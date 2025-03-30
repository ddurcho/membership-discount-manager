<?php
/**
 * Handles cron job functionality for automatic tier calculations
 *
 * @package MembershipDiscountManager
 */

namespace MembershipDiscountManager;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

use MembershipDiscountManager\Logger;

/**
 * Class Cron
 */
class Cron {
    /**
     * @var Logger
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        // Initialize logger
        $this->logger = new Logger();

        add_action('init', array($this, 'schedule_events'));
        add_action('mdm_auto_calculation', array($this, 'run_auto_calculation'));
        
        // Handle frequency changes
        add_action('update_option_mdm_auto_calc_frequency', array($this, 'handle_frequency_change'), 10, 2);
        add_action('update_option_mdm_auto_calc_enabled', array($this, 'handle_enabled_change'), 10, 2);

        // Add custom cron schedule
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
    }

    /**
     * Add custom cron interval
     *
     * @param array $schedules Existing cron schedules
     * @return array Modified cron schedules
     */
    public function add_cron_interval($schedules) {
        $schedules['five_minutes'] = array(
            'interval' => 300, // 5 minutes in seconds
            'display' => __('Every 5 minutes', 'membership-discount-manager')
        );
        return $schedules;
    }

    /**
     * Schedule cron events
     */
    public function schedule_events() {
        try {
            $this->logger->debug('[CRON] Checking cron schedule status', array(
                'auto_calc_enabled' => get_option('mdm_auto_calc_enabled', false),
                'current_schedule' => wp_next_scheduled('mdm_auto_calculation'),
                'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON
            ));

            if (!get_option('mdm_auto_calc_enabled', false)) {
                $this->logger->info('[CRON] Auto calculation is disabled, clearing scheduled event');
                $this->clear_scheduled_event();
                return;
            }

            if (!wp_next_scheduled('mdm_auto_calculation')) {
                $frequency = get_option('mdm_auto_calc_frequency', 'daily');
                $this->logger->info('[CRON] Scheduling new cron event', array(
                    'frequency' => $frequency,
                    'next_run' => wp_date('Y-m-d H:i:s', time())
                ));
                
                wp_schedule_event(time(), $frequency, 'mdm_auto_calculation');
                
                // Verify scheduling
                $next_scheduled = wp_next_scheduled('mdm_auto_calculation');
                $this->logger->info('[CRON] Cron event scheduled', array(
                    'next_run' => $next_scheduled ? wp_date('Y-m-d H:i:s', $next_scheduled) : 'Not scheduled'
                ));
            } else {
                $next_scheduled = wp_next_scheduled('mdm_auto_calculation');
                $this->logger->debug('[CRON] Cron already scheduled', array(
                    'next_run' => wp_date('Y-m-d H:i:s', $next_scheduled)
                ));
            }
        } catch (\Exception $e) {
            $this->logger->error('[CRON] Error scheduling cron event', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
        }
    }

    /**
     * Clear scheduled event
     */
    private function clear_scheduled_event() {
        wp_clear_scheduled_hook('mdm_auto_calculation');
    }

    /**
     * Handle frequency change
     *
     * @param mixed $old_value Old frequency value
     * @param mixed $new_value New frequency value
     */
    public function handle_frequency_change($old_value, $new_value) {
        if ($old_value !== $new_value && get_option('mdm_auto_calc_enabled', false)) {
            $this->clear_scheduled_event();
            wp_schedule_event(time(), $new_value, 'mdm_auto_calculation');
        }
    }

    /**
     * Handle enabled/disabled change
     *
     * @param mixed $old_value Old enabled value
     * @param mixed $new_value New enabled value
     */
    public function handle_enabled_change($old_value, $new_value) {
        if ($new_value) {
            $frequency = get_option('mdm_auto_calc_frequency', 'daily');
            $this->clear_scheduled_event();
            wp_schedule_event(time(), $frequency, 'mdm_auto_calculation');
        } else {
            $this->clear_scheduled_event();
        }
    }

    /**
     * Run automatic calculation
     */
    public function run_auto_calculation() {
        try {
            $this->logger->info('[CRON] Starting automatic tier calculation');
            
            global $wpdb;
            $admin = new \MembershipDiscountManager\Admin();

            // Get batch size from settings
            $batch_size = get_option('mdm_sync_batch_size', 20);
            $offset = 0;
            $total_processed = 0;
            $total_updated = 0;
            $total_skipped = 0;
            $start_time = microtime(true);

            // First, get total count of users with completed orders
            $count_query = "
                SELECT COUNT(DISTINCT cl.user_id) as total
                FROM {$wpdb->prefix}wc_order_stats AS os
                INNER JOIN {$wpdb->prefix}wc_customer_lookup AS cl ON os.customer_id = cl.customer_id
                WHERE os.status = 'wc-completed'
            ";
            $total_users = $wpdb->get_var($count_query);

            if (!$total_users) {
                throw new \Exception('No users found to process');
            }

            $this->logger->info('[CRON] Starting batch processing', array(
                'total_users' => $total_users,
                'batch_size' => $batch_size,
                'start_time' => wp_date('Y-m-d H:i:s')
            ));

            // Process users in batches
            while ($offset < $total_users) {
                // Get users for this batch
                $users_query = $wpdb->prepare("
                    SELECT DISTINCT
                        cl.user_id,
                        ROUND(SUM(CASE 
                            WHEN os.date_created >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                            THEN os.total_sales 
                            ELSE 0 
                        END), 2) as yearly_spend,
                        ROUND(SUM(os.total_sales), 2) as total_spend
                    FROM {$wpdb->prefix}wc_order_stats AS os
                    INNER JOIN {$wpdb->prefix}wc_customer_lookup AS cl ON os.customer_id = cl.customer_id
                    WHERE os.status = 'wc-completed'
                    GROUP BY cl.user_id
                    LIMIT %d OFFSET %d
                ", $batch_size, $offset);

                $users = $wpdb->get_results($users_query);

                if (empty($users)) {
                    $this->logger->warning('[CRON] No users found in batch', array(
                        'offset' => $offset,
                        'batch_size' => $batch_size
                    ));
                    break;
                }

                $batch_processed = 0;
                $batch_updated = 0;
                $batch_skipped = 0;

                foreach ($users as $user) {
                    // Get user details for logging
                    $user_data = get_user_by('id', $user->user_id);
                    $user_email = $user_data ? $user_data->user_email : 'Unknown';
                    
                    $this->logger->debug('[CRON] Processing user', array(
                        'user_id' => $user->user_id,
                        'user_email' => $user_email,
                        'yearly_spend' => $user->yearly_spend,
                        'total_spend' => $user->total_spend
                    ));

                    // Update spending data
                    update_user_meta($user->user_id, '_wc_memberships_profile_field_average_spend_last_year', $user->yearly_spend);
                    update_user_meta($user->user_id, '_wc_memberships_profile_field_total_spend_all_time', $user->total_spend);

                    // Skip if manual override is enabled
                    $manual_override = get_user_meta($user->user_id, '_wc_memberships_profile_field_manual_discount_override', true);
                    if ($manual_override === 'yes') {
                        $this->logger->debug('[CRON] Skipping user (manual override)', array(
                            'user_id' => $user->user_id,
                            'user_email' => $user_email
                        ));
                        $batch_skipped++;
                        continue;
                    }

                    // Get current tier before update
                    $current_tier = get_user_meta($user->user_id, '_wc_memberships_profile_field_discount_tier', true);

                    // Use Admin class to calculate and update tier
                    if ($admin->calculate_and_update_user_tier($user->user_id)) {
                        $new_tier = get_user_meta($user->user_id, '_wc_memberships_profile_field_discount_tier', true);
                        $this->logger->info('[CRON] Updated user tier', array(
                            'user_id' => $user->user_id,
                            'user_email' => $user_email,
                            'old_tier' => $current_tier,
                            'new_tier' => $new_tier,
                            'yearly_spend' => $user->yearly_spend,
                            'total_spend' => $user->total_spend
                        ));
                        $batch_updated++;
                    }

                    $batch_processed++;
                }

                // Update totals
                $total_processed += $batch_processed;
                $total_updated += $batch_updated;
                $total_skipped += $batch_skipped;

                $this->logger->info('[CRON] Batch completed', array(
                    'batch_number' => ($offset / $batch_size) + 1,
                    'processed' => $batch_processed,
                    'updated' => $batch_updated,
                    'skipped' => $batch_skipped,
                    'total_processed_so_far' => $total_processed,
                    'total_updated_so_far' => $total_updated,
                    'total_skipped_so_far' => $total_skipped,
                    'remaining_users' => $total_users - ($offset + $batch_processed)
                ));

                // Update last run statistics after each batch
                $end_time = microtime(true);
                $execution_time = round($end_time - $start_time, 2);
                
                update_option('mdm_auto_calc_last_run_stats', array(
                    'records_processed' => $total_processed,
                    'records_updated' => $total_updated,
                    'records_skipped' => $total_skipped,
                    'execution_time' => $execution_time,
                    'last_run' => current_time('mysql'),
                    'is_running' => true,
                    'progress' => round(($total_processed / $total_users) * 100, 2)
                ));

                // Move to next batch
                $offset += $batch_size;
            }

            $end_time = microtime(true);
            $execution_time = round($end_time - $start_time, 2);

            // Final update of last run statistics
            $stats = array(
                'records_processed' => $total_processed,
                'records_updated' => $total_updated,
                'records_skipped' => $total_skipped,
                'execution_time' => $execution_time,
                'last_run' => current_time('mysql'),
                'is_running' => false,
                'progress' => 100
            );

            update_option('mdm_auto_calc_last_run_stats', $stats);
            update_option('mdm_auto_calc_last_run', time());

            $this->logger->info('[CRON] Completed automatic tier calculation', array(
                'total_processed' => $total_processed,
                'total_updated' => $total_updated,
                'total_skipped' => $total_skipped,
                'execution_time_seconds' => $execution_time,
                'next_scheduled' => wp_next_scheduled('mdm_auto_calculation') ? 
                    wp_date('Y-m-d H:i:s', wp_next_scheduled('mdm_auto_calculation')) : 'Not scheduled'
            ));

        } catch (\Exception $e) {
            $this->logger->error('[CRON] Error during automatic tier calculation', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            
            // Update last run status with error
            update_option('mdm_auto_calc_last_run', time());
            update_option('mdm_auto_calc_last_run_stats', array(
                'error' => true,
                'error_message' => $e->getMessage(),
                'last_run' => current_time('mysql'),
                'is_running' => false
            ));
        }
    }

    /**
     * Calculate and update user tier
     *
     * @param int $user_id User ID
     * @return bool Whether the tier was updated
     */
    private function calculate_and_update_user_tier($user_id) {
        // Get user's spending data
        $total_spend = get_user_meta($user_id, '_wc_memberships_profile_field_total_spend_all_time', true);
        $yearly_spend = get_user_meta($user_id, '_wc_memberships_profile_field_average_spend_last_year', true);
        
        // Calculate new tier based on spending
        $new_tier = $this->calculate_tier($total_spend, $yearly_spend);
        
        // Get current tier
        $current_tier = get_user_meta($user_id, '_wc_memberships_profile_field_discount_tier', true);
        
        // Update if different
        if ($new_tier !== $current_tier) {
            update_user_meta($user_id, '_wc_memberships_profile_field_discount_tier', $new_tier);
            update_user_meta($user_id, '_wc_memberships_profile_field_discount_last_sync', current_time('mysql'));
            return true;
        }
        
        return false;
    }

    /**
     * Calculate tier based on spending
     *
     * @param float $total_spend Total lifetime spend
     * @param float $yearly_spend Yearly average spend
     * @return string Tier name
     */
    private function calculate_tier($total_spend, $yearly_spend) {
        // Convert to numbers
        $total_spend = floatval($total_spend);
        $yearly_spend = floatval($yearly_spend);

        // Define tier thresholds
        if ($yearly_spend >= 10000 || $total_spend >= 50000) {
            return 'Platinum';
        } elseif ($yearly_spend >= 5000 || $total_spend >= 25000) {
            return 'Gold';
        } elseif ($yearly_spend >= 2500 || $total_spend >= 10000) {
            return 'Silver';
        } elseif ($yearly_spend >= 1000 || $total_spend >= 5000) {
            return 'Bronze';
        }

        return 'None';
    }
} 