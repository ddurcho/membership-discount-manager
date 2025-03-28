<?php
namespace MembershipDiscountManager;

/**
 * Handles plugin logging functionality
 */
class Logger {
    /**
     * Log directory path
     *
     * @var string
     */
    private $log_dir;

    /**
     * Whether logging is enabled
     *
     * @var bool
     */
    private $logging_enabled;

    /**
     * Whether debug mode is enabled
     *
     * @var bool
     */
    private $debug_mode;

    /**
     * Constructor
     */
    public function __construct() {
        $this->log_dir = MDM_PLUGIN_DIR . 'logs';
        $this->logging_enabled = get_option('mdm_logging_enabled', true);
        $this->debug_mode = get_option('mdm_debug_mode', false);
        $this->ensure_log_directory();
    }

    /**
     * Ensure log directory exists and is writable
     */
    private function ensure_log_directory() {
        if (!$this->logging_enabled) {
            return;
        }

        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
            chmod($this->log_dir, 0755);
            
            // Create .htaccess to protect logs
            $htaccess_content = "Order deny,allow\nDeny from all";
            file_put_contents($this->log_dir . '/.htaccess', $htaccess_content);
            
            // Create index.php to prevent directory listing
            file_put_contents($this->log_dir . '/index.php', '<?php // Silence is golden');
        }
    }

    /**
     * Log a message
     *
     * @param string $message The message to log
     * @param string $level The log level (debug, info, error)
     * @param array $context Additional context data
     */
    public function log($message, $level = 'debug', $context = array()) {
        if (!$this->logging_enabled || ($level === 'debug' && !$this->debug_mode)) {
            return;
        }

        try {
            $timestamp = current_time('Y-m-d H:i:s');
            $log_file = $this->log_dir . '/mdm-' . current_time('Y-m-d') . '.log';

            // Format context data
            $context_string = empty($context) ? '' : ' ' . json_encode($context);
            
            // Format log entry
            $log_entry = sprintf(
                "[%s] [%s] %s%s\n",
                $timestamp,
                strtoupper($level),
                $message,
                $context_string
            );

            // Write to log file
            error_log($log_entry, 3, $log_file);
        } catch (\Exception $e) {
            error_log('MDM Logger Error: ' . $e->getMessage());
        }
    }

    /**
     * Log debug message
     *
     * @param string $message
     * @param array $context
     */
    public function debug($message, $context = array()) {
        if ($this->debug_mode) {
            $this->log($message, 'debug', $context);
        }
    }

    /**
     * Log info message
     *
     * @param string $message
     * @param array $context
     */
    public function info($message, $context = array()) {
        $this->log($message, 'info', $context);
    }

    /**
     * Log error message
     *
     * @param string $message
     * @param array $context
     */
    public function error($message, $context = array()) {
        $this->log($message, 'error', $context);
    }

    /**
     * Get path to latest log file
     *
     * @return string|false
     */
    public function get_latest_log_file() {
        if (!$this->logging_enabled || !file_exists($this->log_dir)) {
            return false;
        }

        $files = glob($this->log_dir . '/mdm-*.log');
        if (empty($files)) {
            return false;
        }

        return end($files);
    }

    /**
     * Clear all log files
     */
    public function clear_logs() {
        if (!$this->logging_enabled || !file_exists($this->log_dir)) {
            return;
        }

        $files = glob($this->log_dir . '/mdm-*.log');
        foreach ($files as $file) {
            unlink($file);
        }
    }
} 