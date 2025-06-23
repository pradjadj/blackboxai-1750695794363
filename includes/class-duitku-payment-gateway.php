<?php
defined('ABSPATH') || exit;

class Duitku_Payment_Gateway extends WC_Payment_Gateway {
    public $settings;
    protected $logger;

    public function __construct() {
        $this->id = 'duitku';
        $this->method_title = 'Duitku Payment Gateway';
        $this->method_description = 'Duitku Payment Gateway for WooCommerce - Display directly on checkout page';
        $this->has_fields = false;
        $this->supports = array('products');

        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title = $this->get_option('title', 'Duitku Payment');
        $this->description = $this->get_option('description', 'Pay with Duitku');
        
        // Get settings from Duitku_Settings
        $this->settings = get_option('duitku_settings');
        if (!is_array($this->settings)) {
            $this->settings = array(
                'merchant_code' => '',
                'api_key' => '',
                'environment' => 'development',
                'expiry_period' => '60',
                'enable_logging' => 'yes'
            );
        }
        
        // Initialize logger
        $this->logger = new Duitku_Logger();

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_duitku_callback', array($this, 'handle_callback'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'duitku'),
                'type' => 'checkbox',
                'label' => __('Enable Duitku Payment', 'duitku'),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Title', 'duitku'),
                'type' => 'text',
                'description' => __('Payment method title that the customer will see on your checkout.', 'duitku'),
                'default' => __('Duitku Payment', 'duitku'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'duitku'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'duitku'),
                'default' => __('Pay with Duitku', 'duitku'),
                'desc_tip' => true,
            )
        );
    }

    public function payment_scripts() {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_script(
            'duitku-ajax',
            DUITKU_PLUGIN_URL . 'assets/js/duitku-ajax.js',
            array('jquery'),
            DUITKU_PLUGIN_VERSION,
            true
        );

        wp_localize_script('duitku-ajax', 'duitkuAjax', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('duitku-ajax-nonce'),
            'checkInterval' => 3000 // 3 seconds
        ));
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        try {
            // Prepare transaction data
            $merchantCode = $this->settings['merchant_code'];
            $merchantOrderId = 'TRX-' . $order_id;
            $paymentAmount = $order->get_total();
            $apiKey = $this->settings['api_key'];
            
            // Generate signature according to Duitku documentation
            $signature = md5($merchantCode . $merchantOrderId . intval($paymentAmount) . $apiKey);
            
            // Prepare API request data according to Duitku documentation
            $data = array(
                'merchantCode' => $merchantCode,
                'paymentAmount' => intval($paymentAmount),
                'merchantOrderId' => $merchantOrderId,
                'productDetails' => $this->get_product_details($order),
                'customerVaName' => get_bloginfo('name'),
                'email' => $order->get_billing_email(),
                'phoneNumber' => $order->get_billing_phone(),
                'additionalParam' => '',
                'merchantUserInfo' => '',
                'customerDetail' => array(
                    'firstName' => $order->get_billing_first_name(),
                    'lastName' => $order->get_billing_last_name(),
                    'email' => $order->get_billing_email(),
                    'phoneNumber' => $order->get_billing_phone(),
                    'billingAddress' => array(
                        'firstName' => $order->get_billing_first_name(),
                        'lastName' => $order->get_billing_last_name(),
                        'address' => $order->get_billing_address_1(),
                        'city' => $order->get_billing_city(),
                        'postalCode' => $order->get_billing_postcode(),
                        'phone' => $order->get_billing_phone(),
                        'countryCode' => $order->get_billing_country()
                    ),
                    'shippingAddress' => array(
                        'firstName' => $order->get_shipping_first_name(),
                        'lastName' => $order->get_shipping_last_name(),
                        'address' => $order->get_shipping_address_1(),
                        'city' => $order->get_shipping_city(),
                        'postalCode' => $order->get_shipping_postcode(),
                        'phone' => $order->get_billing_phone(),
                        'countryCode' => $order->get_shipping_country()
                    )
                ),
                'returnUrl' => $this->get_return_url($order),
                'callbackUrl' => add_query_arg('duitku_callback', '1', site_url('/')),
                'signature' => $signature,
                'expiryPeriod' => intval($this->settings['expiry_period'])
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

            if (!$body) {
                throw new Exception(__('Empty response from Duitku API', 'duitku'));
            }

            if (!isset($body['statusCode'])) {
                throw new Exception(__('Invalid response format from Duitku API', 'duitku'));
            }

            if ($body['statusCode'] !== '00') {
                $error_message = isset($body['statusMessage']) ? $body['statusMessage'] : __('Unknown error occurred', 'duitku');
                $this->logger->log('Duitku API Error: ' . $error_message);
                throw new Exception($error_message);
            }

            if (!isset($body['paymentUrl'])) {
                throw new Exception(__('Payment URL not received from Duitku API', 'duitku'));
            }

            // Store payment details in order meta using HPOS compatible methods
            $this->update_order_meta($order, '_duitku_reference', $body['reference']);
            $this->update_order_meta($order, '_duitku_payment_url', $body['paymentUrl']);
            
            // Store additional payment details if available
            if (!empty($body['vaNumber'])) {
                $this->update_order_meta($order, '_duitku_va_number', $body['vaNumber']);
            }
            if (!empty($body['qrString'])) {
                $this->update_order_meta($order, '_duitku_qr_string', $body['qrString']);
            }
            $this->update_order_meta($order, '_duitku_expiry', time() + ($this->settings['expiry_period'] * 60));
            
            // Update order status
            $order->update_status('pending', __('Awaiting payment via Duitku', 'duitku'));

            // Return success and redirect to Duitku payment page
            return array(
                'result' => 'success',
                'redirect' => $body['paymentUrl']
            );

        } catch (Exception $e) {
            $this->logger->log('Payment processing failed: ' . $e->getMessage());
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

    public function receipt_page($order_id) {
        $order = wc_get_order($order_id);
        $va_number = $this->get_order_meta($order, '_duitku_va_number');
        $qr_string = $this->get_order_meta($order, '_duitku_qr_string');
        $expiry = $this->get_order_meta($order, '_duitku_expiry');
        
        echo '<div class="duitku-payment-details">';
        
        if ($va_number) {
            echo '<div class="duitku-va-number">';
            echo '<h3>' . esc_html__('Virtual Account Number', 'duitku') . '</h3>';
            echo '<p class="va-number">' . esc_html($va_number) . '</p>';
            echo '</div>';
        }
        
        if ($qr_string) {
            echo '<div class="duitku-qr-code">';
            echo '<h3>' . esc_html__('QRIS Code', 'duitku') . '</h3>';
            echo '<img src="' . esc_url($this->generate_qr_url($qr_string)) . '" alt="QRIS Code" />';
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
        
        echo '</div>';
    }

    protected function generate_qr_url($qr_string) {
        // You might want to use a QR code generation service or library here
        return 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($qr_string);
    }
}
