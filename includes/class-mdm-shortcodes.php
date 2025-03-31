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
        // Get current user ID
        $user_id = get_current_user_id();
        if (!$user_id) {
            return '';
        }

        $this->logger->debug('Rendering next tier progress shortcode', array(
            'user_id' => $user_id
        ));

        // Check for manual override
        $manual_override = get_user_meta($user_id, '_wc_memberships_profile_field_manual_discount_override', true);
        if ($manual_override === 'yes') {
            return ''; // Don't show progress if manually overridden
        }

        // Get user's spending data
        $spending = $this->get_user_spending($user_id);
        if (!$spending) {
            return '';
        }

        // Get next tier information based on yearly spend
        $next_tier = $this->get_next_tier_info($spending['yearly_spend']);
        if (!$next_tier) {
            return sprintf(
                '<span class="mdm-message mdm-message-success">%s</span>',
                __('Congratulations! You\'ve reached our highest VIP tier! 🎉', 'membership-discount-manager')
            );
        }

        // Format amount using WooCommerce currency
        $amount_formatted = wc_price($next_tier['amount_needed']);

        // Generate motivational message
        $messages = array(
            sprintf(
                __('Spend <span class="mdm-amount">%s</span> more this year to unlock <span class="mdm-tier">%s</span> status and enjoy <span class="mdm-discount">%d%%</span> discount! 🎯', 'membership-discount-manager'),
                $amount_formatted,
                $next_tier['tier'],
                $next_tier['discount']
            ),
            sprintf(
                __('You\'re just <span class="mdm-amount">%s</span> away from <span class="mdm-tier">%s</span> tier and a sweet <span class="mdm-discount">%d%%</span> discount this year! 🚀', 'membership-discount-manager'),
                $amount_formatted,
                $next_tier['tier'],
                $next_tier['discount']
            ),
            sprintf(
                __('Almost there! <span class="mdm-amount">%s</span> more this year unlocks <span class="mdm-tier">%s</span> benefits with <span class="mdm-discount">%d%%</span> savings! ⭐', 'membership-discount-manager'),
                $amount_formatted,
                $next_tier['tier'],
                $next_tier['discount']
            )
        );

        // Randomly select a message for variety
        $message = $messages[array_rand($messages)];

        // Wrap the entire message in a container
        return sprintf('<div class="mdm-message mdm-message-progress">%s</div>', $message);
    }
} 