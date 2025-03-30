<?php
/**
 * Plugin Name: Nestwork Membership Discount Manager
 * Plugin URI: https://nestwork.com
 * Description: Dynamically manages membership discounts based on customer spending history
 * Version: 1.0.0
 * Author: Nestwork
 * Author URI: https://nestwork.com
 * Text Domain: membership-discount-manager
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 *
 * @package MembershipDiscountManager
 */

defined('ABSPATH') || exit;

// Plugin constants
define('MDM_VERSION', '1.0.0');
define('MDM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MDM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MDM_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('MDM_PLUGIN_FILE', __FILE__);

// Load required files for activation/deactivation
require_once MDM_PLUGIN_DIR . 'includes/class-mdm-activator.php';
require_once MDM_PLUGIN_DIR . 'includes/class-mdm-deactivator.php';
require_once MDM_PLUGIN_DIR . 'includes/class-mdm-logger.php';
require_once MDM_PLUGIN_DIR . 'includes/class-mdm-setup.php';
require_once MDM_PLUGIN_DIR . 'includes/class-mdm-admin.php';
require_once MDM_PLUGIN_DIR . 'includes/class-mdm-cron.php';

/**
 * Check if WooCommerce is active
 */
function mdm_is_woocommerce_active() {
    return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
}

/**
 * Check if WooCommerce Memberships is active
 */
function mdm_is_wc_memberships_active() {
    return in_array('woocommerce-memberships/woocommerce-memberships.php', apply_filters('active_plugins', get_option('active_plugins')));
}

/**
 * Admin notice for missing dependencies
 */
function mdm_admin_notice_missing_dependencies() {
    $class = 'notice notice-error';
    $message = '';
    
    if (!mdm_is_woocommerce_active()) {
        $message .= __('WooCommerce must be installed and activated. ', 'membership-discount-manager');
    }
    
    if (!mdm_is_wc_memberships_active()) {
        $message .= __('WooCommerce Memberships must be installed and activated. ', 'membership-discount-manager');
    }
    
    printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
}

/**
 * Plugin activation hook
 */
function mdm_activate() {
    if (!mdm_is_woocommerce_active() || !mdm_is_wc_memberships_active()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('This plugin requires both WooCommerce and WooCommerce Memberships to be installed and activated.', 'membership-discount-manager'),
            'Plugin Activation Error',
            array('back_link' => true)
        );
    }

    // Create logs directory with proper permissions
    $logs_dir = MDM_PLUGIN_DIR . 'logs';
    if (!file_exists($logs_dir)) {
        wp_mkdir_p($logs_dir);
        
        // Set directory permissions
        chmod($logs_dir, 0755);
        
        // Create .htaccess to protect logs
        $htaccess_content = "Order deny,allow\nDeny from all";
        file_put_contents($logs_dir . '/.htaccess', $htaccess_content);
        
        // Create index.php to prevent directory listing
        file_put_contents($logs_dir . '/index.php', '<?php // Silence is golden');
    }

    // Initialize default options
    add_option('mdm_logging_enabled', true);
    add_option('mdm_debug_mode', false);

    MembershipDiscountManager\Activator::activate();
}

/**
 * Plugin deactivation hook
 */
function mdm_deactivate() {
    MembershipDiscountManager\Deactivator::deactivate();
    // Clear scheduled cron jobs
    wp_clear_scheduled_hook('mdm_auto_calculation');
}

/**
 * Initialize plugin
 */
function mdm_init() {
    // Check dependencies
    if (!mdm_is_woocommerce_active() || !mdm_is_wc_memberships_active()) {
        add_action('admin_notices', 'mdm_admin_notice_missing_dependencies');
        return;
    }

    // Initialize classes
    new MembershipDiscountManager\Admin();
    new MembershipDiscountManager\Setup();
    new MembershipDiscountManager\Cron();

    // Load required files
    require_once MDM_PLUGIN_DIR . 'includes/class-mdm-discount-handler.php';

    // Initialize discount handler
    new MembershipDiscountManager\Discount_Handler();

    // Log initialization
    error_log('🎯 MDM: Plugin initialized with Discount Handler');
}

// Register activation hook
register_activation_hook(__FILE__, 'mdm_activate');

// Register deactivation hook
register_deactivation_hook(__FILE__, 'mdm_deactivate');

// Initialize plugin after WooCommerce is loaded
add_action('plugins_loaded', 'mdm_init');

// Autoloader for plugin classes
spl_autoload_register(function($class) {
    $prefix = 'MembershipDiscountManager\\';
    $base_dir = MDM_PLUGIN_DIR . 'includes/';

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

// Hook into the initialization action
add_action('mdm_initialize_tiers', array('MembershipDiscountManager\\Activator', 'initialize_member_tiers')); 