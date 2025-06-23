<?php
defined('ABSPATH') || exit;

class Duitku_Ajax_Refresh {
    protected $logger;

    public function __construct() {
        $this->logger = new Duitku_Logger();

        // Register AJAX actions for both logged in and guest users
        add_action('wp_ajax_duitku_check_payment', array($this, 'check_payment_status'));
        add_action('wp_ajax_nopriv_duitku_check_payment', array($this, 'check_payment_status'));
    }

    public function check_payment_status() {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'duitku-ajax-nonce')) {
                throw new Exception('Invalid security token');
            }

            // Get order ID
            if (!isset($_POST['order_id'])) {
                throw new Exception('Order ID is required');
            }

            $order_id = absint($_POST['order_id']);
            $order = wc_get_order($order_id);

            if (!$order) {
                throw new Exception('Order not found');
            }

            // Check if this is a Duitku order
            if ($order->get_payment_method() !== 'duitku') {
                throw new Exception('Invalid payment method');
            }

            // Check payment status
            $status = $this->get_payment_status($order);

            // Return response
            wp_send_json_success(array(
                'status' => $status['status'],
                'message' => $status['message'],
                'redirect_url' => $status['redirect_url']
            ));

        } catch (Exception $e) {
            $this->logger->log('AJAX refresh error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
    }

    protected function get_payment_status($order) {
        $status = array(
            'status' => 'pending',
            'message' => __('Waiting for payment...', 'duitku'),
            'redirect_url' => ''
        );

        // Check if payment is completed
        if ($order->is_paid()) {
            $status['status'] = 'completed';
            $status['message'] = __('Payment completed!', 'duitku');
            $status['redirect_url'] = $order->get_checkout_order_received_url();
            return $status;
        }

        // Check if order is cancelled
        if ($order->get_status() === 'cancelled') {
            $status['status'] = 'cancelled';
            $status['message'] = __('Payment cancelled or expired', 'duitku');
            $status['redirect_url'] = wc_get_checkout_url();
            return $status;
        }

        // Check expiry
        $expiry = $order->get_meta('_duitku_expiry');
        if ($expiry && time() > $expiry) {
            // Cancel the order
            $order->update_status(
                'cancelled',
                __('Order cancelled due to payment expiration.', 'duitku')
            );

            $status['status'] = 'expired';
            $status['message'] = __('Payment period has expired', 'duitku');
            $status['redirect_url'] = wc_get_checkout_url();
            return $status;
        }

        // Still pending
        $va_number = $order->get_meta('_duitku_va_number');
        if ($va_number) {
            $status['message'] = sprintf(
                __('Waiting for payment to VA: %s', 'duitku'),
                $va_number
            );
        }

        return $status;
    }
}

// Initialize the AJAX handler
new Duitku_Ajax_Refresh();
