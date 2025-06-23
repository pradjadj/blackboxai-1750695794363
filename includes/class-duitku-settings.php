<?php
defined('ABSPATH') || exit;

class Duitku_Settings {
    private static $instance = null;
    private $options;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->options = get_option('duitku_settings');
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function enqueue_admin_scripts($hook) {
        if ('woocommerce_page_duitku-settings' !== $hook) {
            return;
        }
        wp_enqueue_style('duitku-admin', DUITKU_PLUGIN_URL . 'assets/css/duitku-admin.css', array(), DUITKU_PLUGIN_VERSION);
    }

    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            'Duitku Settings',
            'Duitku Settings',
            'manage_woocommerce',
            'duitku-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('duitku_settings', 'duitku_settings', array($this, 'sanitize_settings'));

        add_settings_section(
            'duitku_main_settings',
            'Main Settings',
            array($this, 'section_callback'),
            'duitku-settings'
        );

        $this->add_settings_fields();
    }

    public function section_callback() {
        echo '<p>Configure your Duitku payment gateway settings below.</p>';
    }

    public function add_settings_fields() {
        $fields = array(
            'merchant_code' => array(
                'title' => 'Merchant Code',
                'type' => 'text',
                'description' => 'Enter your Duitku Merchant Code'
            ),
            'api_key' => array(
                'title' => 'API Key',
                'type' => 'password',
                'description' => 'Enter your Duitku API Key'
            ),
            'environment' => array(
                'title' => 'Environment',
                'type' => 'select',
                'options' => array(
                    'development' => 'Development',
                    'production' => 'Production'
                ),
                'description' => 'Select environment mode'
            ),
            'expiry_period' => array(
                'title' => 'Global Expiry Period',
                'type' => 'number',
                'description' => 'Enter expiry period in minutes',
                'default' => '60'
            ),
            'enable_logging' => array(
                'title' => 'Enable Logging',
                'type' => 'checkbox',
                'description' => 'Enable logging for debugging purposes'
            )
        );

        foreach ($fields as $field_id => $field) {
            add_settings_field(
                'duitku_' . $field_id,
                $field['title'],
                array($this, 'render_field'),
                'duitku-settings',
                'duitku_main_settings',
                array(
                    'id' => $field_id,
                    'type' => $field['type'],
                    'description' => $field['description'],
                    'options' => isset($field['options']) ? $field['options'] : null,
                    'default' => isset($field['default']) ? $field['default'] : ''
                )
            );
        }
    }

    public function render_field($args) {
        $id = $args['id'];
        $type = $args['type'];
        $value = isset($this->options[$id]) ? $this->options[$id] : $args['default'];
        
        switch ($type) {
            case 'text':
            case 'password':
            case 'number':
                printf(
                    '<input type="%s" id="duitku_%s" name="duitku_settings[%s]" value="%s" class="regular-text" />',
                    esc_attr($type),
                    esc_attr($id),
                    esc_attr($id),
                    esc_attr($value)
                );
                break;
                
            case 'select':
                echo '<select id="duitku_' . esc_attr($id) . '" name="duitku_settings[' . esc_attr($id) . ']">';
                foreach ($args['options'] as $key => $label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($key),
                        selected($value, $key, false),
                        esc_html($label)
                    );
                }
                echo '</select>';
                break;
                
            case 'checkbox':
                printf(
                    '<input type="checkbox" id="duitku_%s" name="duitku_settings[%s]" value="1" %s />',
                    esc_attr($id),
                    esc_attr($id),
                    checked($value, 1, false)
                );
                break;
        }
        
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        $callback_url = add_query_arg('duitku_callback', '1', site_url('/'));
        ?>
        <div class="wrap">
            <h1>Duitku Settings</h1>
            
            <div class="duitku-admin-card">
                <h2>Callback URL</h2>
                <p>Use this URL in your Duitku merchant dashboard:</p>
                <code><?php echo esc_url($callback_url); ?></code>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields('duitku_settings');
                do_settings_sections('duitku-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['merchant_code'])) {
            $sanitized['merchant_code'] = sanitize_text_field($input['merchant_code']);
        }
        
        if (isset($input['api_key'])) {
            $sanitized['api_key'] = sanitize_text_field($input['api_key']);
        }
        
        if (isset($input['environment'])) {
            $sanitized['environment'] = sanitize_text_field($input['environment']);
        }
        
        if (isset($input['expiry_period'])) {
            $sanitized['expiry_period'] = absint($input['expiry_period']);
        }
        
        if (isset($input['enable_logging'])) {
            $sanitized['enable_logging'] = (bool) $input['enable_logging'];
        }
        
        return $sanitized;
    }
}

// Initialize the settings
add_action('plugins_loaded', array('Duitku_Settings', 'get_instance'));
