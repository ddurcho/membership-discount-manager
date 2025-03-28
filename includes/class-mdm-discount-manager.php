<?php
namespace MembershipDiscountManager;

/**
 * Handles discount calculations and tier management
 */
class DiscountManager {
    /**
     * Discount tiers configuration
     */
    const DISCOUNT_TIERS = [
        'bronze' => [
            'discount' => 5,
            'min_spend' => 0,
        ],
        'silver' => [
            'discount' => 10,
            'min_spend' => 1000,
        ],
        'gold' => [
            'discount' => 15,
            'min_spend' => 5000,
        ],
        'platinum' => [
            'discount' => 20,
            'min_spend' => 10000,
        ],
    ];

    /**
     * Get user's current discount percentage
     *
     * @param int $user_id
     * @return float|bool
     */
    public function get_user_discount($user_id) {
        $tier = get_user_meta($user_id, '_mdm_discount_tier', true);
        return isset(self::DISCOUNT_TIERS[$tier]) ? self::DISCOUNT_TIERS[$tier]['discount'] : false;
    }

    /**
     * Calculate and update discount tier for a user
     *
     * @param int $user_id
     * @return string|bool
     */
    public function calculate_user_tier($user_id) {
        // Check if manual override is set
        if (get_user_meta($user_id, '_mdm_manual_override', true)) {
            return false;
        }

        $total_spend = $this->calculate_user_total_spend($user_id);
        $tier = $this->determine_tier_from_spend($total_spend);

        update_user_meta($user_id, '_mdm_discount_tier', $tier);
        update_user_meta($user_id, '_mdm_total_spend', $total_spend);

        return $tier;
    }

    /**
     * Update tiers for all members
     */
    public function update_all_member_tiers() {
        $members = \wc_memberships_get_active_members();
        
        if (!empty($members)) {
            foreach ($members as $member) {
                $this->calculate_user_tier($member->get_user_id());
            }
        }
    }

    /**
     * Calculate total spend for a user in the last year
     *
     * @param int $user_id
     * @return float
     */
    private function calculate_user_total_spend($user_id) {
        global $wpdb;

        $one_year_ago = date('Y-m-d H:i:s', strtotime('-1 year'));

        $total = $wpdb->get_var($wpdb->prepare("
            SELECT SUM(pm.meta_value)
            FROM {$wpdb->posts} AS p
            JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_author = %d
            AND p.post_date >= %s
            AND pm.meta_key = '_order_total'
        ", $user_id, $one_year_ago));

        return (float) $total;
    }

    /**
     * Determine appropriate tier based on spend amount
     *
     * @param float $total_spend
     * @return string
     */
    private function determine_tier_from_spend($total_spend) {
        $tier = 'bronze'; // Default tier

        foreach (self::DISCOUNT_TIERS as $key => $data) {
            if ($total_spend >= $data['min_spend']) {
                $tier = $key;
            } else {
                break;
            }
        }

        return $tier;
    }

    /**
     * Set manual discount tier override
     *
     * @param int $user_id
     * @param string $tier
     * @param bool $override
     * @return bool
     */
    public function set_manual_tier($user_id, $tier, $override = true) {
        if (!isset(self::DISCOUNT_TIERS[$tier])) {
            return false;
        }

        update_user_meta($user_id, '_mdm_discount_tier', $tier);
        update_user_meta($user_id, '_mdm_manual_override', $override);

        return true;
    }
} 