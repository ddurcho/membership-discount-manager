<?php
namespace MembershipDiscountManager;

/**
 * Handles product-specific functionality for the Membership Discount Manager
 */
class Product {
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
        $this->init();
    }

    /**
     * Initialize hooks
     */
    private function init() {
        // Add checkbox to product page
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_loyalty_discount_field'));
        // Save checkbox value
        add_action('woocommerce_process_product_meta', array($this, 'save_loyalty_discount_field'));
    }

    /**
     * Add loyalty discount checkbox to product general options
     */
    public function add_loyalty_discount_field() {
        woocommerce_wp_checkbox(array(
            'id' => '_mdm_loyalty_discount_enabled',
            'label' => __('Enable VIP Discount', 'membership-discount-manager'),
            'description' => __('Allow membership tier discounts for this product', 'membership-discount-manager'),
            'desc_tip' => true,
            'default' => 'no'
        ));
    }

    /**
     * Save loyalty discount field value
     *
     * @param int $post_id Product ID
     */
    public function save_loyalty_discount_field($post_id) {
        $this->logger->debug('Saving loyalty discount field', array(
            'product_id' => $post_id,
            'enabled' => isset($_POST['_mdm_loyalty_discount_enabled']) ? 'yes' : 'no'
        ));

        $loyalty_discount = isset($_POST['_mdm_loyalty_discount_enabled']) ? 'yes' : 'no';
        update_post_meta($post_id, '_mdm_loyalty_discount_enabled', $loyalty_discount);
    }

    /**
     * Check if loyalty discount is enabled for a product
     *
     * @param WC_Product|int $product Product object or ID
     * @return bool
     */
    public static function is_discount_enabled($product) {
        static $logger = null;
        if ($logger === null) {
            $logger = new Logger();
        }

        // Handle both product objects and IDs
        $product_id = $product instanceof \WC_Product ? $product->get_id() : intval($product);
        
        if (!$product_id) {
            $logger->error('Invalid product ID or object', [
                'product' => is_object($product) ? get_class($product) : gettype($product)
            ]);
            return false;
        }

        $enabled = get_post_meta($product_id, '_mdm_loyalty_discount_enabled', true) === 'yes';
        
        $logger->debug('Checking loyalty discount status', [
            'product_id' => $product_id,
            'enabled' => $enabled ? 'yes' : 'no',
            'meta_value' => get_post_meta($product_id, '_mdm_loyalty_discount_enabled', true)
        ]);
        
        return $enabled;
    }
} 