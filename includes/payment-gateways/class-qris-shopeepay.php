<?php
defined('ABSPATH') || exit;

class Duitku_ShopeePay extends Duitku_Payment_Gateway {
    public function __construct() {
        parent::__construct();
        
        $this->id = 'duitku_shopeepay';
        $this->method_title = 'Duitku - ShopeePay QRIS';
        $this->method_description = 'Pembayaran melalui ShopeePay QRIS';
        $this->payment_code = 'SP'; // ShopeePay QRIS payment code

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title = $this->get_option('title', 'ShopeePay QRIS');
        $this->description = $this->get_option('description');
        $this->instructions = $this->get_option('instructions');
        $this->expiry_time = $this->get_option('expiry_time', '24');
        $this->fee_type = $this->get_option('fee_type', 'nominal');
        $this->fee_value = $this->get_option('fee_value', '0');

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'duitku'),
                'type' => 'checkbox',
                'label' => __('Enable ShopeePay QRIS Payment', 'duitku'),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Title', 'duitku'),
                'type' => 'text',
                'description' => __('Payment method title that the customer will see on your checkout.', 'duitku'),
                'default' => __('ShopeePay QRIS', 'duitku'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'duitku'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'duitku'),
                'default' => __('Pay using ShopeePay QRIS. Scan the QR code using your ShopeePay app.', 'duitku'),
                'desc_tip' => true,
            ),
            'instructions' => array(
                'title' => __('Payment Instructions', 'duitku'),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page and emails.', 'duitku'),
                'default' => __(
                    "1. Open your ShopeePay app\n" .
                    "2. Select Pay by QRIS\n" .
                    "3. Scan the QR code shown\n" .
                    "4. Check the payment details\n" .
                    "5. Enter your PIN to confirm payment\n" .
                    "6. Your payment is complete",
                    'duitku'
                ),
                'desc_tip' => true,
            ),
            'expiry_time' => array(
                'title' => __('Expiry Time', 'duitku'),
                'type' => 'number',
                'description' => __('Time in hours before the payment expires. Default is 24 hours.', 'duitku'),
                'default' => '24',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => '1',
                    'max' => '48',
                    'step' => '1'
                )
            ),
            'fee_type' => array(
                'title' => __('Fee Type', 'duitku'),
                'type' => 'select',
                'description' => __('Choose how the payment fee will be calculated.', 'duitku'),
                'default' => 'nominal',
                'options' => array(
                    'nominal' => __('Nominal (Fixed Amount)', 'duitku'),
                    'percent' => __('Percentage (%)', 'duitku')
                ),
                'desc_tip' => true,
            ),
            'fee_value' => array(
                'title' => __('Fee Value', 'duitku'),
                'type' => 'text',
                'description' => __('Enter the fee value. For percentage, enter number without % symbol (e.g., 2.5). For nominal, enter the amount.', 'duitku'),
                'default' => '0',
                'desc_tip' => true,
            )
        );
    }

    public function calculate_fee($order_total) {
        if ($this->fee_type === 'percent') {
            return ($order_total * floatval($this->fee_value)) / 100;
        }
        return floatval($this->fee_value);
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        try {
            // Add payment fee if set
            $fee = $this->calculate_fee($order->get_total());
            if ($fee > 0) {
                $fee_name = sprintf(__('Payment Fee (%s)', 'duitku'), $this->title);
                $order->add_fee($fee_name, $fee, true);
                $order->calculate_totals();
            }

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
                'customerVaName' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'returnUrl' => $this->get_return_url($order),
                'callbackUrl' => add_query_arg('duitku_callback', '1', site_url('/')),
                'signature' => $signature,
                'paymentMethod' => $this->payment_code,
                'expiryPeriod' => intval($this->expiry_time) * 60 // Convert hours to minutes
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
            $this->update_order_meta($order, '_duitku_reference', $body['reference']);
            $this->update_order_meta($order, '_duitku_qr_string', $body['qrString']);
            $this->update_order_meta($order, '_duitku_payment_method', $this->payment_code);
            $this->update_order_meta($order, '_duitku_expiry', time() + (intval($this->expiry_time) * 3600));
            
            // Update order status
            $order->update_status('pending', __('Awaiting ShopeePay QRIS payment', 'duitku'));

            // Empty cart
            WC()->cart->empty_cart();

            // Return success
            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            );

        } catch (Exception $e) {
            $this->logger->log('ShopeePay QRIS Payment processing failed: ' . $e->getMessage());
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
        $qr_string = $this->get_order_meta($order, '_duitku_qr_string');
        $expiry = $this->get_order_meta($order, '_duitku_expiry');
        
        echo '<div class="duitku-payment-details">';
        
        // Order Details
        echo '<div class="duitku-order-details">';
        echo '<h2>' . esc_html__('Order Details', 'duitku') . '</h2>';
        echo '<p><strong>' . esc_html__('Order Number:', 'duitku') . '</strong> ' . esc_html($order->get_order_number()) . '</p>';
        echo '<p><strong>' . esc_html__('Total Amount:', 'duitku') . '</strong> ' . wp_kses_post($order->get_formatted_order_total()) . '</p>';
        echo '</div>';
        
        // QR Code
        if ($qr_string) {
            echo '<div class="duitku-qr-code">';
            echo '<h2>' . esc_html__('Scan QR Code', 'duitku') . '</h2>';
            echo '<div class="qr-code-container">';
            echo '<img src="' . esc_url($qr_string) . '" alt="QRIS QR Code">';
            echo '</div>';
            echo '</div>';
        }
        
        // Payment Deadline
        if ($expiry) {
            echo '<div class="duitku-expiry">';
            echo '<h2>' . esc_html__('Payment Deadline', 'duitku') . '</h2>';
            echo '<p class="countdown" data-expiry="' . esc_attr($expiry) . '">' . esc_html(date('Y-m-d H:i:s', $expiry)) . ' WIB</p>';
            echo '</div>';
        }
        
        // Payment Instructions
        if ($this->instructions) {
            echo '<div class="duitku-instructions">';
            echo '<h2>' . esc_html__('Payment Instructions', 'duitku') . '</h2>';
            echo '<div class="instruction-steps">';
            echo wp_kses_post(nl2br($this->instructions));
            echo '</div>';
            echo '</div>';
        }
        
        // Payment Status
        echo '<div class="duitku-status">';
        echo '<h2>' . esc_html__('Payment Status', 'duitku') . '</h2>';
        echo '<p class="status-message">' . esc_html__('Waiting for your payment...', 'duitku') . '</p>';
        echo '<div class="duitku-spinner"></div>';
        echo '</div>';
        
        // Add order ID for AJAX
        echo '<input type="hidden" name="order_id" value="' . esc_attr($order_id) . '">';
        
        echo '</div>';
    }
}
