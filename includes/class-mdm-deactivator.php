<?php
namespace MembershipDiscountManager;

/**
 * Fired during plugin deactivation
 */
class Deactivator {
    /**
     * Deactivate the plugin
     */
    public static function deactivate() {
        // Remove scheduled cron job
        wp_clear_scheduled_hook('mdm_daily_tier_update');

        // Clear any transients
        delete_transient('mdm_user_data_cache');

        // Optionally, you could add code here to:
        // 1. Clean up any temporary data
        // 2. Remove plugin-specific user meta if desired
        // 3. Log deactivation for debugging purposes
        
        // Note: We don't remove the main plugin settings or user discount tiers
        // in case the plugin is reactivated later
    }
} 