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
        $this->fee_type = $this->get_option('fee_type', 'nominal');
        $this->fee_value = $this->get_option('fee_value', '0');
        $this->expiry_period = $this->get_option('expiry_period', '1440'); // Default 24 hours in minutes

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
            'expiry_period' => array(
                'title' => __('Expiry Period', 'duitku'),
                'type' => 'number',
                'description' => __('Time in minutes before the payment expires. Leave empty to use global setting.', 'duitku'),
                'default' => '',
                'desc_tip' => true,
                'placeholder' => __('Use global setting', 'duitku'),
                'custom_attributes' => array(
                    'min' => '1',
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
                $fee_item = new WC_Order_Item_Fee();
                $fee_item->set_name(sprintf(__('Payment Fee (%s)', 'duitku'), $this->title));
                $fee_item->set_amount($fee);
                $fee_item->set_tax_status('taxable');
                $fee_item->set_total($fee);
                
                // Add fee to order
                $order->add_item($fee_item);
                $order->calculate_totals();
            }

            // Get merchant settings from parent
            $merchantCode = $this->settings['merchant_code'];
            $apiKey = $this->settings['api_key'];
            $environment = $this->settings['environment'];

            $merchantOrderId = 'TRX-' . $order_id;
            $paymentAmount = $order->get_total();
            
            // Generate signature
            $signature = md5($merchantCode . $merchantOrderId . $paymentAmount . $apiKey);
            
            // Get expiry period - use local setting if set, otherwise use global
            $expiryPeriod = $this->get_option('expiry_period');
            if (empty($expiryPeriod)) {
                $expiryPeriod = $this->settings['expiry_period'];
            }
            if (empty($expiryPeriod)) {
                $expiryPeriod = 1440; // Default to 24 hours
            }
            
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

            if (!isset($body['reference']) || !isset($body['qrString'])) {
                throw new Exception(__('Invalid response from Duitku: Missing required fields', 'duitku'));
            }

            // Store payment details in order meta
            $this->update_order_meta($order, '_duitku_reference', $body['reference']);
            $this->update_order_meta($order, '_duitku_qr_string', $body['qrString']);
            $this->update_order_meta($order, '_duitku_payment_code', $this->payment_code);
            $this->update_order_meta($order, '_duitku_expiry', time() + (intval($expiryPeriod) * 60));
            
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
        
        // Add QR Code JS library
        wp_enqueue_script('qrcodejs', 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js', array(), null, true);
        
        echo '<div class="duitku-payment-details">';
        
        // Payment Instructions
        echo '<div class="duitku-instructions">';
        echo '<p>' . esc_html__('Silahkan Scan QRIS berikut ini:', 'duitku') . '</p>';
        
        // QR Code Container
        if ($qr_string) {
            echo '<div class="qr-code-container">';
            echo '<div id="qrcode"></div>';
            echo '</div>';
            
            // Generate QR Code using qrcodejs
            ?>
            <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                new QRCode(document.getElementById("qrcode"), {
                    text: <?php echo json_encode($qr_string); ?>,
                    width: 256,
                    height: 256,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.H
                });
            });
            </script>
            <?php
        }
        
        echo '<p>' . esc_html__('Nominal Pembayaran:', 'duitku') . ' ' . wp_kses_post($order->get_formatted_order_total()) . '</p>';
        
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
        
        echo '</div>';
    }
}
