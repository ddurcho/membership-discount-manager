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
     * Static flag to track initialization
     *
     * @var bool
     */
    private static $initialized = false;

    /**
     * Constructor
     */
    public function __construct() {
        // Only initialize once
        if (self::$initialized) {
            return;
        }

        $this->logger = new Logger();
        $this->init_hooks();
        
        self::$initialized = true;
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
        //$this->logger->info('Membership Discount Manager admin initialized');
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

        // New automation settings
        register_setting('mdm_options', 'mdm_auto_calc_enabled');
        register_setting('mdm_options', 'mdm_auto_calc_frequency');

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
                'confirm_clear_logs' => __('You are about to clear all debug logs. This action cannot be undone. Are you sure you want to continue?', 'membership-discount-manager'),
                'logs_cleared' => __('Debug logs have been cleared successfully.', 'membership-discount-manager'),
                'clear_logs_error' => __('Error clearing debug logs. Please try again or check server permissions.', 'membership-discount-manager')
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
     * Check if profile fields exist in WooCommerce Memberships
     *
     * @return array Array of field statuses
     */
    private function check_profile_fields_exist() {
        global $wpdb;
        
        $required_fields = array(
            'discount_tier' => 'VIP Status',
            'average_spend_last_year' => 'Average spend last year',
            'total_spend_all_time' => 'User total spend',
            'manual_discount_override' => 'Flag to skip automatic recalculations',
            'discount_last_sync' => 'Discount last sync'
        );
        
        $fields_status = array();
        
        // Get the profile fields from wp_options
        $profile_fields = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                'wc_memberships_profile_fields'
            )
        );
        
        if ($profile_fields) {
            $fields = maybe_unserialize($profile_fields);
            
            foreach ($required_fields as $slug => $name) {
                $fields_status[$slug] = isset($fields[$slug]);
            }
        } else {
            // If no fields found, mark all as non-existent
            foreach ($required_fields as $slug => $name) {
                $fields_status[$slug] = false;
            }
        }
        
        return $fields_status;
    }

    /**
     * Render options page
     */
    public function render_options_page() {
        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        ?>
        <div class="wrap">
            <h1><?php _e('Nestwork Discounts Options', 'membership-discount-manager'); ?></h1>

            <?php settings_errors(); ?>

            <nav class="nav-tab-wrapper">
                <a href="?page=nestwork-options&tab=general" class="nav-tab <?php echo $current_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('General Settings', 'membership-discount-manager'); ?>
                </a>
                <a href="?page=nestwork-options&tab=automation" class="nav-tab <?php echo $current_tab === 'automation' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Automation', 'membership-discount-manager'); ?>
                </a>
                <a href="?page=nestwork-options&tab=debug" class="nav-tab <?php echo $current_tab === 'debug' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Debug', 'membership-discount-manager'); ?>
                </a>
            </nav>

            <form method="post" action="<?php echo admin_url('admin.php?page=nestwork-options&tab=' . $current_tab); ?>">
                <?php
                wp_nonce_field('mdm_save_settings', 'mdm_settings_nonce');
                
                // Add hidden field for current tab
                echo '<input type="hidden" name="tab" value="' . esc_attr($current_tab) . '" />';
                echo '<input type="hidden" name="action" value="mdm_save_settings" />';
                
                if ($current_tab === 'general') {
                    // General Settings Tab
                    ?>
                    <div id="general-settings" class="mdm-settings-section">
                        <div class="mdm-settings-box">
                            <h2><?php _e('Discount Tiers Configuration', 'membership-discount-manager'); ?></h2>
                            <div class="inside">
                                <?php $this->render_tier_settings(); ?>
                            </div>
                        </div>

                        <div class="mdm-settings-box">
                            <h2><?php _e('Membership Fields Sync', 'membership-discount-manager'); ?></h2>
                            <div class="inside">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php _e('Sync Batch Size', 'membership-discount-manager'); ?></th>
                                        <td>
                                            <input type="number" name="mdm_sync_batch_size" value="<?php echo esc_attr(get_option('mdm_sync_batch_size', 20)); ?>" min="1" max="100" />
                                            <p class="description"><?php _e('Number of users to process in each sync batch (1-100)', 'membership-discount-manager'); ?></p>
                                        </td>
                                    </tr>
                                </table>
                                <?php $this->render_sync_settings(); ?>
                            </div>
                        </div>
                    </div>
                    <?php
                } else if ($current_tab === 'automation') {
                    // Automation Tab
                    ?>
                    <div id="automation-settings" class="mdm-settings-section">
                        <div class="mdm-settings-box">
                            <h2><?php _e('Automatic Calculation Settings', 'membership-discount-manager'); ?></h2>
                            <div class="inside">
                                <?php
                                // Check WordPress Cron Status
                                $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
                                ?>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php _e('Enable Automatic Calculation', 'membership-discount-manager'); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" 
                                                       name="mdm_auto_calc_enabled" 
                                                       value="1" 
                                                       <?php checked(get_option('mdm_auto_calc_enabled', false)); ?> />
                                                <?php _e('Enable automatic tier calculation', 'membership-discount-manager'); ?>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php _e('Calculation Frequency', 'membership-discount-manager'); ?></th>
                                        <td>
                                            <select name="mdm_auto_calc_frequency">
                                                <?php
                                                $current_frequency = get_option('mdm_auto_calc_frequency', 'daily');
                                                $frequencies = array(
                                                    'two_minutes' => __('Every 2 minutes', 'membership-discount-manager'),
                                                    'five_minutes' => __('Every 5 minutes', 'membership-discount-manager'),
                                                    'hourly' => __('Hourly', 'membership-discount-manager'),
                                                    'daily' => __('Daily', 'membership-discount-manager')
                                                );
                                                foreach ($frequencies as $value => $label) {
                                                    printf(
                                                        '<option value="%s" %s>%s</option>',
                                                        esc_attr($value),
                                                        selected($current_frequency, $value, false),
                                                        esc_html($label)
                                                    );
                                                }
                                                ?>
                                            </select>
                                            <?php if ($wp_cron_disabled): ?>
                                            <p class="description">
                                                <?php _e('Note: Since WordPress Cron is disabled, the actual execution frequency depends on your system cron configuration.', 'membership-discount-manager'); ?>
                                            </p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <div class="mdm-settings-box">
                            <h2><?php _e('Last Run Status', 'membership-discount-manager'); ?></h2>
                            <div class="inside">
                                <?php $this->display_last_run_status(); ?>
                            </div>
                        </div>

                        <div class="mdm-settings-box">
                            <h2><?php _e('Cron Status', 'membership-discount-manager'); ?></h2>
                            <div class="inside">
                                <?php
                                // Check WordPress Cron Status
                                $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
                                $cron_status_color = $wp_cron_disabled ? 'orange' : 'green';
                                $cron_status_text = $wp_cron_disabled ? 
                                    __('WordPress Cron is disabled (using system cron)', 'membership-discount-manager') : 
                                    __('WordPress Cron is enabled', 'membership-discount-manager');

                                // Get next scheduled run
                                $next_scheduled = wp_next_scheduled('mdm_auto_calculation');
                                $is_scheduled = $next_scheduled !== false;
                                
                                // Check if automatic calculation is enabled
                                $auto_calc_enabled = get_option('mdm_auto_calc_enabled', false);
                                ?>
                                
                                <table class="widefat striped">
                                    <tr>
                                        <td><strong><?php _e('WordPress Cron:', 'membership-discount-manager'); ?></strong></td>
                                        <td>
                                            <span style="color: <?php echo $cron_status_color; ?>;">
                                                <?php echo esc_html($cron_status_text); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php _e('Automatic Calculation:', 'membership-discount-manager'); ?></strong></td>
                                        <td>
                                            <span style="color: <?php echo $auto_calc_enabled ? 'green' : 'red'; ?>;">
                                                <?php echo $auto_calc_enabled ? 
                                                    esc_html__('Enabled', 'membership-discount-manager') : 
                                                    esc_html__('Disabled', 'membership-discount-manager'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php _e('Next Scheduled Run:', 'membership-discount-manager'); ?></strong></td>
                                        <td>
                                            <?php
                                            if ($is_scheduled) {
                                                echo esc_html(wp_date(
                                                    get_option('date_format') . ' ' . get_option('time_format'), 
                                                    $next_scheduled
                                                ));
                                            } else {
                                                echo '<span style="color: red;">' . 
                                                    esc_html__('Not scheduled', 'membership-discount-manager') . 
                                                    '</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php _e('Frequency:', 'membership-discount-manager'); ?></strong></td>
                                        <td>
                                            <?php 
                                            $frequency = get_option('mdm_auto_calc_frequency', 'daily');
                                            echo esc_html(ucfirst($frequency)); 
                                            ?>
                                        </td>
                                    </tr>
                                </table>

                                <?php if ($wp_cron_disabled): ?>
                                <p class="description" style="margin-top: 10px;">
                                    <?php _e('WordPress Cron is disabled in wp-config.php. Make sure your system cron job is properly configured to trigger wp-cron.php regularly.', 'membership-discount-manager'); ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php
                } else if ($current_tab === 'debug') {
                    // Debug Tab
                    ?>
                    <div id="debug-settings" class="mdm-settings-section">
                        <div class="mdm-settings-box">
                            <h2><?php _e('Profile Fields Status', 'membership-discount-manager'); ?></h2>
                            <div class="inside">
                                <table class="form-table">
                                    <?php
                                    $fields_status = $this->check_profile_fields_exist();
                                    $required_fields = array(
                                        'discount_tier' => 'VIP Status',
                                        'average_spend_last_year' => 'Average spend last year',
                                        'total_spend_all_time' => 'User total spend',
                                        'manual_discount_override' => 'Flag to skip automatic recalculations',
                                        'discount_last_sync' => 'Discount last sync'
                                    );

                                    foreach ($required_fields as $slug => $name) :
                                        $exists = $fields_status[$slug];
                                        $status_icon = $exists ? '✓' : '✗';
                                        $status_color = $exists ? 'green' : 'red';
                                        ?>
                                        <tr>
                                            <th scope="row">
                                                <?php echo esc_html($name); ?>
                                                <br>
                                                <small style="font-weight: normal; color: #666;">
                                                    <?php echo esc_html($slug); ?>
                                                </small>
                                            </th>
                                            <td>
                                                <span style="color: <?php echo $status_color; ?>; font-size: 18px; font-weight: bold;">
                                                    <?php echo $status_icon; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        </div>

                        <div class="mdm-settings-box">
                            <h2><?php _e('Logging Settings', 'membership-discount-manager'); ?></h2>
                            <div class="inside">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php _e('Enable Logging', 'membership-discount-manager'); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="mdm_logging_enabled" value="1" <?php checked(get_option('mdm_logging_enabled', true)); ?> />
                                                <?php _e('Enable debug logging', 'membership-discount-manager'); ?>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php _e('Debug Mode', 'membership-discount-manager'); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="mdm_debug_mode" value="1" <?php checked(get_option('mdm_debug_mode', false)); ?> />
                                                <?php _e('Enable debug mode', 'membership-discount-manager'); ?>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php _e('Clear Logs', 'membership-discount-manager'); ?></th>
                                        <td>
                                            <button type="button" id="mdm-clear-logs" class="button">
                                                <?php _e('Clear Debug Logs', 'membership-discount-manager'); ?>
                                            </button>
                                            <p class="description"><?php _e('Clear all debug logs from the database', 'membership-discount-manager'); ?></p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php
                }
                ?>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render tier settings section
     */
    private function render_tier_settings() {
        $default_settings = array(
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
        );
        
        $tier_settings = get_option('mdm_tier_settings', $default_settings);
        
        if (empty($tier_settings) || !is_array($tier_settings)) {
            $tier_settings = $default_settings;
            update_option('mdm_tier_settings', $default_settings);
        }
        ?>
        <table class="form-table">
            <?php foreach ($tier_settings as $tier => $settings) : ?>
                <tr>
                    <th scope="row"><?php echo esc_html(ucfirst($tier)); ?> <?php _e('Tier', 'membership-discount-manager'); ?></th>
                    <td>
                        <label>
                            <?php _e('Minimum Spend:', 'membership-discount-manager'); ?>
                            <input type="number" name="mdm_tier_settings[<?php echo esc_attr($tier); ?>][min_spend]" 
                                value="<?php echo esc_attr($settings['min_spend']); ?>" step="0.01" min="0" />
                        </label>
                        <label>
                            <?php _e('Discount %:', 'membership-discount-manager'); ?>
                            <input type="number" name="mdm_tier_settings[<?php echo esc_attr($tier); ?>][discount]" 
                                value="<?php echo esc_attr($settings['discount']); ?>" step="0.1" min="0" max="100" />
                        </label>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php
    }

    /**
     * Render sync settings section
     */
    private function render_sync_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Sync Membership Fields', 'membership-discount-manager'); ?></th>
                <td>
                    <button type="button" id="mdm-sync-fields" class="button">
                        <?php _e('Sync Now', 'membership-discount-manager'); ?>
                    </button>
                    <span class="spinner" style="float: none; margin-left: 10px;"></span>
                    <div id="mdm-sync-progress" class="hidden" style="margin-top: 10px;">
                        <div class="mdm-progress-bar">
                            <div class="mdm-progress-fill" style="width: 0%;"></div>
                        </div>
                        <div class="mdm-progress-status"></div>
                    </div>
                    <p class="description"><?php _e('Synchronize membership fields for all users', 'membership-discount-manager'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Handle options page form submission
     */
    public function handle_options_submission() {
        // Check if this is our settings action
        if (!isset($_POST['action']) || $_POST['action'] !== 'mdm_save_settings') {
            return;
        }

        // Verify nonce
        if (!isset($_POST['mdm_settings_nonce']) || !wp_verify_nonce($_POST['mdm_settings_nonce'], 'mdm_save_settings')) {
            wp_die(__('Security check failed', 'membership-discount-manager'));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'membership-discount-manager'));
        }

        // Get current tab
        $current_tab = isset($_POST['tab']) ? sanitize_text_field($_POST['tab']) : 'general';

        // General tab settings
        if ($current_tab === 'general') {
            if (isset($_POST['mdm_tier_settings'])) {
                update_option('mdm_tier_settings', $this->sanitize_tier_settings($_POST['mdm_tier_settings']));
            }
        if (isset($_POST['mdm_sync_batch_size'])) {
                update_option('mdm_sync_batch_size', $this->sanitize_batch_size($_POST['mdm_sync_batch_size']));
            }
        }
        // Automation tab settings
        else if ($current_tab === 'automation') {
            update_option('mdm_auto_calc_enabled', isset($_POST['mdm_auto_calc_enabled']));
            if (isset($_POST['mdm_auto_calc_frequency'])) {
                update_option('mdm_auto_calc_frequency', sanitize_text_field($_POST['mdm_auto_calc_frequency']));
            }
        }
        // Debug tab settings
        else if ($current_tab === 'debug') {
        update_option('mdm_logging_enabled', isset($_POST['mdm_logging_enabled']));
        update_option('mdm_debug_mode', isset($_POST['mdm_debug_mode']));
        }

        // Add settings updated message
        add_settings_error(
            'mdm_messages',
            'mdm_settings_updated',
            __('Settings saved successfully.', 'membership-discount-manager'),
            'updated'
        );

        // Redirect back to the settings page
        wp_redirect(add_query_arg(array(
            'page' => 'nestwork-options',
            'tab' => $current_tab,
            'settings-updated' => 1
        ), admin_url('admin.php')));
        exit;
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
     * Process a batch of users for sync
     *
     * @param string $source Source of the sync request (MANUAL or CRON)
     * @param int $offset Starting offset for user query
     * @param int $batch_size Number of users to process in this batch
     * @return array Stats about the sync process
     */
    public function process_sync_batch($source = 'MANUAL', $offset = 0, $batch_size = 20) {
        global $wpdb;

        // Ensure logger is initialized
        if (!$this->logger) {
            $this->logger = new Logger();
        }

        $batch_size = min(max($batch_size, 1), 100); // Ensure batch size is between 1 and 100

        $this->logger->info("[$source] Starting sync batch", array(
            'offset' => $offset,
            'batch_size' => $batch_size
        ));

        // Get total number of users with orders
        $total_query = "
            SELECT COUNT(DISTINCT cl.user_id) as total
            FROM {$wpdb->prefix}wc_order_stats AS os
            INNER JOIN {$wpdb->prefix}wc_customer_lookup AS cl ON os.customer_id = cl.customer_id
            WHERE os.status = 'wc-completed'
        ";
        $total = $wpdb->get_var($total_query);

        $this->logger->info("[$source] Found total users", array(
            'total_users' => $total
        ));

        // Get batch of users with their spending data
        $users_query = $wpdb->prepare("
            SELECT 
                cl.user_id,
                ROUND(SUM(CASE 
                    WHEN os.date_created >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                    THEN os.total_sales 
                    ELSE 0 
                END), 2) as yearly_spend,
                ROUND(SUM(os.total_sales), 2) as total_spend
            FROM {$wpdb->prefix}wc_order_stats AS os
            INNER JOIN {$wpdb->prefix}wc_customer_lookup AS cl ON os.customer_id = cl.customer_id
            WHERE os.status = 'wc-completed'
            GROUP BY cl.user_id
            LIMIT %d, %d
        ", $offset, $batch_size);

        $users = $wpdb->get_results($users_query);
        $stats = array(
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'total' => $total,
            'current_offset' => $offset,
            'is_complete' => ($offset + $batch_size) >= $total
        );

        foreach ($users as $user) {
            $this->logger->debug("[$source] Processing user", array(
                'user_id' => $user->user_id,
                'yearly_spend' => $user->yearly_spend,
                'total_spend' => $user->total_spend
            ));

            // Update spending data
            update_user_meta($user->user_id, '_wc_memberships_profile_field_average_spend_last_year', $user->yearly_spend);
            update_user_meta($user->user_id, '_wc_memberships_profile_field_total_spend_all_time', $user->total_spend);

            // Skip if manual override is enabled
            $manual_override = get_user_meta($user->user_id, '_wc_memberships_profile_field_manual_discount_override', true);
            if ($manual_override === 'yes') {
                $this->logger->debug("[$source] Skipping user due to manual override", array(
                    'user_id' => $user->user_id
                ));
                $stats['skipped']++;
                continue;
            }

            // Calculate and update tier
            $old_tier = get_user_meta($user->user_id, '_wc_memberships_profile_field_discount_tier', true);
            if ($this->calculate_and_update_user_tier($user->user_id)) {
                $new_tier = get_user_meta($user->user_id, '_wc_memberships_profile_field_discount_tier', true);
                $this->logger->info("[$source] Updated user tier", array(
                    'user_id' => $user->user_id,
                    'old_tier' => $old_tier,
                    'new_tier' => $new_tier,
                    'yearly_spend' => $user->yearly_spend
                ));
                $stats['updated']++;
            }

            $stats['processed']++;
        }

        $this->logger->info("[$source] Completed sync batch", array(
            'processed' => $stats['processed'],
            'updated' => $stats['updated'],
            'skipped' => $stats['skipped'],
            'offset' => $offset,
            'total' => $total
        ));

        return $stats;
    }

    /**
     * AJAX handler for syncing membership fields
     */
    public function ajax_sync_membership_fields() {
        try {
            check_ajax_referer('mdm-admin-nonce', 'nonce');

            if (!current_user_can('manage_woocommerce')) {
                $this->logger->error('Sync attempt with insufficient permissions');
                wp_send_json_error('Insufficient permissions');
                return;
            }

            $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
            $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : get_option('mdm_sync_batch_size', 20);
            $batch_size = min(max($batch_size, 1), 100); // Ensure batch size is between 1 and 100

            $this->logger->info('[MANUAL] Starting sync batch', array(
                'offset' => $offset,
                'batch_size' => $batch_size
            ));

            $stats = $this->process_sync_batch('MANUAL', $offset, $batch_size);
            
            $is_complete = ($offset + $stats['processed'] >= $stats['total']);
            
            $response = array(
                'processed' => $offset + $stats['processed'],
                'total' => $stats['total'],
                'batch_stats' => array(
                    'processed' => $stats['processed'],
                    'updated' => $stats['updated'],
                    'skipped' => $stats['skipped']
                ),
                'is_complete' => $is_complete
            );
            
            if ($is_complete) {
                // Add completion summary
                $response['completion_summary'] = array(
                    'total_processed' => $offset + $stats['processed'],
                    'total_updated' => $stats['updated'],
                    'total_skipped' => $stats['skipped'],
                    'execution_time' => date('H:i:s'),
                    'message' => sprintf(
                        __('Sync completed successfully! Processed %d members (%d updated, %d skipped) at %s', 'membership-discount-manager'),
                        $offset + $stats['processed'],
                        $stats['updated'],
                        $stats['skipped'],
                        current_time('H:i:s')
                    )
                );
                
                // Update last sync time
                update_option('mdm_last_fields_sync', current_time('mysql'));
            }

            wp_send_json_success($response);

        } catch (\Exception $e) {
            $this->logger->error('[MANUAL] Error during sync', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
                throw new \Exception(__('Insufficient permissions to clear logs', 'membership-discount-manager'));
            }

            $result = $this->logger->clear_logs();
            
            if ($result) {
                $this->logger->info('Logs cleared successfully by user');
                wp_send_json_success(array(
                    'message' => __('Debug logs have been cleared successfully.', 'membership-discount-manager')
                ));
            } else {
                throw new \Exception(__('Failed to clear some or all log files', 'membership-discount-manager'));
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to clear logs', array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
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

    /**
     * Calculate and update user tier based on spending
     *
     * @param int $user_id
     * @return bool
     */
    private function calculate_and_update_user_tier($user_id) {
        try {
            $is_cron = defined('DOING_CRON') && DOING_CRON;
            $log_prefix = $is_cron ? '[CRON]' : '[MANUAL]';

            // Get user's spending data
            $yearly_spend = get_user_meta($user_id, '_wc_memberships_profile_field_average_spend_last_year', true);
            $total_spend = get_user_meta($user_id, '_wc_memberships_profile_field_total_spend_all_time', true);

            $this->logger->debug($log_prefix . ' Calculating tier for user', array(
                'user_id' => $user_id,
                'yearly_spend' => $yearly_spend,
                'total_spend' => $total_spend
            ));

            // Get tier settings
            $tier_settings = get_option('mdm_tier_settings', array());
            if (empty($tier_settings)) {
                throw new \Exception('Tier settings not found');
            }

            // Calculate appropriate tier based on yearly spend
            $current_tier = 'None';
            foreach (array('platinum', 'gold', 'silver', 'bronze') as $tier_key) {
                if (isset($tier_settings[$tier_key]) && $yearly_spend >= $tier_settings[$tier_key]['min_spend']) {
                    // Convert first letter to uppercase
                    $current_tier = ucfirst($tier_key);
                    break;
                }
            }

            $old_tier = get_user_meta($user_id, '_wc_memberships_profile_field_discount_tier', true);
            
            // Get user details for logging
            $user = get_user_by('id', $user_id);
            $user_email = $user ? $user->user_email : 'Unknown';

            // Only update if tier has changed (case-insensitive comparison)
            if (strcasecmp($old_tier, $current_tier) !== 0) {
                $this->logger->info($log_prefix . ' Updating user tier', array(
                    'user_id' => $user_id,
                    'user_email' => $user_email,
                    'old_tier' => $old_tier,
                    'new_tier' => $current_tier,
                    'yearly_spend' => $yearly_spend,
                    'total_spend' => $total_spend,
                    'tier_thresholds' => $tier_settings,
                    'update_source' => $is_cron ? 'cron' : 'manual_sync'
                ));

                // Update user's tier
                update_user_meta($user_id, '_wc_memberships_profile_field_discount_tier', $current_tier);
                
                // Update last sync timestamp
                update_user_meta($user_id, '_wc_memberships_profile_field_last_updated', current_time('mysql'));

                return true;
            }

            $this->logger->debug($log_prefix . ' No tier change needed', array(
                'user_id' => $user_id,
                'user_email' => $user_email,
                'current_tier' => $current_tier,
                'yearly_spend' => $yearly_spend,
                'update_source' => $is_cron ? 'cron' : 'manual_sync'
            ));

            return false;

        } catch (\Exception $e) {
            $log_prefix = (defined('DOING_CRON') && DOING_CRON) ? '[ERROR][CRON]' : '[ERROR][MANUAL]';
            $this->logger->error($log_prefix . ' Error calculating user tier', array(
                'user_id' => $user_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'update_source' => (defined('DOING_CRON') && DOING_CRON) ? 'cron' : 'manual_sync'
            ));
            return false;
        }
    }

    /**
     * Display last run status
     */
    private function display_last_run_status() {
        $last_run = get_option('mdm_auto_calc_last_run', false);
        $last_run_stats = get_option('mdm_auto_calc_last_run_stats', array());

        echo '<h3>' . __('Last Run Status', 'membership-discount-manager') . '</h3>';
        echo '<table class="form-table">';
        
        // Last Run Time
        echo '<tr>';
        echo '<th>' . __('Last Run', 'membership-discount-manager') . '</th>';
        echo '<td>';
        if (isset($last_run_stats['last_run'])) {
            echo esc_html($last_run_stats['last_run']);
        } elseif ($last_run) {
            echo esc_html(wp_date('Y-m-d H:i:s', $last_run));
        } else {
            echo __('Never', 'membership-discount-manager');
        }
        echo '</td>';
        echo '</tr>';

        // Current Status
        echo '<tr>';
        echo '<th>' . __('Status', 'membership-discount-manager') . '</th>';
        echo '<td>';
        if (isset($last_run_stats['is_running']) && $last_run_stats['is_running']) {
            echo '<span style="color: blue;">' . __('Running', 'membership-discount-manager') . '</span>';
            if (isset($last_run_stats['progress'])) {
                echo ' (' . esc_html($last_run_stats['progress']) . '%)';
            }
        } else {
            echo '<span style="color: green;">' . __('Completed', 'membership-discount-manager') . '</span>';
        }
        echo '</td>';
        echo '</tr>';

        // Records Processed
        if (isset($last_run_stats['records_processed'])) {
            echo '<tr>';
            echo '<th>' . __('Records Processed', 'membership-discount-manager') . '</th>';
            echo '<td>' . esc_html($last_run_stats['records_processed']) . '</td>';
            echo '</tr>';
        }

        // Records Updated
        if (isset($last_run_stats['records_updated'])) {
            echo '<tr>';
            echo '<th>' . __('Records Updated', 'membership-discount-manager') . '</th>';
            echo '<td>' . esc_html($last_run_stats['records_updated']) . '</td>';
            echo '</tr>';
        }

        // Records Skipped
        if (isset($last_run_stats['records_skipped'])) {
            echo '<tr>';
            echo '<th>' . __('Records Skipped', 'membership-discount-manager') . '</th>';
            echo '<td>' . esc_html($last_run_stats['records_skipped']) . '</td>';
            echo '</tr>';
        }

        // Execution Time
        if (isset($last_run_stats['execution_time'])) {
            echo '<tr>';
            echo '<th>' . __('Execution Time', 'membership-discount-manager') . '</th>';
            echo '<td>' . esc_html($last_run_stats['execution_time']) . ' ' . __('seconds', 'membership-discount-manager') . '</td>';
            echo '</tr>';
        }

        // Error Message (if any)
        if (isset($last_run_stats['error']) && $last_run_stats['error']) {
            echo '<tr>';
            echo '<th>' . __('Error', 'membership-discount-manager') . '</th>';
            echo '<td class="error">' . esc_html($last_run_stats['error_message']) . '</td>';
            echo '</tr>';
        }

        // Next Scheduled Run
        $next_run = wp_next_scheduled('mdm_auto_calculation');
        echo '<tr>';
        echo '<th>' . __('Next Scheduled Run', 'membership-discount-manager') . '</th>';
        echo '<td>';
        if ($next_run) {
            echo esc_html(wp_date('Y-m-d H:i:s', $next_run));
        } else {
            echo __('Not scheduled', 'membership-discount-manager');
        }
        echo '</td>';
        echo '</tr>';

        echo '</table>';
    }

    /**
     * Run automatic calculation via cron
     */
    public function run_auto_calculation() {
        try {
            if (!get_option('mdm_auto_calc_enabled', false)) {
                $this->logger->info('[CRON] Auto calculation is disabled');
                return;
            }

            $this->logger->info('[CRON] Starting automatic calculation');

            // Get batch size from settings
            $batch_size = get_option('mdm_sync_batch_size', 20);
            $offset = 0;
            $total_processed = 0;
            $total_updated = 0;
            $total_skipped = 0;

            // Track execution time
            $start_time = microtime(true);

            // Update running status
            update_option('mdm_auto_calc_last_run_stats', array(
                'last_run' => current_time('mysql'),
                'is_running' => true,
                'progress' => 0
            ));

            do {
                $stats = $this->process_sync_batch('CRON', $offset, $batch_size);
                
                $total_processed += $stats['processed'];
                $total_updated += $stats['updated'];
                $total_skipped += $stats['skipped'];
                
                // Update progress
                $progress = min(100, round(($offset + $stats['processed']) / $stats['total'] * 100));
                update_option('mdm_auto_calc_last_run_stats', array(
                    'last_run' => current_time('mysql'),
                    'is_running' => true,
                    'progress' => $progress,
                    'records_processed' => $total_processed,
                    'records_updated' => $total_updated,
                    'records_skipped' => $total_skipped
                ));

                $offset += $batch_size;
            } while (!$stats['is_complete']);

            $execution_time = microtime(true) - $start_time;

            // Update final stats
            update_option('mdm_auto_calc_last_run', time());
            update_option('mdm_auto_calc_last_run_stats', array(
                'last_run' => current_time('mysql'),
                'is_running' => false,
                'progress' => 100,
                'records_processed' => $total_processed,
                'records_updated' => $total_updated,
                'records_skipped' => $total_skipped,
                'execution_time' => round($execution_time, 2),
                'error' => false
            ));

            $this->logger->info('[CRON] Completed automatic calculation', array(
                'total_processed' => $total_processed,
                'total_updated' => $total_updated,
                'total_skipped' => $total_skipped,
                'execution_time' => round($execution_time, 2)
            ));

        } catch (\Exception $e) {
            $this->logger->error('[CRON] Error during automatic calculation', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));

            // Update error status
            update_option('mdm_auto_calc_last_run_stats', array(
                'last_run' => current_time('mysql'),
                'is_running' => false,
                'error' => true,
                'error_message' => $e->getMessage()
            ));
        }
    }
} 