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
        add_shortcode('mdm_loyalty_rules', array($this, 'render_loyalty_rules'));
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

            // Define tier order from lowest to highest
            $tier_order = array(
                'none' => 0,
                'bronze' => 5,
                'silver' => 10,
                'gold' => 15,
                'platinum' => 20
            );
            
            // Get current tier based on yearly spend
            $current_tier = 'none';
            foreach ($tier_order as $tier => $discount) {
                if (isset($tier_settings[$tier]) && $yearly_spend >= floatval($tier_settings[$tier]['min_spend'])) {
                    $current_tier = $tier;
                }
            }

            // Find next tier
            $next_tier = null;
            $next_tier_min_spend = 0;
            $current_tier_level = array_search($current_tier, array_keys($tier_order));
            
            // Only look for next tier if not at platinum
            if ($current_tier !== 'platinum') {
                $tier_keys = array_keys($tier_order);
                $next_tier = $tier_keys[$current_tier_level + 1];
                
                if (isset($tier_settings[$next_tier])) {
                    $next_tier_min_spend = floatval($tier_settings[$next_tier]['min_spend']);
                    $amount_needed = $next_tier_min_spend - $yearly_spend;
                    
                    return array(
                        'tier' => ucfirst($next_tier),
                        'amount_needed' => $amount_needed,
                        'discount' => $tier_order[$next_tier]
                    );
                }
            }

            return null; // Return null if at highest tier or no next tier found

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
                __('Please log in to see your membership journey with us.', 'membership-discount-manager')
            );
        }

        try {
            // Get user's yearly spend
            $spending_data = $this->get_user_spending($user_id);
            if (!$spending_data) {
                return '';
            }

            $next_tier_info = $this->get_next_tier_info($spending_data['yearly_spend']);
            $current_tier = get_user_meta($user_id, '_wc_memberships_profile_field_discount_tier', true);
            $current_tier = $current_tier ? strtolower($current_tier) : 'none';

            // Define tier icons
            $tier_icons = array(
                'none' => '⭐',
                'bronze' => '🥉',
                'silver' => '🥈',
                'gold' => '🥇',
                'platinum' => '👑'
            );

            $output = '<div class="mdm-tier-progress-wrapper">';
            
            if (!$next_tier_info) {
                $output .= sprintf(
                    '<p class="mdm-message mdm-max-tier">%s Incredible Journey! You\'re a Platinum Member! %s</p>
                     <p class="mdm-message mdm-appreciation">Your trust and dedication to our community mean the world to us. Members like you make Nestwork thrive and grow. Thank you for being such an amazing part of our family! 💝</p>',
                    $tier_icons['platinum'],
                    $tier_icons['platinum']
                );
            } else {
                $current_icon = isset($tier_icons[$current_tier]) ? $tier_icons[$current_tier] : $tier_icons['none'];
                $next_tier_key = strtolower($next_tier_info['tier']);
                $next_icon = isset($tier_icons[$next_tier_key]) ? $tier_icons[$next_tier_key] : '';

                $message = sprintf(
                    __('Thank you for being an amazing %s %s member! You\'re just %s away from becoming a cherished %s %s member with a special %s%% appreciation reward. Every step of your journey with us strengthens our community! 💫', 'membership-discount-manager'),
                    $current_icon,
                    '<span class="mdm-current-tier">' . esc_html(ucfirst($current_tier)) . '</span>',
                    '<span class="mdm-amount-needed">' . wc_price($next_tier_info['amount_needed']) . '</span>',
                    $next_icon,
                    '<span class="mdm-next-tier">' . esc_html($next_tier_info['tier']) . '</span>',
                    '<span class="mdm-next-discount">' . esc_html($next_tier_info['discount']) . '</span>'
                );

                $output .= sprintf('<p class="mdm-message mdm-tier-progress">%s</p>', $message);
            }

            $output .= '</div>';

            // Add some basic inline styles
            $output .= '
            <style>
                .mdm-tier-progress-wrapper {
                    background: #f9f9f9;
                    padding: 25px 30px;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    margin: 1em 0;
                }
                .mdm-message {
                    font-size: 1.15em;
                    line-height: 1.7;
                    color: #333;
                    margin: 0;
                }
                .mdm-appreciation {
                    margin-top: 15px;
                    color: #555;
                    font-style: italic;
                    text-align: center;
                    line-height: 1.8;
                }
                .mdm-current-tier,
                .mdm-next-tier,
                .mdm-next-discount,
                .mdm-amount-needed {
                    font-weight: bold;
                    color: #2c3338;
                }
                .mdm-max-tier {
                    text-align: center;
                    color: #2c3338;
                    font-weight: bold;
                    margin-bottom: 12px;
                    font-size: 1.2em;
                }
                .mdm-login-required {
                    color: #666;
                    font-style: italic;
                    text-align: center;
                }
            </style>';

            return $output;

        } catch (\Exception $e) {
            $this->logger->error('Error rendering next tier progress', [
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Render loyalty rules description shortcode
     * 
     * @param array $atts Shortcode attributes
     * @param string $content Shortcode content
     * @return string
     */
    public function render_loyalty_rules($atts = [], $content = null) {
        try {
            $tier_settings = get_option('mdm_tier_settings', array());
            if (empty($tier_settings)) {
                return '';
            }

            // Start with a friendly introduction
            $output = '<div class="mdm-loyalty-rules">';
            $output .= sprintf(
                '<h3>%s</h3>',
                __('Nestwork Loyalty Program!', 'membership-discount-manager')
            );
            
            $output .= sprintf(
                '<p class="mdm-intro">%s</p>',
                __('We value your loyalty! Our program rewards you with exclusive discounts based on your yearly spending. The longer you stay our member the bigger the discount!', 'membership-discount-manager')
            );

            // Explain how it works
            $output .= sprintf(
                '<h4>%s</h4>',
                __('How It Works', 'membership-discount-manager')
            );

            $output .= '<ul class="mdm-tier-list">';

            // Define tier order for consistent display
            $tier_order = array(
                'bronze' => array('name' => __('Bronze', 'membership-discount-manager'), 'icon' => '🥉'),
                'silver' => array('name' => __('Silver', 'membership-discount-manager'), 'icon' => '🥈'),
                'gold' => array('name' => __('Gold', 'membership-discount-manager'), 'icon' => '🥇'),
                'platinum' => array('name' => __('Platinum', 'membership-discount-manager'), 'icon' => '👑')
            );

            // Display each tier's requirements and benefits
            foreach ($tier_order as $tier_key => $tier_info) {
                if (isset($tier_settings[$tier_key])) {
                    $tier = $tier_settings[$tier_key];
                    $output .= sprintf(
                        '<li class="mdm-tier mdm-tier-%s"><strong>%s %s</strong> - %s',
                        esc_attr($tier_key),
                        $tier_info['icon'],
                        $tier_info['name'],
                        sprintf(
                            __('Spend %s or more yearly to enjoy a %s%% discount on eligible products!', 'membership-discount-manager'),
                            wc_price($tier['min_spend']),
                            $tier['discount']
                        )
                    );
                }
            }
            
            $output .= '</ul>';

            // Add important notes
            $output .= sprintf(
                '<h4>%s</h4>',
                __('Important Notes', 'membership-discount-manager')
            );

            $output .= '<ul class="mdm-notes">';
            $output .= sprintf(
                '<li>%s</li>',
                __('Your tier is automatically calculated based on your spending in the last 12 months.', 'membership-discount-manager')
            );
            $output .= sprintf(
                '<li>%s</li>',
                __('Loyalty discounts cannot be combined with other coupons or promotional offers.', 'membership-discount-manager')
            );
            $output .= sprintf(
                '<li>%s</li>',
                __('Discounts are automatically applied to eligible products at checkout.', 'membership-discount-manager')
            );
            $output .= '</ul>';

            $output .= '</div>';

            // Add some basic inline styles
            $output .= '
            <style>
                .mdm-loyalty-rules {
                    max-width: 800px;
                    margin: 2em auto;
                    padding: 20px;
                    background: #f9f9f9;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                .mdm-loyalty-rules h3 {
                    color: #333;
                    margin-bottom: 1em;
                }
                .mdm-loyalty-rules h4 {
                    color: #444;
                    margin: 1.5em 0 1em;
                }
                .mdm-intro {
                    font-size: 1.1em;
                    line-height: 1.6;
                    color: #666;
                }
                .mdm-tier-list {
                    list-style: none;
                    padding: 0;
                }
                .mdm-tier {
                    margin: 1em 0;
                    padding: 10px 15px;
                    background: #fff;
                    border-radius: 6px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                }
                .mdm-notes {
                    list-style: disc;
                    padding-left: 20px;
                    color: #666;
                }
                .mdm-notes li {
                    margin: 0.5em 0;
                }
            </style>';

            return $output;

        } catch (\Exception $e) {
            $this->logger->error('Error rendering loyalty rules', [
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }
} 