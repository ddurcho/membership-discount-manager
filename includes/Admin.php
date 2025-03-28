<?php
namespace MembershipDiscountManager;

class Admin {
    /**
     * Initialize the admin functionality
     */
    public function init() {
        // Add menu items
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add AJAX handlers
        add_action('wp_ajax_mdm_sync_all', array($this, 'handle_sync_all'));
        add_action('wp_ajax_mdm_sync_user', array($this, 'handle_sync_user'));
    }

    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Nestwork Discounts', 'membership-discount-manager'),
            __('Nestwork Discounts', 'membership-discount-manager'),
            'manage_options',
            'nestwork-options',
            array($this, 'render_options_page'),
            'dashicons-money',
            30
        );

        add_submenu_page(
            'nestwork-options',
            __('All-Time Spend', 'membership-discount-manager'),
            __('All-Time Spend', 'membership-discount-manager'),
            'manage_options',
            'nestwork-all-time-spend',
            array($this, 'render_all_time_spend_page')
        );

        add_submenu_page(
            'nestwork-options',
            __('Last Year Spend', 'membership-discount-manager'),
            __('Last Year Spend', 'membership-discount-manager'),
            'manage_options',
            'nestwork-last-year-spend',
            array($this, 'render_last_year_spend_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('mdm_options', 'mdm_tier_settings', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_tier_settings')
        ));
    }

    /**
     * Sanitize tier settings
     */
    public function sanitize_tier_settings($input) {
        $sanitized = array();
        
        foreach ($input as $tier => $settings) {
            $sanitized[$tier] = array(
                'min_spend' => floatval($settings['min_spend']),
                'discount' => floatval($settings['discount'])
            );
        }
        
        return $sanitized;
    }

    /**
     * Render the options page
     */
    public function render_options_page() {
        require_once MDM_PLUGIN_DIR . 'templates/options.php';
    }

    /**
     * Render the all-time spend page
     */
    public function render_all_time_spend_page() {
        require_once MDM_PLUGIN_DIR . 'templates/all-time-spend.php';
    }

    /**
     * Render the last year spend page
     */
    public function render_last_year_spend_page() {
        require_once MDM_PLUGIN_DIR . 'templates/last-year-spend.php';
    }

    /**
     * Handle sync all users AJAX request
     */
    public function handle_sync_all() {
        check_ajax_referer('mdm_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        try {
            $stats = $this->sync_all_users();
            wp_send_json_success($stats);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle sync single user AJAX request
     */
    public function handle_sync_user() {
        check_ajax_referer('mdm_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $user_id = intval($_POST['user_id']);
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
            return;
        }

        try {
            $result = $this->sync_user_spending_data($user_id);
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Sync all users' spending data
     */
    public function sync_all_users() {
        global $wpdb;
        
        $stats = array(
            'total' => 0,
            'processed' => 0,
            'success' => 0,
            'failed' => 0
        );

        $user_ids = $wpdb->get_col("
            SELECT DISTINCT user_id 
            FROM {$wpdb->prefix}wc_customer_lookup
            WHERE user_id > 0
        ");

        $stats['total'] = count($user_ids);

        foreach ($user_ids as $user_id) {
            $stats['processed']++;
            
            try {
                $this->sync_user_spending_data($user_id);
                $stats['success']++;
            } catch (\Exception $e) {
                $stats['failed']++;
                error_log('MDM sync error for user ' . $user_id . ': ' . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Sync spending data for a specific user
     */
    public function sync_user_spending_data($user_id) {
        global $wpdb;

        // Get user's membership
        $membership = wc_memberships_get_user_membership($user_id);
        if (!$membership) {
            throw new \Exception('User has no membership');
        }

        // Calculate spending
        $all_time_spend = $wpdb->get_var($wpdb->prepare("
            SELECT SUM(total_sales) 
            FROM {$wpdb->prefix}wc_customer_lookup cl
            JOIN {$wpdb->prefix}wc_order_stats os ON cl.customer_id = os.customer_id
            WHERE cl.user_id = %d
            AND os.status = 'wc-completed'
        ", $user_id));

        $last_year_spend = $wpdb->get_var($wpdb->prepare("
            SELECT SUM(total_sales) 
            FROM {$wpdb->prefix}wc_customer_lookup cl
            JOIN {$wpdb->prefix}wc_order_stats os ON cl.customer_id = os.customer_id
            WHERE cl.user_id = %d
            AND os.status = 'wc-completed'
            AND os.date_created >= %s
        ", $user_id, date('Y-m-d H:i:s', strtotime('-1 year'))));

        // Update membership profile fields
        $membership->set_profile_field('total_spend_all_time', $all_time_spend ?: 0);
        $membership->set_profile_field('average_spend_last_year', $last_year_spend ?: 0);

        // Calculate and set discount tier
        $tier = $this->calculate_discount_tier($all_time_spend ?: 0);
        $membership->set_profile_field('discount_tier', $tier);

        return array(
            'all_time_spend' => $all_time_spend ?: 0,
            'last_year_spend' => $last_year_spend ?: 0,
            'tier' => $tier
        );
    }

    /**
     * Calculate discount tier based on spending
     */
    private function calculate_discount_tier($total_spend) {
        $tier_settings = get_option('mdm_tier_settings');
        $current_tier = 'none';

        foreach ($tier_settings as $tier => $settings) {
            if ($total_spend >= $settings['min_spend']) {
                $current_tier = $tier;
            }
        }

        return $current_tier;
    }

    /**
     * Sync user data when order is completed
     */
    public function sync_order_user($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $user_id = $order->get_user_id();
        if (!$user_id) {
            return;
        }

        try {
            $this->sync_user_spending_data($user_id);
        } catch (\Exception $e) {
            error_log('MDM order sync error for user ' . $user_id . ': ' . $e->getMessage());
        }
    }
} 