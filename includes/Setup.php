<?php
namespace MembershipDiscountManager;

class Setup {
    /**
     * Initialize the setup
     */
    public function init() {
        // Register activation/deactivation hooks
        register_activation_hook(MDM_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(MDM_PLUGIN_FILE, array($this, 'deactivate'));

        // Register cron hook
        add_action('mdm_daily_sync_hook', array($this, 'run_daily_sync'));

        // Register WooCommerce order hooks
        add_action('woocommerce_order_status_completed', array($this, 'sync_completed_order'));
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Schedule daily sync if not already scheduled
        if (!wp_next_scheduled('mdm_daily_sync_hook')) {
            wp_schedule_event(time(), 'daily', 'mdm_daily_sync_hook');
        }
        
        // Initialize cron tracking options
        add_option('mdm_cron_last_run', '');
        add_option('mdm_cron_last_status', '');
        add_option('mdm_cron_stats', array());
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Remove scheduled sync
        wp_clear_scheduled_hook('mdm_daily_sync_hook');
    }

    /**
     * Run the daily sync
     */
    public function run_daily_sync() {
        global $mdm_admin;
        
        if ($mdm_admin) {
            update_option('mdm_cron_last_run', current_time('mysql'));
            
            try {
                $stats = $mdm_admin->sync_all_users();
                update_option('mdm_cron_last_status', 'success');
                update_option('mdm_cron_stats', $stats);
            } catch (\Exception $e) {
                update_option('mdm_cron_last_status', 'error');
                update_option('mdm_cron_stats', array(
                    'error' => $e->getMessage(),
                    'time' => current_time('mysql')
                ));
            }
        }
    }

    /**
     * Get cron status information
     * 
     * @return array Cron status information
     */
    public function get_cron_status() {
        $next_scheduled = wp_next_scheduled('mdm_daily_sync_hook');
        
        return array(
            'is_scheduled' => (bool) $next_scheduled,
            'next_run' => $next_scheduled ? get_date_from_gmt(date('Y-m-d H:i:s', $next_scheduled), 'Y-m-d H:i:s') : null,
            'last_run' => get_option('mdm_cron_last_run', ''),
            'last_status' => get_option('mdm_cron_last_status', ''),
            'last_stats' => get_option('mdm_cron_stats', array())
        );
    }

    /**
     * Sync user data when order is completed
     * 
     * @param int $order_id
     */
    public function sync_completed_order($order_id) {
        global $mdm_admin;
        
        if ($mdm_admin) {
            $mdm_admin->sync_order_user($order_id);
        }
    }
} 