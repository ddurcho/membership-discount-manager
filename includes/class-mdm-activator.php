<?php
namespace MembershipDiscountManager;

/**
 * Fired during plugin activation
 */
class Activator {
    /**
     * Activate the plugin
     */
    public static function activate() {
        // Check if WooCommerce is active
        if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            deactivate_plugins(MDM_PLUGIN_BASENAME);
            wp_die(
                __('This plugin requires WooCommerce to be installed and activated.', 'membership-discount-manager'),
                'Plugin Activation Error',
                array('back_link' => true)
            );
        }

        // Check if WooCommerce Memberships is active
        if (!in_array('woocommerce-memberships/woocommerce-memberships.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            deactivate_plugins(MDM_PLUGIN_BASENAME);
            wp_die(
                __('This plugin requires WooCommerce Memberships to be installed and activated.', 'membership-discount-manager'),
                'Plugin Activation Error',
                array('back_link' => true)
            );
        }

        // Initialize default settings
        if (!get_option('mdm_settings')) {
            update_option('mdm_settings', array(
                'version' => MDM_VERSION,
                'initialized' => current_time('mysql')
            ));
        }

        // Schedule cron job for daily tier updates
        if (!wp_next_scheduled('mdm_daily_tier_update')) {
            wp_schedule_event(time(), 'daily', 'mdm_daily_tier_update');
        }

        // Clear any existing transients
        delete_transient('mdm_user_data_cache');

        // Schedule a one-time action to initialize member tiers
        if (!wp_next_scheduled('mdm_initialize_tiers')) {
            wp_schedule_single_event(time() + 30, 'mdm_initialize_tiers');
        }
    }

    /**
     * Initialize tiers for all members
     * This will be called by the scheduled action after all plugins are loaded
     */
    public static function initialize_member_tiers() {
        if (!function_exists('wc_memberships_get_active_members')) {
            return;
        }

        $members = wc_memberships_get_active_members();
        
        if (!empty($members)) {
            foreach ($members as $member) {
                $user_id = $member->get_user_id();
                $existing_tier = get_user_meta($user_id, '_mdm_discount_tier', true);
                
                if (!$existing_tier) {
                    update_user_meta($user_id, '_mdm_discount_tier', 'bronze');
                    update_user_meta($user_id, '_mdm_total_spend', '0');
                    update_user_meta($user_id, '_mdm_manual_override', '0');
                }
            }
        }
    }
} 