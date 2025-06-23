<?php
/**
 * Plugin Name: Duitku Payment Gateway
 * Plugin URI: https://sgnet.co.id
 * Description: Duitku Payment Gateway for WooCommerce - Display directly on checkout page
 * Version: 1.0
 * Author: Pradja DJ
 * Author URI: https://sgnet.co.id
 * Requires PHP: 8.0
 * WC requires at least: 9.8
 * WC tested up to: 9.8
 */

defined('ABSPATH') || exit;

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Check PHP version
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>Duitku Payment Gateway requires PHP version 8.0 or higher.</p></div>';
    });
    return;
}

// Define plugin constants
define('DUITKU_PLUGIN_VERSION', '1.0');
define('DUITKU_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DUITKU_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'Duitku\\';
    $base_dir = DUITKU_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Initialize the plugin
function duitku_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    
    // Include core files
    require_once DUITKU_PLUGIN_DIR . 'includes/duitku-logger.php'; // Load logger first
    require_once DUITKU_PLUGIN_DIR . 'includes/class-duitku-settings.php';
    require_once DUITKU_PLUGIN_DIR . 'includes/class-duitku-payment-gateway.php';
    require_once DUITKU_PLUGIN_DIR . 'includes/class-callback-handler.php';
    require_once DUITKU_PLUGIN_DIR . 'includes/class-ajax-refresh.php';
    
    // Load payment gateway classes
    foreach (glob(DUITKU_PLUGIN_DIR . 'includes/payment-gateways/class-*.php') as $filename) {
        require_once $filename;
    }

    // Initialize callback handler
    new Duitku_Callback_Handler();
}
add_action('plugins_loaded', 'duitku_init');

// Add settings link on plugin page
function duitku_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=duitku_settings') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'duitku_add_settings_link');

// Register activation hook
register_activation_hook(__FILE__, 'duitku_activate');
function duitku_activate() {
    // Create necessary database tables or options if needed
    add_option('duitku_settings', array(
        'merchant_code' => '',
        'api_key' => '',
        'environment' => 'development',
        'expiry_period' => '60',
        'enable_logging' => 'yes'
    ));
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'duitku_deactivate');
function duitku_deactivate() {
    // Cleanup if necessary
}
