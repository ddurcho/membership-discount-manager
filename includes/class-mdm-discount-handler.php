<?php
namespace MembershipDiscountManager;

/**
 * Handles discount calculations and application during checkout
 */
class Discount_Handler {
    /**
     * Logger instance
     *
     * @var Logger
     */
    private $logger;

    /**
     * @var bool Initialization flag
     */
    private static $initialized = false;

    /**
     * @var array Cache for user discounts
     */
    private static $user_discount_cache = [];

    /**
     * Constructor
     */
    public function __construct() {
        if (self::$initialized) {
            return;
        }

        $this->logger = new Logger();
        
        // Register our hooks after WooCommerce is loaded
        if (did_action('woocommerce_init')) {
            $this->init_hooks();
        } else {
            add_action('woocommerce_init', array($this, 'init_hooks'));
        }
    }

    /**
     * Initialize WooCommerce hooks
     */
    public function init_hooks() {
        if (self::$initialized) {
            return;
        }

        // Cart calculation hooks - only use add_discount_fee for consistency
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_discount_fee'), 99);
        
        // Coupon restriction hooks
        add_filter('woocommerce_coupon_is_valid', array($this, 'validate_coupon_for_loyalty'), 10, 2);
        add_action('woocommerce_before_cart', array($this, 'check_and_remove_coupons'));
        add_action('woocommerce_before_checkout_form', array($this, 'check_and_remove_coupons'));
        
        // Cart change detection
        add_action('woocommerce_cart_item_removed', array($this, 'clear_discount_notices'));
        add_action('woocommerce_add_to_cart', array($this, 'clear_discount_notices'));
        add_action('woocommerce_cart_emptied', array($this, 'clear_discount_notices'));
        
        // CartFlows compatibility
        if (class_exists('\\CartFlows\\Core')) {
            add_action('cartflows_checkout_before_calculate_totals', array($this, 'add_discount_fee'), 99);
        }

        self::$initialized = true;
    }

    /**
     * Early checkout debug
     */
    public function early_checkout_debug() {
        // Only proceed if debug mode is enabled
        if (!get_option('mdm_debug_mode', false)) {
            return;
        }

        // Use transient to prevent multiple debug logs within a short time period
        $debug_lock = get_transient('mdm_early_checkout_debug_lock');
        if ($debug_lock) {
            return;
        }

        // Set a transient lock for 5 seconds
        set_transient('mdm_early_checkout_debug_lock', true, 5);

        // Collect current state
        $current_state = array(
            'is_checkout' => is_checkout(),
            'is_cart' => is_cart(),
            'user_id' => get_current_user_id(),
            'request_uri' => $_SERVER['REQUEST_URI'],
            'wc_loaded' => defined('WC_LOADED'),
            'cartflows_active' => class_exists('\\CartFlows\\Core')
        );

        // Only log if state has changed
        $last_state = get_transient('mdm_early_checkout_state');
        if ($last_state !== $current_state) {
            $this->logger->info('🔍 MDM: Early Checkout Debug', $current_state);
            set_transient('mdm_early_checkout_state', $current_state, 60);
        }
    }

    /**
     * Debug discount setup
     */
    public function debug_discount_setup() {
        $user_id = get_current_user_id();
        
        // Get only necessary user meta fields
        $relevant_meta = array(
            'discount_tier' => get_user_meta($user_id, '_wc_memberships_profile_field_discount_tier', true),
            'average_spend' => get_user_meta($user_id, '_wc_memberships_profile_field_average_spend_last_year', true),
            'total_spend' => get_user_meta($user_id, '_wc_memberships_profile_field_total_spend_all_time', true),
            'manual_override' => get_user_meta($user_id, '_wc_memberships_profile_field_manual_discount_override', true)
        );

        // $this->logger->debug('Debugging discount setup', [
        //     'user_id' => $user_id,
        //     'is_user_logged_in' => is_user_logged_in(),
        //     'wc_session_exists' => isset(WC()->session),
        //     'wc_cart_exists' => isset(WC()->cart),
        //     'relevant_meta' => $relevant_meta,
        //     'tier_settings' => get_option('mdm_tier_settings')
        // ]);
    }

