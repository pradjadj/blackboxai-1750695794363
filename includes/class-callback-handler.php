<?php
defined('ABSPATH') || exit;

class Duitku_Callback_Handler {
    protected $logger;
    protected $settings;

    public function __construct() {
        $this->logger = new Duitku_Logger();
        $this->settings = get_option('duitku_settings');

        add_action('init', array($this, 'handle_callback'));
        add_action('woocommerce_order_status_pending_to_cancelled', array($this, 'handle_expired_orders'));
    }

    protected function update_order_meta($order, $meta_key, $meta_value) {
        if (method_exists($order, 'update_meta_data')) {
            // WooCommerce 7.0+ and HPOS compatible
            $order->update_meta_data($meta_key, $meta_value);
            $order->save();
        } else {
            // Fallback for older WooCommerce versions
            update_post_meta($order->get_id(), $meta_key, $meta_value);
        }
    }

    protected function get_order_meta($order, $meta_key, $single = true) {
        if (method_exists($order, 'get_meta')) {
            // WooCommerce 7.0+ and HPOS compatible
            return $order->get_meta($meta_key, $single);
        } else {
            // Fallback for older WooCommerce versions
            return get_post_meta($order->get_id(), $meta_key, $single);
        }
    }

    public function handle_callback() {
        if (!isset($_GET['duitku_callback'])) {
            return;
        }

        // Get callback data
        $raw_post = file_get_contents('php://input');
        $callback_data = json_decode($raw_post, true);

        if (!$callback_data) {
            $this->logger->log('Invalid callback data received');
            wp_die('Invalid callback data', 'Duitku Callback', array('response' => 400));
        }

        try {
            $this->validate_callback_data($callback_data);
            $order = $this->get_order_from_callback($callback_data);
            $this->process_callback($order, $callback_data);
            
            echo 'OK'; // Response expected by Duitku
            exit;

        } catch (Exception $e) {
            $this->logger->log('Callback processing failed: ' . $e->getMessage());
            wp_die($e->getMessage(), 'Duitku Callback', array('response' => 400));
        }
    }

    protected function validate_callback_data($data) {
        $required_fields = array(
            'merchantCode',
            'amount',
            'merchantOrderId',
            'productDetail',
            'additionalParam',
            'paymentCode',
            'resultCode',
            'merchantUserId',
            'reference',
            'signature'
        );

        foreach ($required_fields as $field) {
            if (!isset($data[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }

        // Validate merchant code
        if ($data['merchantCode'] !== $this->settings['merchant_code']) {
            throw new Exception('Invalid merchant code');
        }

        // Validate signature
        $expected_signature = md5(
            $data['merchantCode'] . 
            $data['amount'] . 
            $data['merchantOrderId'] . 
            $this->settings['api_key']
        );

        if ($data['signature'] !== $expected_signature) {
            throw new Exception('Invalid signature');
        }
    }

    protected function get_order_from_callback($data) {
        // Extract order ID from merchantOrderId (format: DPAY-order_id)
        $order_id = str_replace('DPAY-', '', $data['merchantOrderId']);
        
        $order = wc_get_order($order_id);
        if (!$order) {
            throw new Exception("Order not found: {$order_id}");
        }

        return $order;
    }

    protected function process_callback($order, $data) {
        // Store callback data in order meta using HPOS compatible method
        $this->update_order_meta($order, '_duitku_callback_data', $data);
        
        // Process based on result code
        switch ($data['resultCode']) {
            case '00': // Success
                if ($order->get_status() === 'pending') {
                    // Update order status
                    $order->payment_complete($data['reference']);
                    $order->add_order_note(
                        sprintf(
                            __('Payment completed via Duitku. Reference: %s', 'duitku'),
                            $data['reference']
                        )
                    );

                    // Store settlement date if provided using HPOS compatible method
                    if (isset($data['settlementDate'])) {
                        $this->update_order_meta($order, '_duitku_settlement_date', $data['settlementDate']);
                    }
                }
                break;

            case '01': // Pending
                // Do nothing, order stays in pending status
                break;

            case '02': // Cancelled/Failed
                if ($order->get_status() === 'pending') {
                    $order->update_status(
                        'cancelled',
                        sprintf(
                            __('Payment cancelled or failed. Reference: %s', 'duitku'),
                            $data['reference']
                        )
                    );
                }
                break;

            default:
                throw new Exception("Unknown result code: {$data['resultCode']}");
        }

        $order->save();
    }

    public function handle_expired_orders($order_id) {
        $order = wc_get_order($order_id);
        
        // Check if this is a Duitku order
        if ($order->get_payment_method() !== 'duitku') {
            return;
        }

        // Check expiry using HPOS compatible method
        $expiry = $this->get_order_meta($order, '_duitku_expiry');
        if ($expiry && time() > $expiry) {
            $order->update_status(
                'cancelled',
                __('Order cancelled due to payment expiration.', 'duitku')
            );
            $order->save(); // Ensure changes are saved in HPOS
        }
    }
}

// Initialization is handled in the main plugin file
