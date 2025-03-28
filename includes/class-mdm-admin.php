<?php
namespace MembershipDiscountManager;

/**
 * Handles admin interface and AJAX functionality
 */
class Admin {
    /**
     * Logger instance
     *
     * @var Logger
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new Logger();
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Add menu items
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Handle form submission
        add_action('admin_init', array($this, 'handle_options_submission'));
        
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // AJAX handlers
        add_action('wp_ajax_mdm_get_user_data', array($this, 'ajax_get_user_data'));
        add_action('wp_ajax_mdm_update_user_tier', array($this, 'ajax_update_user_tier'));
        add_action('wp_ajax_mdm_sync_membership_fields', array($this, 'ajax_sync_membership_fields'));
        add_action('wp_ajax_mdm_clear_logs', array($this, 'ajax_clear_logs'));

        // Log plugin initialization
        $this->logger->info('Membership Discount Manager admin initialized');
    }

    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        // Add main menu
        add_menu_page(
            __('Nestwork Discounts', 'membership-discount-manager'),
            __('Nestwork Discounts', 'membership-discount-manager'),
            'manage_woocommerce',
            'nestwork-discounts',
            array($this, 'render_all_time_spend_page'),
            'dashicons-money-alt',
            58 // After WooCommerce
        );

        // Add submenu items
        add_submenu_page(
            'nestwork-discounts',
            __('All-Time Spend', 'membership-discount-manager'),
            __('All-Time Spend', 'membership-discount-manager'),
            'manage_woocommerce',
            'nestwork-discounts',
            array($this, 'render_all_time_spend_page')
        );

        add_submenu_page(
            'nestwork-discounts',
            __('Last Year Spend', 'membership-discount-manager'),
            __('Last Year Spend', 'membership-discount-manager'),
            'manage_woocommerce',
            'nestwork-last-year',
            array($this, 'render_last_year_spend_page')
        );

        add_submenu_page(
            'nestwork-discounts',
            __('User Status', 'membership-discount-manager'),
            __('User Status', 'membership-discount-manager'),
            'manage_woocommerce',
            'nestwork-user-status',
            array($this, 'render_user_status_page')
        );

        add_submenu_page(
            'nestwork-discounts',
            __('All Memberships', 'membership-discount-manager'),
            __('All Memberships', 'membership-discount-manager'),
            'manage_woocommerce',
            'nestwork-all-memberships',
            array($this, 'render_all_memberships_page')
        );

        add_submenu_page(
            'nestwork-discounts',
            __('Options', 'membership-discount-manager'),
            __('Options', 'membership-discount-manager'),
            'manage_woocommerce',
            'nestwork-options',
            array($this, 'render_options_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // Existing tier settings
        register_setting(
            'mdm_options',
            'mdm_tier_settings',
            array(
                'type' => 'array',
                'description' => 'Tier settings including spending thresholds and discount percentages',
                'sanitize_callback' => array($this, 'sanitize_tier_settings'),
                'default' => array(
                    'none' => array(
                        'min_spend' => 0,
                        'discount' => 0,
                    ),
                    'bronze' => array(
                        'min_spend' => 500,
                        'discount' => 5,
                    ),
                    'silver' => array(
                        'min_spend' => 1000,
                        'discount' => 10,
                    ),
                    'gold' => array(
                        'min_spend' => 5000,
                        'discount' => 15,
                    ),
                    'platinum' => array(
                        'min_spend' => 10000,
                        'discount' => 20,
                    ),
                ),
            )
        );

        // Batch size setting
        register_setting('mdm_options', 'mdm_sync_batch_size', array(
            'type' => 'integer',
            'description' => 'Number of users to process in each sync batch',
            'sanitize_callback' => array($this, 'sanitize_batch_size'),
            'default' => 20,
        ));

        // Logging settings
        register_setting('mdm_options', 'mdm_logging_enabled', array(
            'type' => 'boolean',
            'description' => 'Whether logging is enabled',
            'default' => true,
        ));

        register_setting('mdm_options', 'mdm_debug_mode', array(
            'type' => 'boolean',
            'description' => 'Whether debug mode is enabled',
            'default' => false,
        ));

        // Add AJAX handler for clearing logs
        add_action('wp_ajax_mdm_clear_logs', array($this, 'ajax_clear_logs'));
    }

    /**
     * Sanitize tier settings
     *
     * @param array $input The input array to sanitize
     * @return array Sanitized input
     */
    public function sanitize_tier_settings($input) {
        $sanitized = array();
        $tiers = array('none', 'bronze', 'silver', 'gold', 'platinum');

        foreach ($tiers as $tier) {
            if (isset($input[$tier])) {
                $sanitized[$tier] = array(
                    'min_spend' => max(0, floatval($input[$tier]['min_spend'])),
                    'discount' => min(100, max(0, floatval($input[$tier]['discount']))),
                );
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize batch size setting
     *
     * @param mixed $input
     * @return int
     */
    public function sanitize_batch_size($input) {
        $value = absint($input);
        return min(max($value, 1), 100);
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'nestwork-') === false) {
            return;
        }

        wp_enqueue_style(
            'mdm-admin-styles',
            MDM_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            MDM_VERSION
        );

        wp_enqueue_script(
            'mdm-admin-script',
            MDM_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            MDM_VERSION,
            true
        );

        wp_localize_script('mdm-admin-script', 'mdmAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mdm-admin-nonce'),
            'i18n' => array(
                'syncing' => __('Syncing... %1$d%', 'membership-discount-manager'),
                'sync_complete' => __('Sync complete! Processed %1$d members.', 'membership-discount-manager'),
                'sync_error' => __('Error occurred during sync. Please try again.', 'membership-discount-manager'),
            ),
        ));
    }

    /**
     * Render all-time spend page
     */
    public function render_all_time_spend_page() {
        global $wpdb;
        
        // Get current page
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // Search functionality
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $search_clause = '';
        if ($search) {
            $search_clause = $wpdb->prepare(
                "AND (cl.email LIKE %s OR CONCAT(cl.first_name, ' ', cl.last_name) LIKE %s)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        // Get total items for pagination
        $total_items = $wpdb->get_var("
            SELECT COUNT(DISTINCT cl.customer_id)
            FROM {$wpdb->prefix}wc_order_stats AS os
            INNER JOIN {$wpdb->prefix}wc_customer_lookup AS cl ON os.customer_id = cl.customer_id
            WHERE os.status = 'wc-completed'
            $search_clause
        ");

        // Get data
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT
                cl.user_id,
                cl.customer_id,
                cl.email AS user_email,
                CONCAT(cl.first_name, ' ', cl.last_name) AS full_name,
                ROUND(SUM(os.total_sales), 2) AS total_sales,
                ROUND(SUM(os.tax_total), 2) AS total_tax,
                ROUND(SUM(os.net_total), 2) AS total_net
            FROM {$wpdb->prefix}wc_order_stats AS os
            INNER JOIN {$wpdb->prefix}wc_customer_lookup AS cl ON os.customer_id = cl.customer_id
            WHERE os.status = 'wc-completed'
            $search_clause
            GROUP BY cl.user_id, cl.customer_id, cl.email, full_name
            ORDER BY total_sales DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset));

        // Calculate pagination
        $total_pages = ceil($total_items / $per_page);

        include MDM_PLUGIN_DIR . 'templates/all-time-spend.php';
    }

    /**
     * Render last year spend page
     */
    public function render_last_year_spend_page() {
        global $wpdb;
        
        // Get current page
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // Search functionality
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $search_clause = '';
        if ($search) {
            $search_clause = $wpdb->prepare(
                "AND (cl.email LIKE %s OR CONCAT(cl.first_name, ' ', cl.last_name) LIKE %s)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        // Get total items for pagination
        $total_items = $wpdb->get_var("
            SELECT COUNT(DISTINCT cl.customer_id)
            FROM {$wpdb->prefix}wc_order_stats AS os
            INNER JOIN {$wpdb->prefix}wc_customer_lookup AS cl ON os.customer_id = cl.customer_id
            WHERE os.status = 'wc-completed'
            AND os.date_created >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
            $search_clause
        ");

        // Get data
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT
                cl.user_id,
                cl.customer_id,
                cl.email AS user_email,
                CONCAT(cl.first_name, ' ', cl.last_name) AS full_name,
                ROUND(SUM(os.total_sales), 2) AS total_sales_last_year,
                ROUND(SUM(os.tax_total), 2) AS total_tax_last_year,
                ROUND(SUM(os.net_total), 2) AS total_net_last_year
            FROM {$wpdb->prefix}wc_order_stats AS os
            INNER JOIN {$wpdb->prefix}wc_customer_lookup AS cl ON os.customer_id = cl.customer_id
            WHERE os.status = 'wc-completed'
            AND os.date_created >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
            $search_clause
            GROUP BY cl.user_id, cl.customer_id, cl.email, full_name
            ORDER BY total_sales_last_year DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset));

        // Calculate pagination
        $total_pages = ceil($total_items / $per_page);

        include MDM_PLUGIN_DIR . 'templates/last-year-spend.php';
    }

    /**
     * Render user status page
     */
    public function render_user_status_page() {
        global $wpdb;
        
        // Get current page
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // Search functionality
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $search_clause = '';
        if ($search) {
            $search_clause = $wpdb->prepare(
                "AND (cl.email LIKE %s OR CONCAT(cl.first_name, ' ', cl.last_name) LIKE %s)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        // Get total items for pagination
        $total_items = $wpdb->get_var("
            SELECT COUNT(DISTINCT cl.customer_id)
            FROM {$wpdb->prefix}wc_customer_lookup AS cl
            LEFT JOIN {$wpdb->prefix}posts AS p
                ON p.post_author = cl.user_id
                AND p.post_type = 'wc_user_membership'
                AND p.post_status IN ('wcm-active', 'wcm-expired', 'wcm-cancelled', 'publish')
            WHERE 1=1
            $search_clause
        ");

        // Get data with pagination
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                cl.customer_id,
                cl.user_id,
                cl.email AS user_email,
                CONCAT(cl.first_name, ' ', cl.last_name) AS full_name,
                p.post_status AS membership_status,
                COUNT(p.ID) AS membership_count
            FROM {$wpdb->prefix}wc_customer_lookup AS cl
            LEFT JOIN {$wpdb->prefix}posts AS p
                ON p.post_author = cl.user_id
                AND p.post_type = 'wc_user_membership'
                AND p.post_status IN ('wcm-active', 'wcm-expired', 'wcm-cancelled', 'publish')
            WHERE 1=1
            $search_clause
            GROUP BY cl.customer_id, cl.user_id, user_email, full_name, p.post_status
            ORDER BY cl.customer_id, p.post_status
            LIMIT %d OFFSET %d
        ", $per_page, $offset));

        // Calculate pagination
        $total_pages = ceil($total_items / $per_page);

        include MDM_PLUGIN_DIR . 'templates/user-status.php';
    }

    /**
     * Render options page
     */
    public function render_options_page() {
        if (isset($_POST['submit']) && check_admin_referer('mdm_options_nonce')) {
            // Handle tier settings
            $tier_settings = array();
            $tiers = array('none', 'bronze', 'silver', 'gold', 'platinum');

            foreach ($tiers as $tier) {
                $tier_settings[$tier] = array(
                    'min_spend' => isset($_POST['tier'][$tier]['min_spend']) ? floatval($_POST['tier'][$tier]['min_spend']) : 0,
                    'discount' => isset($_POST['tier'][$tier]['discount']) ? floatval($_POST['tier'][$tier]['discount']) : 0,
                );
            }

            update_option('mdm_tier_settings', $tier_settings);

            // Handle logging settings
            update_option('mdm_logging_enabled', isset($_POST['mdm_logging_enabled']));
            update_option('mdm_debug_mode', isset($_POST['mdm_debug_mode']));

            $this->logger->info('Settings updated', array(
                'logging_enabled' => get_option('mdm_logging_enabled'),
                'debug_mode' => get_option('mdm_debug_mode')
            ));

            add_settings_error('mdm_messages', 'mdm_message', __('Settings Saved', 'membership-discount-manager'), 'updated');
        }

        // Get current settings
        $tier_settings = get_option('mdm_tier_settings');
        
        include MDM_PLUGIN_DIR . 'templates/options.php';
    }

    /**
     * Handle options page form submission
     */
    public function handle_options_submission() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'mdm_options_nonce')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        // Save tier settings
        if (isset($_POST['tier'])) {
            $tier_settings = $this->sanitize_tier_settings($_POST['tier']);
            update_option('mdm_tier_settings', $tier_settings);
        }

        // Save batch size
        if (isset($_POST['mdm_sync_batch_size'])) {
            $batch_size = $this->sanitize_batch_size($_POST['mdm_sync_batch_size']);
            update_option('mdm_sync_batch_size', $batch_size);
        }

        // Save logging settings
        update_option('mdm_logging_enabled', isset($_POST['mdm_logging_enabled']));
        update_option('mdm_debug_mode', isset($_POST['mdm_debug_mode']));

        add_settings_error(
            'mdm_messages',
            'mdm_settings_updated',
            __('Settings saved successfully.', 'membership-discount-manager'),
            'updated'
        );
    }

    /**
     * AJAX handler for getting user data
     */
    public function ajax_get_user_data() {
        check_ajax_referer('mdm-admin-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
        }

        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $members = $this->get_filtered_members($search);
        
        wp_send_json_success($members);
    }

    /**
     * AJAX handler for updating user tier
     */
    public function ajax_update_user_tier() {
        check_ajax_referer('mdm-admin-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
        }

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $tier = isset($_POST['tier']) ? sanitize_text_field($_POST['tier']) : '';
        $override = isset($_POST['override']) ? (bool) $_POST['override'] : false;

        if (!$user_id || !$tier) {
            wp_send_json_error('Invalid parameters');
        }

        $discount_manager = new DiscountManager();
        $result = $discount_manager->set_manual_tier($user_id, $tier, $override);

        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to update tier');
        }
    }

    /**
     * Core function to sync spending data and tier for a single user
     * 
     * @param int $user_id The user ID to sync
     * @return array|WP_Error Returns array of updated data or WP_Error on failure
     */
    private function sync_user_spending_data($user_id) {
        try {
            global $wpdb;

            // Verify user has membership
            $has_membership = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM {$wpdb->posts}
                WHERE post_type = 'wc_user_membership'
                AND post_author = %d
                AND post_status IN ('wcm-active', 'wcm-expired', 'wcm-cancelled', 'wcm-pending', 'publish')
            ", $user_id));

            if (!$has_membership) {
                return new \WP_Error('no_membership', 'User has no memberships');
            }

            // Get tier settings from options
            $tier_settings = get_option('mdm_tier_settings', array(
                'none' => array('min_spend' => 0, 'discount' => 0),
                'bronze' => array('min_spend' => 349, 'discount' => 5),
                'silver' => array('min_spend' => 500, 'discount' => 10),
                'gold' => array('min_spend' => 1000, 'discount' => 15),
                'platinum' => array('min_spend' => 1500, 'discount' => 20)
            ));

            // Sort tiers by min_spend in descending order
            uasort($tier_settings, function($a, $b) {
                return $b['min_spend'] - $a['min_spend'];
            });

            // Get all-time spending
            $all_time_spending = $wpdb->get_var($wpdb->prepare("
                SELECT ROUND(SUM(os.net_total), 2) as total_net
                FROM {$wpdb->prefix}wc_order_stats AS os
                INNER JOIN {$wpdb->prefix}wc_customer_lookup AS cl ON os.customer_id = cl.customer_id
                WHERE cl.user_id = %d
                AND os.status = 'wc-completed'
            ", $user_id));

            // Get last year's spending
            $last_year_spending = $wpdb->get_var($wpdb->prepare("
                SELECT ROUND(SUM(os.net_total), 2) as total_net_last_year
                FROM {$wpdb->prefix}wc_order_stats AS os
                INNER JOIN {$wpdb->prefix}wc_customer_lookup AS cl ON os.customer_id = cl.customer_id
                WHERE cl.user_id = %d
                AND os.status = 'wc-completed'
                AND os.date_created >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
            ", $user_id));

            // Determine appropriate tier
            $current_tier = 'none';
            $all_time_spending = floatval($all_time_spending ?: 0);

            foreach ($tier_settings as $tier => $settings) {
                if ($all_time_spending >= $settings['min_spend']) {
                    $current_tier = ucfirst($tier);
                    break;
                }
            }

            // Update user meta
            update_user_meta($user_id, '_wc_memberships_profile_field_total_spend_all_time', $all_time_spending);
            update_user_meta($user_id, '_wc_memberships_profile_field_average_spend_last_year', $last_year_spending ?: 0);
            update_user_meta($user_id, '_wc_memberships_profile_field_discount_tier', $current_tier);
            update_user_meta($user_id, '_wc_memberships_profile_field_discount_last_sync', current_time('mysql'));

            $this->logger->debug('Updated user profile fields', array(
                'user_id' => $user_id,
                'all_time_spend' => $all_time_spending,
                'last_year_spend' => $last_year_spending ?: 0,
                'assigned_tier' => $current_tier
            ));

            return array(
                'user_id' => $user_id,
                'all_time_spend' => $all_time_spending,
                'last_year_spend' => $last_year_spending ?: 0,
                'assigned_tier' => $current_tier,
                'sync_time' => current_time('mysql')
            );

        } catch (\Exception $e) {
            $this->logger->error('Error syncing user data', array(
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ));
            return new \WP_Error('sync_error', $e->getMessage());
        }
    }

    /**
     * Sync all users with memberships
     * 
     * @return array Stats about the sync process
     */
    public function sync_all_users() {
        global $wpdb;

        try {
            // Get all users with memberships
            $users = $wpdb->get_col("
                SELECT DISTINCT post_author
                FROM {$wpdb->posts}
                WHERE post_type = 'wc_user_membership'
                AND post_status IN ('wcm-active', 'wcm-expired', 'wcm-cancelled', 'wcm-pending', 'publish')
            ");

            $stats = array(
                'total' => count($users),
                'processed' => 0,
                'success' => 0,
                'failed' => 0
            );

            foreach ($users as $user_id) {
                $result = $this->sync_user_spending_data($user_id);
                $stats['processed']++;
                
                if (is_wp_error($result)) {
                    $stats['failed']++;
                } else {
                    $stats['success']++;
                }
            }

            update_option('mdm_last_fields_sync', current_time('mysql'));
            $this->logger->info('Completed full sync', $stats);

            return $stats;

        } catch (\Exception $e) {
            $this->logger->error('Full sync failed', array(
                'error' => $e->getMessage()
            ));
            return new \WP_Error('sync_error', $e->getMessage());
        }
    }

    /**
     * Sync user data when an order is completed
     * 
     * @param int $order_id WooCommerce order ID
     * @return array|WP_Error Result of the sync operation
     */
    public function sync_order_user($order_id) {
        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new \Exception('Order not found');
            }

            $user_id = $order->get_user_id();
            if (!$user_id) {
                throw new \Exception('Order has no associated user');
            }

            return $this->sync_user_spending_data($user_id);

        } catch (\Exception $e) {
            $this->logger->error('Order sync failed', array(
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ));
            return new \WP_Error('sync_error', $e->getMessage());
        }
    }

    /**
     * Modified AJAX handler to use the core sync function with batch processing
     */
    public function ajax_sync_membership_fields() {
        try {
            check_ajax_referer('mdm-admin-nonce', 'nonce');

            if (!current_user_can('manage_woocommerce')) {
                throw new \Exception('Insufficient permissions');
            }

            // Get batch parameters
            $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
            $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 20;

            // Check if sync is already running
            $sync_running = get_transient('mdm_sync_in_progress');
            if ($sync_running && $offset === 0) {
                throw new \Exception('Sync already in progress. Please wait for it to complete.');
            }

            // Set sync in progress flag only for the first batch
            if ($offset === 0) {
                set_transient('mdm_sync_in_progress', true, 5 * MINUTE_IN_SECONDS);
            }

            try {
                global $wpdb;

                // Get total number of users to process
                $total_users = $wpdb->get_var("
                    SELECT COUNT(DISTINCT post_author)
                    FROM {$wpdb->posts}
                    WHERE post_type = 'wc_user_membership'
                    AND post_status IN ('wcm-active', 'wcm-expired', 'wcm-cancelled', 'wcm-pending', 'publish')
                ");

                // Get batch of users
                $users = $wpdb->get_col($wpdb->prepare("
                    SELECT DISTINCT post_author
                    FROM {$wpdb->posts}
                    WHERE post_type = 'wc_user_membership'
                    AND post_status IN ('wcm-active', 'wcm-expired', 'wcm-cancelled', 'wcm-pending', 'publish')
                    ORDER BY post_author
                    LIMIT %d OFFSET %d
                ", $batch_size, $offset));

                $stats = array(
                    'total' => (int)$total_users,
                    'processed' => $offset + count($users),
                    'success' => 0,
                    'failed' => 0
                );

                foreach ($users as $user_id) {
                    $result = $this->sync_user_spending_data($user_id);
                    if (is_wp_error($result)) {
                        $stats['failed']++;
                    } else {
                        $stats['success']++;
                    }
                }

                // If this is the last batch, clean up
                if ($stats['processed'] >= $total_users) {
                    delete_transient('mdm_sync_in_progress');
                    update_option('mdm_last_fields_sync', current_time('mysql'));
                    $this->logger->info('Completed full sync', $stats);
                } else {
                    $this->logger->debug('Completed batch sync', array(
                        'batch' => $offset / $batch_size + 1,
                        'processed' => $stats['processed'],
                        'total' => $total_users
                    ));
                }

                wp_send_json_success($stats);

            } catch (\Exception $e) {
                // Clear sync in progress flag on error
                delete_transient('mdm_sync_in_progress');
                throw $e;
            }

        } catch (\Exception $e) {
            $this->logger->error('AJAX sync failed', array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Get filtered list of members
     *
     * @param string $search
     * @return array
     */
    private function get_filtered_members($search) {
        try {
            // Check if WooCommerce Memberships is active
            if (!function_exists('wc_memberships_get_user_memberships')) {
                throw new \Exception('WooCommerce Memberships plugin is not active');
            }

            $members = wc_memberships_get_user_memberships();
            $filtered_members = array();

            $this->logger->debug('Fetching filtered members', array(
                'search' => $search,
                'total_members' => count($members)
            ));

            if (!empty($members)) {
                foreach ($members as $member) {
                    $user = get_user_by('id', $member->get_user_id());
                    
                    if ($search && 
                        stripos($user->user_login, $search) === false && 
                        stripos($user->user_email, $search) === false) {
                        continue;
                    }

                    $user_id = $member->get_user_id();
                    $tier = get_user_meta($user_id, '_mdm_discount_tier', true);
                    $total_spend = get_user_meta($user_id, '_mdm_total_spend', true);
                    $override = get_user_meta($user_id, '_mdm_manual_override', true);

                    $filtered_members[] = array(
                        'id' => $user_id,
                        'name' => $user->display_name,
                        'email' => $user->user_email,
                        'tier' => $tier ?: 'none',
                        'total_spend' => $total_spend ?: 0,
                        'override' => (bool) $override,
                    );
                }
            }

            return $filtered_members;

        } catch (\Exception $e) {
            $this->logger->error('Error getting filtered members', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            return array();
        }
    }

    /**
     * AJAX handler for clearing logs
     */
    public function ajax_clear_logs() {
        try {
            check_ajax_referer('mdm-admin-nonce', 'nonce');

            if (!current_user_can('manage_woocommerce')) {
                throw new \Exception('Insufficient permissions');
            }

            $this->logger->clear_logs();
            wp_send_json_success();

        } catch (\Exception $e) {
            $this->logger->error('Failed to clear logs', array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Render all memberships page
     */
    public function render_all_memberships_page() {
        global $wpdb;
        
        // Get current page
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // Search functionality
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $search_clause = '';
        if ($search) {
            $search_clause = $wpdb->prepare(
                "AND (cl.email LIKE %s OR CONCAT(cl.first_name, ' ', cl.last_name) LIKE %s)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        // Get total items for pagination
        $total_items = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}wc_customer_lookup AS cl
            LEFT JOIN {$wpdb->posts} AS p
                ON p.post_author = cl.user_id
                AND p.post_type = 'wc_user_membership'
                AND p.post_status IN ('wcm-active', 'wcm-expired', 'wcm-cancelled', 'publish')
            WHERE p.ID IS NOT NULL
            $search_clause
        ");

        // Get data with pagination
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                cl.customer_id,
                cl.user_id,
                cl.email AS user_email,
                CONCAT(cl.first_name, ' ', cl.last_name) AS full_name,
                p.ID AS membership_id,
                p.post_status AS membership_status,
                start_meta.meta_value AS start_date,
                end_meta.meta_value AS end_date
            FROM {$wpdb->prefix}wc_customer_lookup AS cl
            LEFT JOIN {$wpdb->posts} AS p
                ON p.post_author = cl.user_id
                AND p.post_type = 'wc_user_membership'
                AND p.post_status IN ('wcm-active', 'wcm-expired', 'wcm-cancelled', 'publish')
            LEFT JOIN {$wpdb->postmeta} AS start_meta
                ON start_meta.post_id = p.ID AND start_meta.meta_key = '_start_date'
            LEFT JOIN {$wpdb->postmeta} AS end_meta
                ON end_meta.post_id = p.ID AND end_meta.meta_key = '_end_date'
            WHERE p.ID IS NOT NULL
            $search_clause
            ORDER BY p.post_date DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset));

        // Calculate pagination
        $total_pages = ceil($total_items / $per_page);

        include MDM_PLUGIN_DIR . 'templates/all-memberships.php';
    }
} 