    /**
     * Debug checkout discount information
     */
    public function debug_checkout_discount() {
        error_log('💡 MDM: Debug Checkout Discount Called');
        $user_id = get_current_user_id();
        $is_cartflows = class_exists('\\CartFlows\\Core');
        $is_cartflows_checkout = function_exists('wcf_is_checkout_page') ? wcf_is_checkout_page() : false;

        // Get only necessary user meta fields
        $relevant_meta = array(
            'discount_tier' => get_user_meta($user_id, '_wc_memberships_profile_field_discount_tier', true),
            'manual_override' => get_user_meta($user_id, '_wc_memberships_profile_field_manual_discount_override', true)
        );

        $this->logger->info('🔍 Checkout Page Discount Debug', [
            'timestamp' => current_time('mysql'),
            'user_id' => $user_id,
            'is_user_logged_in' => is_user_logged_in(),
            'is_cartflows_active' => $is_cartflows,
            'is_cartflows_checkout' => $is_cartflows_checkout,
            'current_page' => $_SERVER['REQUEST_URI'],
            'discount_tier' => $relevant_meta['discount_tier'],
            'cart_total' => WC()->cart ? WC()->cart->get_total() : 'No cart',
            'session_discount' => WC()->session ? WC()->session->get('mdm_discount_info') : 'No session'
        ]);

        // Check WooCommerce Memberships status
        if (function_exists('wc_memberships_get_user_active_memberships')) {
            $memberships = wc_memberships_get_user_active_memberships($user_id);
            $membership_info = [];
            
            if (!empty($memberships)) {
                foreach ($memberships as $membership) {
                    $membership_info[] = [
                        'plan_name' => $membership->get_plan()->get_name(),
                        'status' => $membership->get_status(),
                        'start_date' => $membership->get_start_date(),
                        'end_date' => $membership->get_end_date()
                    ];
                }
            }
            
            // $this->logger->info('🏷️ User Membership Status', [
            //     'user_id' => $user_id,
            //     'has_memberships' => !empty($memberships),
            //     'membership_details' => $membership_info
            // ]);
        }

        // Debug discount calculation
        $discount_info = $this->get_user_discount($user_id);
        // $this->logger->info('💰 Current Discount Calculation', [
        //     'discount_info' => $discount_info ? [
        //         'tier' => $discount_info['tier'],
        //         'percentage' => $discount_info['percentage']
        //     ] : null,
        //     'cart_subtotal' => WC()->cart ? WC()->cart->get_subtotal() : 'No cart',
        //     'calculated_discount' => $discount_info ? ($discount_info['percentage'] . '%') : 'No discount'
        // ]);
    }

    /**
     * Get user's discount information with caching
     *
     * @param int $user_id
     * @return array|false
     */
    private function get_user_discount($user_id) {
        // Check static cache first
        if (isset(self::$user_discount_cache[$user_id])) {
            return self::$user_discount_cache[$user_id];
        }

        // Check transient cache
        $cache_key = 'mdm_user_discount_' . $user_id;
        $cached_discount = get_transient($cache_key);
        if ($cached_discount !== false) {
            self::$user_discount_cache[$user_id] = $cached_discount;
            return $cached_discount;
        }

        $discount_tier = get_user_meta($user_id, '_wc_memberships_profile_field_discount_tier', true);
        if (!$discount_tier || $discount_tier === 'None') {
            self::$user_discount_cache[$user_id] = false;
            set_transient($cache_key, false, HOUR_IN_SECONDS);
            return false;
        }

        $available_tiers = array(
            'none' => 0,
            'bronze' => 5,
            'silver' => 10,
            'gold' => 15,
            'platinum' => 20
        );

        $tier_key = strtolower($discount_tier);
        if (!isset($available_tiers[$tier_key])) {
            self::$user_discount_cache[$user_id] = false;
            set_transient($cache_key, false, HOUR_IN_SECONDS);
            return false;
        }

        $discount_info = array(
            'tier' => ucfirst($tier_key),
            'percentage' => floatval($available_tiers[$tier_key])
        );

        // Cache the result
        self::$user_discount_cache[$user_id] = $discount_info;
        set_transient($cache_key, $discount_info, HOUR_IN_SECONDS);

        return $discount_info;
    }

    /**
     * Disable coupons completely when loyalty discount is applicable
     */
    public function disable_coupons_for_loyalty($enabled) {
        return $this->should_block_coupons() ? false : $enabled;
    }

    /**
     * Block shop coupon data to prevent coupon display
     */
    public function block_shop_coupon_data($data, $coupon, $code) {
        return $this->should_block_coupons() ? false : $data;
    }

