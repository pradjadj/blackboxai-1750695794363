<?php
defined("ABSPATH") || exit;

class Duitku_Settings {
    private static $instance = null;
    private $options;
    private $option_key = "duitku_settings";

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialize settings
        $this->init_settings();
        
        // Add WooCommerce settings tab
        add_filter('woocommerce_settings_tabs_array', array($this, 'add_settings_tab'), 50);
        add_action('woocommerce_settings_tabs_duitku', array($this, 'output_settings'));
        add_action('woocommerce_update_options_duitku', array($this, 'save_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
    }

    private function init_settings() {
        $this->options = get_option($this->option_key);
        if (!is_array($this->options)) {
            $this->options = $this->get_defaults();
            update_option($this->option_key, $this->options);
        }
    }

    public function register_settings() {
        register_setting(
            'woocommerce',
            $this->option_key,
            array(
                'type' => 'array',
                'description' => 'Duitku Payment Gateway Settings',
                'sanitize_callback' => array($this, 'sanitize_settings')
            )
        );
    }

    public function sanitize_settings($input) {
        $sanitized = array();
        $fields = $this->get_settings_fields();
        
        foreach ($fields as $field) {
            if (isset($field['id']) && $field['id'] !== 'duitku_settings_section') {
                $key = $field['id'];
                if (isset($input[$key])) {
                    $sanitized[$key] = sanitize_text_field($input[$key]);
                } else {
                    $sanitized[$key] = isset($field['default']) ? $field['default'] : '';
                }
            }
        }
        
        return $sanitized;
    }

    public function enqueue_admin_scripts($hook) {
        if ("woocommerce_page_wc-settings" !== $hook) {
            return;
        }
        wp_enqueue_style("duitku-admin", DUITKU_PLUGIN_URL . "assets/css/duitku-admin.css", array(), DUITKU_PLUGIN_VERSION);
    }

    public function add_settings_tab($tabs) {
        $tabs["duitku"] = __("Duitku Settings", "duitku");
        return $tabs;
    }

    public function output_settings() {
        echo '<h2>' . esc_html__('Duitku Payment Gateway Settings', 'duitku') . '</h2>';
        
        $callback_url = add_query_arg('duitku_callback', '1', site_url('/'));
        echo '<div class="duitku-admin-card">';
        echo '<h3>' . esc_html__('Callback URL', 'duitku') . '</h3>';
        echo '<p>' . esc_html__('Use this URL in your Duitku merchant dashboard:', 'duitku') . '</p>';
        echo '<code>' . esc_url($callback_url) . '</code>';
        echo '</div>';

        echo '<form method="post" action="">';
        echo '<table class="form-table">';
        woocommerce_admin_fields($this->get_settings_fields());
        echo '</table>';
        echo '<p class="submit">';
        echo '<input type="submit" name="save" class="button-primary woocommerce-save-button" value="' . esc_attr__('Save changes', 'woocommerce') . '" />';
        wp_nonce_field('woocommerce-settings');
        echo '</p>';
        echo '</form>';
    }

    private function get_settings_fields() {
        return array(
            array(
                "title" => __("Duitku Settings", "duitku"),
                "type"  => "title",
                "desc"  => __("Configure your Duitku payment gateway settings below.", "duitku"),
                "id"    => "duitku_settings_section"
            ),
            array(
                "title" => __("Merchant Code", "duitku"),
                "type" => "text",
                "desc" => __("Enter your Duitku Merchant Code", "duitku"),
                "id"   => "merchant_code",
                "default" => "",
                "desc_tip" => true
            ),
            array(
                "title" => __("API Key", "duitku"),
                "type" => "password",
                "desc" => __("Enter your Duitku API Key", "duitku"),
                "id"   => "api_key",
                "default" => "",
                "desc_tip" => true
            ),
            array(
                "title" => __("Environment", "duitku"),
                "type" => "select",
                "desc" => __("Select environment mode", "duitku"),
                "id"   => "environment",
                "default" => "development",
                "options" => array(
                    "development" => __("Development", "duitku"),
                    "production" => __("Production", "duitku")
                ),
                "desc_tip" => true
            ),
            array(
                "title" => __("Global Expiry Period", "duitku"),
                "type" => "number",
                "desc" => __("Enter expiry period in minutes", "duitku"),
                "id"   => "expiry_period",
                "default" => "60",
                "desc_tip" => true,
                "custom_attributes" => array(
                    "min" => "1",
                    "step" => "1"
                )
            ),
            array(
                "title" => __("Enable Logging", "duitku"),
                "type" => "checkbox",
                "desc" => __("Enable logging for debugging purposes", "duitku"),
                "id"   => "enable_logging",
                "default" => "yes"
            ),
            array(
                "type" => "sectionend",
                "id" => "duitku_settings_section"
            )
        );
    }

    public function save_settings() {
        woocommerce_update_options($this->get_settings_fields());
        
        // Refresh the options after saving
        $this->options = get_option($this->option_key, $this->get_defaults());
        
        // Add success message
        WC_Admin_Settings::add_message(__('Duitku settings saved successfully.', 'duitku'));
    }

    private function get_defaults() {
        return array(
            "merchant_code" => "",
            "api_key" => "",
            "environment" => "development",
            "expiry_period" => "60",
            "enable_logging" => "yes"
        );
    }
}

add_action("plugins_loaded", array("Duitku_Settings", "get_instance"));
