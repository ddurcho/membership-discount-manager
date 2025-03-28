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
     * Constructor
     */
    public function __construct() {
        $this->logger = new Logger();
        
        // Early debug logging
        error_log('🚀 MDM: Discount Handler Constructor Called');
        $this->logger->info('🚀 MDM: Discount Handler Constructor Called');
        
        // Log WordPress loading phase
        $this->logger->info('🔄 WordPress Loading Phase', [
            'did_action_init' => did_action('init'),
            'did_action_wp_loaded' => did_action('wp_loaded'),
            'did_action_woocommerce_init' => did_action('woocommerce_init'),
            'wc_loaded' => defined('WC_LOADED'),
            'is_admin' => is_admin(),
            'doing_ajax' => wp_doing_ajax(),
            'current_filter' => current_filter()
        ]);

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
    }

    /**
     * Early checkout debug
     */
    public function early_checkout_debug() {
        error_log('🔍 MDM: Early Checkout Debug Called');
        $this->logger->info('🔍 MDM: Early Checkout Debug', [
            'is_checkout' => is_checkout(),
            'is_cart' => is_cart(),
            'user_id' => get_current_user_id(),
            'request_uri' => $_SERVER['REQUEST_URI'],
            'wc_loaded' => defined('WC_LOADED'),
            'cartflows_active' => class_exists('\\CartFlows\\Core')
        ]);
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

        $this->logger->debug('Debugging discount setup', [
            'user_id' => $user_id,
            'is_user_logged_in' => is_user_logged_in(),
            'wc_session_exists' => isset(WC()->session),
            'wc_cart_exists' => isset(WC()->cart),
            'relevant_meta' => $relevant_meta,
            'tier_settings' => get_option('mdm_tier_settings')
        ]);
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
            
            $this->logger->info('🏷️ User Membership Status', [
                'user_id' => $user_id,
                'has_memberships' => !empty($memberships),
                'membership_details' => $membership_info
            ]);
        }

        // Debug discount calculation
        $discount_info = $this->get_user_discount($user_id);
        $this->logger->info('💰 Current Discount Calculation', [
            'discount_info' => $discount_info ? [
                'tier' => $discount_info['tier'],
                'percentage' => $discount_info['percentage']
            ] : null,
            'cart_subtotal' => WC()->cart ? WC()->cart->get_subtotal() : 'No cart',
            'calculated_discount' => $discount_info ? ($discount_info['percentage'] . '%') : 'No discount'
        ]);
    }

    /**
     * Initialize WooCommerce hooks
     */
    public function init_hooks() {
        error_log('⚡ MDM: Init Hooks Called');
        $this->logger->info('⚡ MDM: Initializing Hooks');

        // Cart calculation hooks
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_discount_fee'), 99);
        
        // Display hooks
        add_action('woocommerce_cart_totals_before_order_total', array($this, 'display_discount_info'), 99);
        add_action('woocommerce_review_order_before_order_total', array($this, 'display_discount_info'), 99);
        
        // CartFlows compatibility
        if (class_exists('\\CartFlows\\Core')) {
            add_action('cartflows_checkout_before_calculate_totals', array($this, 'add_discount_fee'), 99);
        }

        $this->logger->info('⚡ MDM: Hooks Initialized');
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

        $original_price = $product->get_price();
        $discount_amount = ($original_price * $discount_info['percentage']) / 100;
        $discounted_price = $original_price - $discount_amount;

        $this->logger->debug('Adjusting product price display', [
            'product_id' => $product->get_id(),
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
        $original_price = $product->get_price();
        $discount_amount = ($original_price * $discount_info['percentage']) / 100;
        $discounted_price = $original_price - $discount_amount;

        $this->logger->debug('Adjusting cart item price', [
            'product_id' => $product->get_id(),
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
        $quantity = $cart_item['quantity'];
        $original_price = $product->get_price();
        $original_subtotal = $original_price * $quantity;
        $discount_amount = ($original_subtotal * $discount_info['percentage']) / 100;
        $discounted_subtotal = $original_subtotal - $discount_amount;

        $this->logger->debug('Adjusting cart item subtotal', [
            'product_id' => $product->get_id(),
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
     * Get user's current discount tier and percentage
     *
     * @param int $user_id
     * @return array|false Array with tier and percentage or false if no discount
     */
    public function get_user_discount($user_id) {
        if (!$user_id) {
            return false;
        }

        // Get discount tier and settings
        $discount_tier = get_user_meta($user_id, '_wc_memberships_profile_field_discount_tier', true);
        $tier_settings = get_option('mdm_tier_settings', array());

        $this->logger->debug('Checking discount tier', [
            'user_id' => $user_id,
            'discount_tier' => $discount_tier,
            'available_tiers' => array_keys($tier_settings)
        ]);

        // Return false if tier is None or empty
        if (empty($discount_tier) || $discount_tier === 'None') {
            return false;
        }

        // Normalize the tier name to lowercase for comparison
        $normalized_tier = strtolower($discount_tier);
        $normalized_settings = array_change_key_case($tier_settings, CASE_LOWER);

        // Check for valid discount tier
        if (isset($normalized_settings[$normalized_tier])) {
            $discount_info = array(
                'tier' => $discount_tier, // Keep original case for display
                'percentage' => $normalized_settings[$normalized_tier]['discount']
            );
            
            $this->logger->info('User discount retrieved', [
                'user_id' => $user_id,
                'tier' => $discount_tier,
                'percentage' => $normalized_settings[$normalized_tier]['discount']
            ]);
            
            return $discount_info;
        }

        return false;
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
                $original_price = $product->get_regular_price() ? $product->get_regular_price() : $product->get_price();
                
                if ($original_price > 0) {
                    $discount_amount = ($original_price * $discount_info['percentage']) / 100;
                    $total_discount += $discount_amount * $cart_item['quantity'];
                }
            }

            if ($total_discount > 0) {
                // Add the discount as a fee
                $cart->add_fee(
                    sprintf(
                        __('%s Tier Membership Discount (%s%%)', 'membership-discount-manager'),
                        $discount_info['tier'], // Use original case for display
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
     * Add discount as a negative fee
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
                // Clear any existing discount data
                WC()->session->set('mdm_discount_info', null);
                WC()->session->set('mdm_total_discount', 0);
                return;
            }

            // Get fresh discount info instead of from session
            $discount_info = $this->get_user_discount($user_id);
            if (!$discount_info || empty($discount_info['tier']) || $discount_info['tier'] === 'None') {
                // Clear any existing discount data if no valid tier is found
                WC()->session->set('mdm_discount_info', null);
                WC()->session->set('mdm_total_discount', 0);
                return;
            }

            // Get cart subtotal excluding tax
            $subtotal = $cart->get_subtotal();
            
            // Calculate discount
            $total_discount = ($subtotal * $discount_info['percentage']) / 100;

            $this->logger->debug('Calculating discount', [
                'user_id' => $user_id,
                'discount_tier' => $discount_info['tier'],
                'discount_percentage' => $discount_info['percentage'],
                'cart_subtotal' => $subtotal,
                'calculated_discount' => $total_discount
            ]);

            if ($total_discount > 0) {
                // Add as a negative fee
                $cart->add_fee(
                    sprintf(
                        __('VIP %s Tier Discount (%s%%)', 'membership-discount-manager'),
                        $discount_info['tier'],
                        $discount_info['percentage']
                    ),
                    -$total_discount
                );

                // Store for display
                WC()->session->set('mdm_discount_info', $discount_info);
                WC()->session->set('mdm_total_discount', $total_discount);
            } else {
                // Clear discount data if no discount is calculated
                WC()->session->set('mdm_discount_info', null);
                WC()->session->set('mdm_total_discount', 0);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error applying discount fee', [
                'error' => $e->getMessage()
            ]);
            // Clear discount data on error
            WC()->session->set('mdm_discount_info', null);
            WC()->session->set('mdm_total_discount', 0);
        } finally {
            $is_calculating = false;
        }
    }

    /**
     * Display discount information
     */
    public function display_discount_info() {
        $discount_info = WC()->session->get('mdm_discount_info');
        $total_discount = WC()->session->get('mdm_total_discount');

        if ($discount_info && $total_discount > 0) {
            ?>
            <tr class="membership-discount">
                <th><?php _e('VIP Discount', 'membership-discount-manager'); ?></th>
                <td data-title="<?php esc_attr_e('VIP Discount', 'membership-discount-manager'); ?>">
                    <?php 
                    printf(
                        __('%s Tier (%s%%) - You saved %s', 'membership-discount-manager'),
                        $discount_info['tier'],
                        $discount_info['percentage'],
                        wc_price($total_discount)
                    ); 
                    ?>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Add discount information to order details
     *
     * @param array $total_rows
     * @param WC_Order $order
     * @return array
     */
    public function add_discount_info_to_order($total_rows, $order) {
        $user_id = $order->get_user_id();
        $discount_info = $this->get_user_discount($user_id);

        if ($discount_info) {
            $this->logger->debug('Adding discount info to order', [
                'order_id' => $order->get_id(),
                'user_id' => $user_id,
                'discount_info' => $discount_info
            ]);

            $new_rows = array();
            
            foreach ($total_rows as $key => $row) {
                $new_rows[$key] = $row;
                
                if ($key === 'order_total') {
                    $new_rows['membership_discount'] = array(
                        'label' => __('VIP Discount:', 'membership-discount-manager'),
                        'value' => sprintf(
                            __('%s Tier (%s%% discount)', 'membership-discount-manager'),
                            $discount_info['tier'],
                            $discount_info['percentage']
                        )
                    );
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
} 