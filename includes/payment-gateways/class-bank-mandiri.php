<?php
defined('ABSPATH') || exit;

class Duitku_Mandiri extends WC_Payment_Gateway {
    protected $logger;
    protected $settings;

    public function __construct() {
        $this->id = 'duitku_mandiri';
        $this->method_title = 'Duitku - Mandiri Virtual Account';
        $this->method_description = 'Pembayaran melalui Virtual Account Mandiri';
        $this->has_fields = false;
        $this->payment_code = 'M2'; // Mandiri payment code

        // Load settings
        $this->settings = get_option('duitku_settings');
        $this->enabled = 'yes';
        $this->title = 'Mandiri Virtual Account';
        $this->description = sprintf(
            'Pembayaran menggunakan Virtual Account Mandiri. Expired dalam %d menit.',
            $this->settings['expiry_period'] ?? 60
        );

        // Initialize logger
        $this->logger = new Duitku_Logger();

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woocommerce'),
                'type' => 'checkbox',
                'label' => 'Enable Mandiri Virtual Account Payment',
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Title', 'woocommerce'),
                'type' => 'text',
                'description' => __('Payment method title that the customer will see on your checkout.', 'woocommerce'),
                'default' => 'Mandiri Virtual Account',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'woocommerce'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce'),
                'default' => sprintf(
                    'Pembayaran menggunakan Virtual Account Mandiri. Expired dalam %d menit.',
                    $this->settings['expiry_period'] ?? 60
                ),
                'desc_tip' => true,
            )
        );
    }

    public function process_payment($order_id) {
        global $woocommerce;
        $order = wc_get_order($order_id);

        try {
            // Prepare transaction data
            $merchantCode = $this->settings['merchant_code'];
            $merchantOrderId = 'DPAY-' . $order_id;
            $paymentAmount = $order->get_total();
            $apiKey = $this->settings['api_key'];
            
            // Generate signature
            $signature = md5($merchantCode . $merchantOrderId . $paymentAmount . $apiKey);
            
            // Prepare API request data
            $data = array(
                'merchantCode' => $merchantCode,
                'paymentAmount' => $paymentAmount,
                'merchantOrderId' => $merchantOrderId,
                'productDetails' => $this->get_product_details($order),
                'email' => $order->get_billing_email(),
                'phoneNumber' => $order->get_billing_phone(),
                'customerVaName' => get_bloginfo('name'),
                'returnUrl' => $this->get_return_url($order),
                'callbackUrl' => add_query_arg('duitku_callback', '1', site_url('/')),
                'signature' => $signature,
                'paymentMethod' => $this->payment_code,
                'expiryPeriod' => $this->settings['expiry_period'] ?? 60
            );

            // Get API endpoint based on environment
            $endpoint = $this->settings['environment'] === 'production' 
                ? 'https://passport.duitku.com/webapi/api/merchant/v2/inquiry'
                : 'https://sandbox.duitku.com/webapi/api/merchant/v2/inquiry';

            // Make API request
            $response = wp_remote_post($endpoint, array(
                'body' => json_encode($data),
                'headers' => array('Content-Type' => 'application/json'),
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (!$body || isset($body['statusCode']) && $body['statusCode'] !== '00') {
                throw new Exception(isset($body['statusMessage']) ? $body['statusMessage'] : 'Unknown error occurred');
            }

            // Store payment details in order meta
            $order->update_meta_data('_duitku_reference', $body['reference']);
            $order->update_meta_data('_duitku_va_number', $body['vaNumber']);
            $order->update_meta_data('_duitku_payment_code', $this->payment_code);
            $order->update_meta_data('_duitku_expiry', time() + ($this->settings['expiry_period'] * 60));
            
            // Update order status
            $order->update_status('pending', __('Awaiting Mandiri Virtual Account payment', 'duitku'));
            $order->save();

            // Empty cart
            $woocommerce->cart->empty_cart();

            // Return success
            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            );

        } catch (Exception $e) {
            $this->logger->log('Mandiri VA Payment processing failed: ' . $e->getMessage());
            wc_add_notice('Payment error: ' . $e->getMessage(), 'error');
            return array('result' => 'fail');
        }
    }

    protected function get_product_details($order) {
        $items = array();
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name();
        }
        return implode(', ', $items);
    }

    public function receipt_page($order_id) {
        $order = wc_get_order($order_id);
        $va_number = $order->get_meta('_duitku_va_number');
        $expiry = $order->get_meta('_duitku_expiry');
        
        echo '<div class="duitku-payment-details">';
        
        if ($va_number) {
            echo '<div class="duitku-va-number">';
            echo '<h3>' . esc_html__('Mandiri Virtual Account Number', 'duitku') . '</h3>';
            echo '<p class="va-number">' . esc_html($va_number) . '</p>';
            echo '</div>';
        }
        
        if ($expiry) {
            echo '<div class="duitku-expiry">';
            echo '<h3>' . esc_html__('Payment Deadline', 'duitku') . '</h3>';
            echo '<p>' . esc_html(date('Y-m-d H:i:s', $expiry)) . ' WIB</p>';
            echo '</div>';
        }
        
        echo '<div class="duitku-status">';
        echo '<p>' . esc_html__('Waiting for your payment...', 'duitku') . '</p>';
        echo '<div class="duitku-spinner"></div>';
        echo '</div>';
        
        // Add order ID for AJAX
        echo '<input type="hidden" name="order_id" value="' . esc_attr($order_id) . '">';
        
        echo '</div>';
    }
}
