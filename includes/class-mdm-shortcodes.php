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
        add_shortcode('mdm_next_tier_progress', array($this, 'render_next_tier_progress'));
    }

    /**
     * Get user's VIP status from user meta
     * 
     * @param int $user_id
     * @return string|null
     */
    private function get_vip_status($user_id) {
        try {
            return get_user_meta($user_id, '_wc_memberships_profile_field_discount_tier', true);
        } catch (\Exception $e) {
            $this->logger->error('Error getting VIP status', array(
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Get user's spending data
     * 
     * @param int $user_id
     * @return array|null
     */
    private function get_user_spending($user_id) {
        try {
            return array(
                'yearly_spend' => floatval(get_user_meta($user_id, '_wc_memberships_profile_field_average_spend_last_year', true)),
                'total_spend' => floatval(get_user_meta($user_id, '_wc_memberships_profile_field_total_spend_all_time', true))
            );
        } catch (\Exception $e) {
            $this->logger->error('Error getting user spending', array(
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Get next tier information based on current spending
     * 
     * @param float $yearly_spend
     * @return array|null
     */
    private function get_next_tier_info($yearly_spend) {
        try {
            $tier_settings = get_option('mdm_tier_settings', array());
            if (empty($tier_settings)) {
                return null;
            }

            // Define tier order
            $tier_order = array('none', 'bronze', 'silver', 'gold', 'platinum');
            
            // Find next tier
            $next_tier = null;
            $amount_needed = 0;
            
            foreach ($tier_order as $tier) {
                if (!isset($tier_settings[$tier])) continue;
                
                $min_spend = floatval($tier_settings[$tier]['min_spend']);
                if ($yearly_spend < $min_spend) {
                    $next_tier = $tier;
                    $amount_needed = $min_spend - $yearly_spend;
                    break;
                }
            }

            if ($next_tier === null) {
                return null; // Already at highest tier
            }

            return array(
                'tier' => ucfirst($next_tier),
                'amount_needed' => $amount_needed,
                'discount' => $tier_settings[$next_tier]['discount']
            );
        } catch (\Exception $e) {
            $this->logger->error('Error calculating next tier info', array(
                'yearly_spend' => $yearly_spend,
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
        // Get current user ID
        $user_id = get_current_user_id();
        if (!$user_id) {
            return '';
        }

        $this->logger->debug('Rendering VIP status shortcode', array(
            'user_id' => $user_id
        ));

        // Get VIP status
        $status = $this->get_vip_status($user_id);

        // Return empty if no status found
        if (empty($status)) {
            return '';
        }

        return esc_html($status);
    }

    /**
     * Render next tier progress shortcode
     * 
     * @param array $atts Shortcode attributes
     * @param string $content Shortcode content
     * @return string
     */
    public function render_next_tier_progress($atts = [], $content = null) {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return sprintf(
                '<p class="mdm-message mdm-login-required">%s</p>',
                __('Please log in to see your Loyalty Tier progress.', 'membership-discount-manager')
            );
        }

        try {
            $next_tier_info = $this->get_next_tier_info($user_id);
            
            if (!$next_tier_info) {
                return sprintf(
                    '<p class="mdm-message mdm-max-tier">%s</p>',
                    __('Congratulations! You have reached our highest Loyalty Tier level.', 'membership-discount-manager')
                );
            }

            $current_tier = get_user_meta($user_id, '_wc_memberships_profile_field_discount_tier', true);
            $current_tier = $current_tier ? $current_tier : 'None';
            
            $message = sprintf(
                __('You are currently in the %s Loyalty Tier. Spend %s more to reach the %s tier and get a %s%% discount!', 'membership-discount-manager'),
                '<span class="mdm-current-tier">' . esc_html($current_tier) . '</span>',
                '<span class="mdm-amount-needed">' . wc_price($next_tier_info['amount_needed']) . '</span>',
                '<span class="mdm-next-tier">' . esc_html($next_tier_info['tier']) . '</span>',
                '<span class="mdm-next-discount">' . esc_html($next_tier_info['discount']) . '</span>'
            );

            return sprintf('<p class="mdm-message mdm-tier-progress">%s</p>', $message);

        } catch (\Exception $e) {
            $this->logger->error('Error rendering next tier progress', [
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }
} 