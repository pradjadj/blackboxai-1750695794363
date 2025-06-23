<?php
defined('ABSPATH') || exit;

class Duitku_Permata extends Duitku_Payment_Gateway {
    public function __construct() {
        parent::__construct();
        
        $this->id = 'duitku_permata';
        $this->method_title = 'Duitku - Permata Virtual Account';
        $this->method_description = 'Pembayaran melalui Virtual Account Permata';
        $this->payment_code = 'BT'; // Permata payment code

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title = $this->get_option('title', 'Permata Virtual Account');
        $this->description = $this->get_option('description');
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
                'label' => __('Enable Permata Virtual Account Payment', 'duitku'),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Title', 'duitku'),
                'type' => 'text',
                'description' => __('Payment method title that the customer will see on your checkout.', 'duitku'),
                'default' => __('Permata Virtual Account', 'duitku'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'duitku'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'duitku'),
                'default' => __('Pay using Permata Virtual Account. The payment will expire after the specified time limit.', 'duitku'),
                'desc_tip' => true,
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

            // Get merchant settings from parent
            $merchantCode = $this->get_option('merchant_code');
            $apiKey = $this->get_option('api_key');
            $environment = $this->get_option('environment');
            
            if (!$merchantCode || !$apiKey) {
                throw new Exception(__('Please configure merchant code and API key in Duitku settings', 'duitku'));
            }

            $merchantOrderId = 'DPAY-' . $order_id;
            $paymentAmount = $order->get_total();
            
            // Generate signature
            $signature = md5($merchantCode . $merchantOrderId . $paymentAmount . $apiKey);
            
            // Get expiry period from global settings if available
            $expiryPeriod = $this->get_option('expiry_period');
            if (empty($expiryPeriod)) {
                // Default to 1440 minutes (24 hours) if not set
                $expiryPeriod = 1440;
            }
            
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
                'expiryPeriod' => intval($expiryPeriod)
            );

            // Get API endpoint based on environment
            $endpoint = $environment === 'production' 
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

            if (!isset($body['reference']) || !isset($body['vaNumber'])) {
                throw new Exception(__('Invalid response from Duitku: Missing required fields', 'duitku'));
            }

            // Store payment details in order meta
            $this->update_order_meta($order, '_duitku_reference', $body['reference']);
            $this->update_order_meta($order, '_duitku_va_number', $body['vaNumber']);
            $this->update_order_meta($order, '_duitku_payment_code', $this->payment_code);
            $this->update_order_meta($order, '_duitku_expiry', time() + (intval($expiryPeriod) * 60));
            
            // Update order status
            $order->update_status('pending', __('Awaiting Permata Virtual Account payment', 'duitku'));

            // Empty cart
            WC()->cart->empty_cart();

            // Return success
            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            );

        } catch (Exception $e) {
            $this->logger->log('Permata VA Payment processing failed: ' . $e->getMessage());
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
        $va_number = $this->get_order_meta($order, '_duitku_va_number');
        $expiry = $this->get_order_meta($order, '_duitku_expiry');
        
        echo '<div class="duitku-payment-details">';
        
        // Payment Instructions
        echo '<div class="duitku-instructions">';
        echo '<p>' . esc_html__('Silakan transfer ke Virtual Account Permata berikut:', 'duitku') . '</p>';
        if ($va_number) {
            echo '<div class="va-number-box">';
            echo '<span class="va-number">' . esc_html($va_number) . '</span>';
            echo '<button class="copy-button" onclick="copyToClipboard(\'' . esc_js($va_number) . '\')">' . esc_html__('Copy', 'duitku') . '</button>';
            echo '</div>';
        }
        
        echo '<p>' . esc_html__('Jumlah yang harus dibayar:', 'duitku') . ' ' . wp_kses_post($order->get_formatted_order_total()) . '</p>';
        
        if ($expiry) {
            echo '<p>' . esc_html__('Batas waktu pembayaran:', 'duitku') . ' ' . esc_html(date('Y-m-d H:i:s', $expiry)) . ' WIB</p>';
        }
        echo '</div>';
        
        // Payment Status
        echo '<div class="duitku-status">';
        echo '<div class="duitku-spinner"></div>';
        echo '<p class="status-message">' . esc_html__('Menunggu pembayaran...', 'duitku') . '</p>';
        echo '</div>';
        
        // Add order ID for AJAX
        echo '<input type="hidden" name="order_id" value="' . esc_attr($order_id) . '">';
        
        // Add copy to clipboard script
        ?>
        <script type="text/javascript">
        function copyToClipboard(text) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            
            var button = document.querySelector('.copy-button');
            button.textContent = '<?php echo esc_js(__('Copied!', 'duitku')); ?>';
            setTimeout(function() {
                button.textContent = '<?php echo esc_js(__('Copy', 'duitku')); ?>';
            }, 2000);
        }
        </script>
        <?php
        
        echo '</div>';
    }
}