    /**
     * Hide coupon HTML in cart
     */
    public function hide_coupon_html($coupon_html, $coupon, $discount_amount_html) {
        return $this->should_block_coupons() ? '' : $coupon_html;
    }

    /**
     * Force remove all coupons if loyalty discount is applicable
     */
    public function force_remove_coupons() {
        if (!is_object(WC()->cart) || !method_exists(WC()->cart, 'get_cart')) {
            return;
        }

        if ($this->should_block_coupons()) {
            // Remove all applied coupons
            WC()->cart->remove_coupons();
            
            // Clear session data related to coupons
            WC()->session->set('applied_coupons', array());
            WC()->session->set('coupon_discount_totals', array());
            WC()->session->set('coupon_discount_tax_totals', array());
            
            // Remove any stored coupon data
            delete_option('woocommerce_cart_coupon_data');
            
            // Force cart update
            WC()->cart->calculate_totals();

            // Clear existing notices and add new one
            $this->clear_discount_notices();
            
            $user_id = get_current_user_id();
            $discount_info = $this->get_user_discount($user_id);
            wc_add_notice(
                sprintf(
                    __('Coupons cannot be used with Loyalty %s Tier Discount (%s%%).', 'membership-discount-manager'),
                    $discount_info['tier'],
                    $discount_info['percentage']
                ),
                'notice'
            );
        }
    }

    /**
     * Validate if coupon can be used with loyalty discount
     */
    public function validate_coupon_for_loyalty($valid, $coupon) {
        if (!$valid) {
            return $valid;
        }

        if ($this->should_block_coupons()) {
            // Clear any existing notices first
            $this->clear_discount_notices();
            
            wc_add_notice(
                sprintf(
                    __('Coupons cannot be used when Loyalty Discount is enabled for any product in your cart.', 'membership-discount-manager'),
                ),
                'error'
            );
            return false;
        }

        return $valid;
    }

    /**
     * Display discount information
     */
    public function display_discount_info() {
        // Disable duplicate discount display since it's already shown as a fee
        return;
    }

    /**
     * Add discount information to order details
     *
     * @param array $total_rows
     * @param WC_Order $order
     * @return array
     */
    public function add_discount_info_to_order($total_rows, $order) {
        $discount_info = $order->get_meta('_mdm_discount_info');
        $total_discount = $order->get_discount_total();

        if ($discount_info && $total_discount > 0) {
            $new_rows = array();

            foreach ($total_rows as $key => $row) {
                if ($key === 'cart_subtotal') {
                    $new_rows[$key] = $row;
                    $new_rows['discount'] = array(
                        'label' => sprintf(
                            __('Loyalty %s Tier Discount (%s%%)', 'membership-discount-manager'),
                            $discount_info['tier'],
                            $discount_info['percentage']
                        ),
                        'value' => '-' . wc_price($total_discount)
                    );
                } else {
                    $new_rows[$key] = $row;
                }
            }

            return $new_rows;
        }

        return $total_rows;
    }

    /**
     * Refresh discount calculations when cart is updated
     */
    public function refresh_discount_calculations() {
        if (!WC()->cart->is_empty()) {
            $this->logger->debug('Refreshing discount calculations due to cart update');
            $user_id = get_current_user_id();
            $discount_info = $this->get_user_discount($user_id);
            
            if ($discount_info) {
                WC()->session->set('mdm_discount_info', $discount_info);
                WC()->cart->calculate_totals();
            }
        }
    }

    /**
     * Modify product price for display and calculations
     *
     * @param float $price
     * @param WC_Product $product
     * @return float
     */
    public function modify_product_price($price, $product) {
        // Return original price - we'll handle discounts through cart fees
        return $price;
    }

    /**
     * Remove all coupons from cart totals
     */
    public function remove_all_coupons($coupons) {
        if ($this->should_block_coupons()) {
            return array();
        }
        return $coupons;
    }

