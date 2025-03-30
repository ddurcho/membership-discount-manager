<?php
namespace MembershipDiscountManager;

/**
 * Handles shortcode functionality
 */
class Shortcodes {
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
        $this->init_shortcodes();
    }

    /**
     * Initialize shortcodes
     */
    private function init_shortcodes() {
        add_shortcode('mdm_vip_status', array($this, 'render_vip_status'));
    }

    /**
     * Get user's VIP status using WooCommerce Memberships API
     * 
     * @param int $user_id
     * @return string|null
     */
    private function get_vip_status_via_membership($user_id) {
        try {
            if (!function_exists('wc_memberships_get_user_memberships')) {
                throw new \Exception('WooCommerce Memberships plugin is not active');
            }

            $memberships = wc_memberships_get_user_memberships($user_id);
            if (empty($memberships)) {
                return null;
            }

            // Get the first active membership
            foreach ($memberships as $membership) {
                if ($membership->get_status() === 'active') {
                    return $membership->get_profile_field('discount_tier');
                }
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->error('Error getting VIP status via membership', array(
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Get user's VIP status directly from user meta
     * 
     * @param int $user_id
     * @return string|null
     */
    private function get_vip_status_via_meta($user_id) {
        try {
            return get_user_meta($user_id, '_wc_memberships_profile_field_discount_tier', true);
        } catch (\Exception $e) {
            $this->logger->error('Error getting VIP status via user meta', array(
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Render VIP status shortcode
     * 
     * @param array $atts Shortcode attributes
     * @param string $content Shortcode content
     * @return string
     */
    public function render_vip_status($atts = [], $content = null) {
        // Parse attributes
        $atts = shortcode_atts(array(
            'method' => 'membership', // 'membership' or 'meta'
        ), $atts);

        // Get current user ID
        $user_id = get_current_user_id();
        if (!$user_id) {
            return '';
        }

        $this->logger->debug('Rendering VIP status shortcode', array(
            'user_id' => $user_id,
            'method' => $atts['method']
        ));

        // Get VIP status based on method
        $status = $atts['method'] === 'meta' 
            ? $this->get_vip_status_via_meta($user_id)
            : $this->get_vip_status_via_membership($user_id);

        // Return empty if no status found
        if (empty($status)) {
            return '';
        }

        return esc_html($status);
    }
} 