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
        $this->options = get_option($this->option_key, $this->get_defaults());
        
        add_filter("woocommerce_settings_tabs_array", array($this, "add_settings_tab"), 50);
        add_action("woocommerce_settings_tabs_duitku", array($this, "output_settings"));
        add_action("woocommerce_update_options_duitku", array($this, "save_settings"));
        add_action("admin_enqueue_scripts", array($this, "enqueue_admin_scripts"));
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
        $callback_url = add_query_arg("duitku_callback", "1", site_url("/"));
        ?>
        <div class="duitku-admin-card">
            <h2><?php _e("Callback URL", "duitku"); ?></h2>
            <p><?php _e("Use this URL in your Duitku merchant dashboard:", "duitku"); ?></p>
            <code><?php echo esc_url($callback_url); ?></code>
        </div>
        <?php
        woocommerce_admin_fields($this->get_settings_fields());
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
        $fields = $this->get_settings_fields();
        $settings = array();
        
        foreach ($fields as $field) {
            if (isset($field["id"]) && $field["id"] !== "duitku_settings_section") {
                $key = $field["id"];
                $value = isset($_POST[$key]) ? $_POST[$key] : (isset($field["default"]) ? $field["default"] : "");
                $settings[$key] = $value;
            }
        }
        
        update_option($this->option_key, $settings);
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
