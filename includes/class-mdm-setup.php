<?php
namespace MembershipDiscountManager;

class Setup {
    /**
     * Logger instance
     *
     * @var Logger
     */
    private $logger;

    /**
     * Discount Handler instance
     *
     * @var Discount_Handler
     */
    private $discount_handler;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new Logger();
        
        // Initialize after WooCommerce is fully loaded
        \add_action('woocommerce_init', array($this, 'init'), 20);
        
        // Only add late_init if we're in a frontend request or AJAX
        if (!\is_admin() || \wp_doing_ajax()) {
            \add_action('wp_loaded', array($this, 'late_init'), 20);
        }
    }

    /**
     * Initialize the setup
     */
    public function init() {
        static $initialized = false;

        // Prevent multiple initializations
        if ($initialized) {
            return;
        }

        // Check if WooCommerce is active
        if (!\class_exists('WooCommerce')) {
            $this->logger->error('WooCommerce not active');
            return;
        }

        // Check if WooCommerce Memberships is active
        if (!\class_exists('WC_Memberships')) {
            return;
        }

        // Register activation/deactivation hooks
        \register_activation_hook(MDM_PLUGIN_FILE, array($this, 'activate'));
        \register_deactivation_hook(MDM_PLUGIN_FILE, array($this, 'deactivate'));

        // Register cron hook
        \add_action('mdm_daily_sync_hook', array($this, 'run_daily_sync'));

        // Register WooCommerce order hooks
        \add_action('woocommerce_order_status_completed', array($this, 'sync_completed_order'));

        // Initialize discount handler for front-end or AJAX requests
        if (!\is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            $this->init_discount_handler();
        }

        $initialized = true;
    }

    /**
     * Late initialization
     */
    public function late_init() {
        // Use transient to prevent multiple initializations within a short time period
        $init_lock = get_transient('mdm_late_init_lock');
        if ($init_lock) {
            return;
        }
        
        // Set a transient lock for 10 seconds
        set_transient('mdm_late_init_lock', true, 10);

        if (!\function_exists('WC')) {
            return;
        }

        // Only log late init in debug mode and if something changed
        if (get_option('mdm_debug_mode', false)) {
            $current_state = array(
                'is_checkout' => \function_exists('is_checkout') ? \is_checkout() : false,
                'is_cart' => \function_exists('is_cart') ? \is_cart() : false,
                'user_id' => \get_current_user_id(),
                'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''
            );

            $last_state = get_transient('mdm_late_init_state');
            if ($last_state !== $current_state) {
                $this->logger->info('MDM Late init started', $current_state);
                set_transient('mdm_late_init_state', $current_state, 60);
            }
        }

        // Reinitialize discount handler if needed
        if (($this->is_cart_or_checkout() || $this->is_ajax_cart_update()) && !$this->discount_handler) {
            $this->init_discount_handler();
        }
    }

    /**
     * Initialize the discount handler
     */
    private function init_discount_handler() {
        // Use transient to prevent multiple initializations
        $handler_lock = get_transient('mdm_discount_handler_lock');
        if ($handler_lock) {
            return;
        }

        if (!$this->discount_handler && \function_exists('WC')) {
            $this->discount_handler = new Discount_Handler();
            
            // Let the Discount_Handler initialize its own hooks
            $this->discount_handler->init_hooks();

            // Set a transient lock for 10 seconds
            set_transient('mdm_discount_handler_lock', true, 10);
        }
    }

    /**
     * Check if current page is cart or checkout
     */
    private function is_cart_or_checkout() {
        return \function_exists('is_checkout') && \function_exists('WC') && (\is_checkout() || \is_cart());
    }

    /**
     * Check if this is an AJAX cart update
     */
    private function is_ajax_cart_update() {
        return defined('DOING_AJAX') && DOING_AJAX && 
               isset($_REQUEST['wc-ajax']) && 
               in_array($_REQUEST['wc-ajax'], array('update_order_review', 'update_shipping_method', 'apply_coupon'));
    }

    /**
     * Plugin activation
     */
    public function activate() {
        $this->logger->info('Activating MDM plugin');
        
        // Schedule daily sync if not already scheduled
        if (!\wp_next_scheduled('mdm_daily_sync_hook')) {
            \wp_schedule_event(time(), 'daily', 'mdm_daily_sync_hook');
            $this->logger->debug('Daily sync scheduled');
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        $this->logger->info('Deactivating MDM plugin');
        
        // Remove scheduled sync
        \wp_clear_scheduled_hook('mdm_daily_sync_hook');
        $this->logger->debug('Daily sync unscheduled');
    }

    /**
     * Run the daily sync
     */
    public function run_daily_sync() {
        $this->logger->info('Starting daily sync');
        
        global $mdm_admin;
        
        if ($mdm_admin) {
            $mdm_admin->sync_all_users();
            $this->logger->info('Daily sync completed');
        } else {
            $this->logger->error('Daily sync failed: Admin instance not available');
        }
    }

    /**
     * Sync user data when order is completed
     * 
     * @param int $order_id
     */
    public function sync_completed_order($order_id) {
        $this->logger->info('Processing completed order', ['order_id' => $order_id]);
        
        global $mdm_admin;
        
        if ($mdm_admin) {
            $mdm_admin->sync_order_user($order_id);
            $this->logger->info('Order sync completed', ['order_id' => $order_id]);
        } else {
            $this->logger->error('Order sync failed: Admin instance not available', ['order_id' => $order_id]);
        }
    }
} 