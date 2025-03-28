<?php
namespace MembershipDiscountManager;

/**
 * The core plugin class
 */
class Core {
    /**
     * The single instance of the class
     *
     * @var Core
     */
    protected static $_instance = null;

    /**
     * Discount Manager instance
     *
     * @var DiscountManager
     */
    protected $discount_manager;

    /**
     * Admin instance
     *
     * @var Admin
     */
    protected $admin;

    /**
     * Main Core Instance
     *
     * Ensures only one instance of Core is loaded or can be loaded.
     *
     * @return Core - Main instance
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Core Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init_hooks'));
    }

    /**
     * Initialize WordPress hooks
     */
    public function init_hooks() {
        // Initialize components first
        $this->init_components();
        
        // Schedule daily discount tier updates
        if (!wp_next_scheduled('mdm_daily_tier_update')) {
            wp_schedule_event(time(), 'daily', 'mdm_daily_tier_update');
        }
        add_action('mdm_daily_tier_update', array($this, 'update_all_member_tiers'));
    }

    /**
     * Initialize plugin components
     */
    private function init_components() {
        // Initialize Discount Manager
        $this->discount_manager = new DiscountManager();

        // Initialize Admin if in admin area
        if (is_admin()) {
            $this->admin = new Admin();
        }
    }

    /**
     * Update tiers for all members
     */
    public function update_all_member_tiers() {
        $this->discount_manager->update_all_member_tiers();
    }
} 