    /**
     * Check if coupons should be blocked
     */
    private function should_block_coupons() {
        if (!is_object(WC()->cart) || !method_exists(WC()->cart, 'get_cart')) {
            return false;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return false;
        }

        $discount_info = $this->get_user_discount($user_id);
        if (!$discount_info) {
            return false;
        }

        // Check if any products have loyalty discount enabled
        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!empty($cart_item['data'])) {
                $product = $cart_item['data'];
                $is_enabled = Product::is_discount_enabled($product);
                error_log(sprintf(
                    'MDM Debug - Product %d loyalty discount enabled: %s',
                    $product->get_id(),
                    $is_enabled ? 'yes' : 'no'
                ));
                if ($is_enabled) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check and remove any applied coupons for loyalty members
     */
    public function check_and_remove_coupons() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        $discount_info = $this->get_user_discount($user_id);
        if (!$discount_info) {
            return;
        }

        // Check if any products have loyalty discount enabled
        $has_loyalty_products = false;
        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!empty($cart_item['data']) && Product::is_discount_enabled($cart_item['data'])) {
                $has_loyalty_products = true;
                break;
            }
        }

        // If we have loyalty-enabled products, remove all coupons
        if ($has_loyalty_products && !empty(WC()->cart->get_applied_coupons())) {
            WC()->cart->remove_coupons();
            wc_add_notice(
                sprintf(
                    __('Coupons cannot be used with Loyalty %s Tier Discount (%s%%).', 'membership-discount-manager'),
                    $discount_info['tier'],
                    $discount_info['percentage']
                ),
                'notice'
            );
        }
    }

    /**
     * Add discount fee to cart
     *
     * @param WC_Cart $cart
     */
    public function add_discount_fee($cart) {
        static $is_calculating = false;
        
        if ($is_calculating || !is_object($cart) || !method_exists($cart, 'is_empty') || $cart->is_empty()) {
            return;
        }

        try {
            $is_calculating = true;

            // Clear any existing notices when recalculating
            $this->clear_discount_notices();

            $user_id = get_current_user_id();
            if (!$user_id) {
                return;
            }

            $discount_info = $this->get_user_discount($user_id);
            if (!$discount_info) {
                return;
            }

            // Calculate eligible subtotal
            $eligible_subtotal = 0;
            $use_net_total = get_option('mdm_use_net_total', true);

            foreach ($cart->get_cart() as $cart_item) {
                if (empty($cart_item['data'])) {
                    continue;
                }

                $product = $cart_item['data'];
                if (!Product::is_discount_enabled($product)) {
                    continue;
                }

                // Get the base price
                $base_price = $product->get_price();
                
                // Calculate price with quantity
                if ($use_net_total) {
                    $price = wc_get_price_excluding_tax($product, array(
                        'qty' => $cart_item['quantity'],
                        'price' => $base_price
                    ));
                } else {
                    $price = wc_get_price_including_tax($product, array(
                        'qty' => $cart_item['quantity'],
                        'price' => $base_price
                    ));
                }
                
                $eligible_subtotal += $price;
            }

            if ($eligible_subtotal <= 0) {
                return;
            }

            // Calculate discount amount
            $discount_amount = $eligible_subtotal * ($discount_info['percentage'] / 100);
            
            // Store in session for later use
            WC()->session->set('mdm_discount_info', array(
                'tier' => $discount_info['tier'],
                'percentage' => $discount_info['percentage'],
                'amount' => $discount_amount,
                'eligible_subtotal' => $eligible_subtotal
            ));

            // Force remove any coupons before adding the discount
            $this->check_and_remove_coupons();

            // Add the discount as a negative fee
            $cart->add_fee(
                sprintf(
                    __('Loyalty %s Tier Discount (%s%%)', 'membership-discount-manager'),
                    $discount_info['tier'],
                    $discount_info['percentage']
                ),
                -$discount_amount,
                true // Is taxable
            );

        } catch (\Exception $e) {
            error_log('MDM Error: ' . $e->getMessage());
        } finally {
            $is_calculating = false;
        }
    }

    /**
     * Clear discount-related notices
     */
    public function clear_discount_notices() {
        $notices = wc_get_notices();
        
        // Remove our specific notices
        if (!empty($notices)) {
            foreach (['error', 'notice', 'success'] as $notice_type) {
                if (isset($notices[$notice_type])) {
                    foreach ($notices[$notice_type] as $key => $notice) {
                        // Check if the notice contains our specific messages
                        if (strpos($notice['notice'], 'Loyalty') !== false && 
                            (strpos($notice['notice'], 'Discount') !== false || 
                             strpos($notice['notice'], 'Coupons') !== false)) {
                            unset($notices[$notice_type][$key]);
                        }
                    }
                }
            }
            wc_set_notices($notices);
        }

        // Clear our static flags
        static $notice_shown = false;
        $notice_shown = false;
    }
} 