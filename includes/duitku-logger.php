<?php
defined('ABSPATH') || exit;

class Duitku_Logger {
    protected $settings;
    protected $logger;

    public function __construct() {
        $this->settings = get_option('duitku_settings');
        
        if (function_exists('wc_get_logger')) {
            $this->logger = wc_get_logger();
        }
    }

    public function log($message, $level = 'error') {
        // Check if logging is enabled in settings
        if (!isset($this->settings['enable_logging']) || $this->settings['enable_logging'] !== 'yes') {
            return;
        }

        if ($this->logger) {
            $context = array('source' => 'duitku-pg');
            $this->logger->log($level, $message, $context);
        } else {
            error_log('Duitku VA: ' . $message);
        }
    }

    public function clear_logs() {
        if (!$this->logger) {
            return;
        }

        // Get WC_Log_Handler_File instance
        $handler = new WC_Log_Handler_File();
        
        // Get log files
        $files = $handler->get_log_files();
        
        // Find and remove Duitku log files
        foreach ($files as $file) {
            if (strpos($file, 'duitku-pg') !== false) {
                @unlink(WC_LOG_DIR . $file);
            }
        }
    }

    public function get_logs() {
        if (!$this->logger) {
            return array();
        }

        $handler = new WC_Log_Handler_File();
        $files = $handler->get_log_files();
        $logs = array();

        foreach ($files as $file) {
            if (strpos($file, 'duitku-pg') !== false) {
                $handle = @fopen(WC_LOG_DIR . $file, 'r');
                if ($handle) {
                    while (($line = fgets($handle)) !== false) {
                        $logs[] = $line;
                    }
                    fclose($handle);
                }
            }
        }

        return $logs;
    }

    public function format_log_entry($message, $data = array()) {
        $entry = array(
            'timestamp' => current_time('mysql'),
            'message' => $message
        );

        if (!empty($data)) {
            $entry['data'] = json_encode($data);
        }

        return sprintf(
            '[%s] %s %s',
            $entry['timestamp'],
            $entry['message'],
            isset($entry['data']) ? $entry['data'] : ''
        );
    }
}
