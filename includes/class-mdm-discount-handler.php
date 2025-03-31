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
     * Static flag to track initialization
     * @var bool
     */
    private static $initialized = false;

    /**
     * Constructor
     */
    public function __construct() {
        // Prevent duplicate initialization
        if (self::$initialized) {
            return;
        }

        // Initialize logger
        $this->logger = new Logger();
        
        // Early debug logging only if debug mode is enabled
        if (get_option('mdm_debug_mode', false)) {
            $this->logger->debug('🚀 MDM: Discount Handler Constructor Called');
        }
        
        // Register our hooks after WooCommerce is loaded
        if (did_action('woocommerce_init')) {
            $this->init_hooks();
            $this->debug_discount_setup();
        } else {
            add_action('woocommerce_init', array($this, 'init_hooks'), 1);
            add_action('woocommerce_init', array($this, 'debug_discount_setup'), 1);
        }

        // Add early checkout hook
        add_action('wp', array($this, 'early_checkout_debug'));

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
     * Initialize WooCommerce hooks
     */
    public function init_hooks() {
        error_log('⚡ MDM: Init Hooks Called');
        //$this->logger->info('⚡ MDM: Initializing Hooks');

        // Cart calculation hooks
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_discount_fee'), 99);
        
        // Display hooks
        add_action('woocommerce_cart_totals_before_order_total', array($this, 'display_discount_info'), 99);
        add_action('woocommerce_review_order_before_order_total', array($this, 'display_discount_info'), 99);
        
        // Coupon restriction hooks
        add_filter('woocommerce_coupon_is_valid', array($this, 'validate_coupon_for_vip'), 10, 2);
        add_action('woocommerce_before_cart', array($this, 'check_and_remove_coupons'));
        add_action('woocommerce_before_checkout_form', array($this, 'check_and_remove_coupons'));
        
        // CartFlows compatibility
        if (class_exists('\\CartFlows\\Core')) {
            add_action('cartflows_checkout_before_calculate_totals', array($this, 'add_discount_fee'), 99);
        }

        //$this->logger->info('⚡ MDM: Hooks Initialized');
    }

    /**
     * Initialize WooCommerce Blocks support
     */
    public function init_blocks_support() {
        $this->logger->debug('Initializing WooCommerce Blocks support');
        
        if (!function_exists('woocommerce_store_api_register_update_callback')) {
            $this->logger->error('WooCommerce Blocks API not available');
            return;
        }

        woocommerce_store_api_register_update_callback([
            'namespace' => 'membership-discount-manager',
            'callback' => function($cart_data) {
                if (!is_null(WC()->cart)) {
                    $this->apply_discount_to_cart(WC()->cart);
                }
                return $cart_data;
            }
        ]);
    }

    /**
     * Adjust price display in shop and product pages
     *
     * @param string $price_html
     * @param WC_Product $product
     * @return string
     */
    public function adjust_price_display($price_html, $product) {
        $user_id = get_current_user_id();
        $discount_info = $this->get_user_discount($user_id);

        if (!$discount_info) {
            return $price_html;
        }

        // Check if loyalty discount is enabled for this product
        if (!Product::is_discount_enabled($product)) {
            $this->logger->debug('Not adjusting price display - loyalty discount not enabled', [
                'product_id' => $product->get_id(),
                'product_name' => $product->get_name()
            ]);
            return $price_html;
        }

        $original_price = $product->get_price();
        $discount_amount = ($original_price * $discount_info['percentage']) / 100;
        $discounted_price = $original_price - $discount_amount;

        $this->logger->debug('Adjusting product price display', [
            'product_id' => $product->get_id(),
            'product_name' => $product->get_name(),
            'original_price' => $original_price,
            'discount_percentage' => $discount_info['percentage'],
            'discounted_price' => $discounted_price
        ]);

        return sprintf(
            '<del>%s</del> <ins>%s</ins> <span class="discount-badge">%s%% OFF</span>',
            wc_price($original_price),
            wc_price($discounted_price),
            $discount_info['percentage']
        );
    }

    /**
     * Adjust the final cart total
     *
     * @param float $total
     * @param WC_Cart $cart
     * @return float
     */
    public function adjust_cart_total($total, $cart) {
        $user_id = get_current_user_id();
        $discount_info = $this->get_user_discount($user_id);

        if (!$discount_info) {
            return $total;
        }

        $discount_amount = ($total * $discount_info['percentage']) / 100;
        $final_total = $total - $discount_amount;

        $this->logger->debug('Adjusting cart total', [
            'original_total' => $total,
            'discount_percentage' => $discount_info['percentage'],
            'discount_amount' => $discount_amount,
            'final_total' => $final_total
        ]);

        // Store for display
        WC()->session->set('mdm_total_discount', $discount_amount);

        return $final_total;
    }

    /**
     * Adjust the displayed price for cart items
     *
     * @param string $price_html
     * @param array $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function adjust_cart_item_price($price_html, $cart_item, $cart_item_key) {
        $user_id = get_current_user_id();
        $discount_info = $this->get_user_discount($user_id);

        if (!$discount_info) {
            return $price_html;
        }

        $product = $cart_item['data'];

        // Check if loyalty discount is enabled for this product
        if (!Product::is_discount_enabled($product)) {
            $this->logger->debug('Not adjusting cart item price - loyalty discount not enabled', [
                'product_id' => $product->get_id(),
                'product_name' => $product->get_name()
            ]);
            return $price_html;
        }

        $original_price = $product->get_price();
        $discount_amount = ($original_price * $discount_info['percentage']) / 100;
        $discounted_price = $original_price - $discount_amount;

        $this->logger->debug('Adjusting cart item price', [
            'product_id' => $product->get_id(),
            'product_name' => $product->get_name(),
            'original_price' => $original_price,
            'discount_percentage' => $discount_info['percentage'],
            'discounted_price' => $discounted_price
        ]);

        // Show both original and discounted price
        return sprintf(
            '<del>%s</del> <ins>%s</ins>',
            wc_price($original_price),
            wc_price($discounted_price)
        );
    }

    /**
     * Adjust the subtotal display for cart items
     *
     * @param string $subtotal
     * @param array $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function adjust_cart_item_subtotal($subtotal, $cart_item, $cart_item_key) {
        $user_id = get_current_user_id();
        $discount_info = $this->get_user_discount($user_id);

        if (!$discount_info) {
            return $subtotal;
        }

        $product = $cart_item['data'];

        // Check if loyalty discount is enabled for this product
        if (!Product::is_discount_enabled($product)) {
            $this->logger->debug('Not adjusting cart item subtotal - loyalty discount not enabled', [
                'product_id' => $product->get_id(),
                'product_name' => $product->get_name()
            ]);
            return $subtotal;
        }

        $quantity = $cart_item['quantity'];
        $original_price = $product->get_price();
        $original_subtotal = $original_price * $quantity;
        $discount_amount = ($original_subtotal * $discount_info['percentage']) / 100;
        $discounted_subtotal = $original_subtotal - $discount_amount;

        $this->logger->debug('Adjusting cart item subtotal', [
            'product_id' => $product->get_id(),
            'product_name' => $product->get_name(),
            'quantity' => $quantity,
            'original_subtotal' => $original_subtotal,
            'discount_percentage' => $discount_info['percentage'],
            'discounted_subtotal' => $discounted_subtotal
        ]);

        return sprintf(
            '<del>%s</del> <ins>%s</ins>',
            wc_price($original_subtotal),
            wc_price($discounted_subtotal)
        );
    }

    /**
     * Get user's discount information
     *
     * @param int $user_id
     * @return array|false
     */
    private function get_user_discount($user_id) {
        static $cached_discount = array();
        
        // Return cached result if available
        if (isset($cached_discount[$user_id])) {
            return $cached_discount[$user_id];
        }
        
        $discount_tier = get_user_meta($user_id, '_wc_memberships_profile_field_discount_tier', true);
        if (!$discount_tier || $discount_tier === 'None') {
            $cached_discount[$user_id] = false;
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
            $this->logger->error('Invalid discount tier', [
                'user_id' => $user_id,
                'discount_tier' => $discount_tier,
                'available_tiers' => array_keys($available_tiers)
            ]);
            $cached_discount[$user_id] = false;
            return false;
        }

        $discount_info = array(
            'tier' => ucfirst($tier_key),
            'percentage' => floatval($available_tiers[$tier_key])
        );

        $this->logger->info('User discount tier active', [
            'user_id' => $user_id,
            'tier' => $discount_info['tier'],
            'percentage' => $discount_info['percentage'],
            'calculation_example' => sprintf(
                'For a €100 product, discount would be: €%.2f',
                100 * ($discount_info['percentage'] / 100)
            )
        ]);

        $cached_discount[$user_id] = $discount_info;
        return $discount_info;
    }

    /**
     * Apply discount to cart
     *
     * @param WC_Cart $cart
     */
    public function apply_discount_to_cart($cart) {
        if ($cart->is_empty()) {
            return;
        }

        // Avoid infinite loops
        static $is_calculating = false;
        if ($is_calculating) {
            return;
        }

        try {
            $is_calculating = true;

            $user_id = get_current_user_id();
            if (!$user_id) {
                return;
            }

            $discount_info = $this->get_user_discount($user_id);
            if (!$discount_info) {
                $this->logger->debug('No discount info found', [
                    'user_id' => $user_id,
                    'meta' => get_user_meta($user_id, '_wc_memberships_profile_field_discount_tier', true)
                ]);
                return;
            }

            $this->logger->info('Processing cart discount', [
                'user_id' => $user_id,
                'discount_tier' => $discount_info['tier'],
                'discount_percentage' => $discount_info['percentage']
            ]);

            // Store discount info in session for later use
            WC()->session->set('mdm_discount_info', $discount_info);

            // Calculate total discount amount
            $total_discount = 0;

            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                if (empty($cart_item['data'])) {
                    continue;
                }

                $product = $cart_item['data'];
                
                // Check if loyalty discount is enabled for this product
                if (!Product::is_discount_enabled($product)) {
                    $this->logger->debug('Skipping product - loyalty discount not enabled', [
                        'product_id' => $product->get_id(),
                        'product_name' => $product->get_name()
                    ]);
                    continue;
                }

                $original_price = $product->get_regular_price() ? $product->get_regular_price() : $product->get_price();
                
                if ($original_price > 0) {
                    $discount_amount = ($original_price * $discount_info['percentage']) / 100;
                    $total_discount += $discount_amount * $cart_item['quantity'];

                    $this->logger->debug('Applied discount to product', [
                        'product_id' => $product->get_id(),
                        'product_name' => $product->get_name(),
                        'original_price' => $original_price,
                        'discount_amount' => $discount_amount,
                        'quantity' => $cart_item['quantity']
                    ]);
                }
            }

            if ($total_discount > 0) {
                // Add the discount as a fee
                $cart->add_fee(
                    sprintf(
                        __('%s Tier Membership Discount (%s%%)', 'membership-discount-manager'),
                        $discount_info['tier'],
                        $discount_info['percentage']
                    ),
                    -$total_discount
                );

                WC()->session->set('mdm_total_discount', $total_discount);
            }

        } catch (\Exception $e) {
            $this->logger->error('Error in discount calculation', [
                'error' => $e->getMessage()
            ]);
        } finally {
            $is_calculating = false;
        }
    }

    /**
     * Add discount fee
     *
     * @param WC_Cart $cart
     */
    public function add_discount_fee($cart) {
        // Static flag to prevent recursion
        static $is_calculating = false;
        if ($is_calculating) {
            return;
        }

        try {
            $is_calculating = true;

            if ($cart->is_empty()) {
                return;
            }

            $user_id = get_current_user_id();
            if (!$user_id) {
                return;
            }

            // Get fresh discount info
            $discount_info = $this->get_user_discount($user_id);
            if (!$discount_info) {
                return;
            }

            // Calculate subtotal only for eligible products
            $eligible_subtotal = 0;

            foreach ($cart->get_cart() as $cart_item) {
                if (empty($cart_item['data'])) {
                    continue;
                }

                $product = $cart_item['data'];
                
                // Only include products with loyalty discount enabled
                if (!Product::is_discount_enabled($product)) {
                    continue;
                }

                // Get the correct price based on the spending calculation setting
                $use_net_total = get_option('mdm_use_net_total', true);
                if ($use_net_total) {
                    // Use price excluding tax
                    $price = wc_get_price_excluding_tax($product);
                } else {
                    // Use price including tax
                    $price = wc_get_price_including_tax($product);
                }
                
                $eligible_subtotal += $price * $cart_item['quantity'];
            }

            // Only proceed if we have eligible products
            if ($eligible_subtotal > 0) {
                // Calculate discount
                $discount_amount = $eligible_subtotal * ($discount_info['percentage'] / 100);

                // Add the discount as a negative fee
                $cart->add_fee(
                    sprintf(
                        __('VIP %s Tier Discount (%s%%)', 'membership-discount-manager'),
                        $discount_info['tier'],
                        $discount_info['percentage']
                    ),
                    -$discount_amount
                );

                // Handle coupon restrictions
                if (!empty($cart->get_applied_coupons())) {
                    $cart->remove_coupons();
                    wc_add_notice(
                        sprintf(
                            __('Note: Your VIP %s Tier Discount (%s%%) has been automatically applied. Additional coupons cannot be combined with your loyalty discount.', 'membership-discount-manager'),
                            $discount_info['tier'],
                            $discount_info['percentage']
                        ),
                        'notice'
                    );
                }
            }
        } catch (\Exception $e) {
            error_log('MDM Error: ' . $e->getMessage());
        } finally {
            $is_calculating = false;
        }
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
                            __('VIP %s Tier Discount (%s%%)', 'membership-discount-manager'),
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
     * Validate if coupon can be used with VIP discount
     *
     * @param bool $valid
     * @param WC_Coupon $coupon
     * @return bool
     */
    public function validate_coupon_for_vip($valid, $coupon) {
        // If coupon is already invalid for other reasons, return early
        if (!$valid) {
            return $valid;
        }

        $user_id = get_current_user_id();
        $discount_info = $this->get_user_discount($user_id);

        // If user has VIP discount, prevent coupon usage with custom message
        if ($discount_info) {
            // Show message only if not already shown
            if (!wc_has_notice('vip_discount_notice')) {
                wc_add_notice(
                    sprintf(
                        __('The coupon "%s" cannot be used because your VIP %s Tier Discount (%s%%) is already applied.', 'membership-discount-manager'),
                        $coupon->get_code(),
                        $discount_info['tier'],
                        $discount_info['percentage']
                    ),
                    'error'
                );
            }
            return false;
        }

        return $valid;
    }

    /**
     * Check and remove any applied coupons for VIP members
     */
    public function check_and_remove_coupons() {
        // This functionality is now handled in add_discount_fee
        return;
    }
} 