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
        $schedules['two_minutes'] = array(
            'interval' => 120, // 2 minutes in seconds
            'display' => __('Every 2 minutes', 'membership-discount-manager')
        );
        
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
            // Set initial state immediately
            update_option('mdm_auto_calc_last_run_stats', array(
                'is_running' => true,
                'progress' => 0,
                'last_run' => current_time('mysql'),
                'records_processed' => 0,
                'records_updated' => 0,
                'records_skipped' => 0,
                'records_errored' => 0,
                'start_time' => current_time('mysql'),
                'pid' => getmypid()
            ));

            // Check if already running and not timed out
            $current_stats = get_option('mdm_auto_calc_last_run_stats', array());
            $last_run_time = isset($current_stats['last_run']) ? strtotime($current_stats['last_run']) : 0;
            
            // If it's been running for more than 15 minutes, consider it timed out
            if (isset($current_stats['is_running']) && $current_stats['is_running'] && 
                (time() - $last_run_time) < 900) { // 15 minutes in seconds
                
                // Double check if the process is still running
                if (isset($current_stats['pid']) && $current_stats['pid'] && function_exists('posix_kill')) {
                    if (posix_kill($current_stats['pid'], 0)) {
                        $this->logger->warning('[CRON] Another instance is still running, skipping this run');
                        return;
                    }
                } else {
                    $this->logger->warning('[CRON] Another instance is already running, skipping this run');
                    return;
                }
            }

            // Reset the running state in case of previous timeout
            if (isset($current_stats['is_running']) && $current_stats['is_running']) {
                $this->logger->warning('[CRON] Previous run appears to have timed out, resetting state');
            }

            $this->logger->info('[CRON] Starting automatic tier calculation');
            
            global $wpdb;
            $admin = new \MembershipDiscountManager\Admin();

            // Get batch size from settings
            $batch_size = get_option('mdm_sync_batch_size', 20);
            $offset = 0;
            $total_processed = 0;
            $total_updated = 0;
            $total_skipped = 0;
            $total_errors = 0;
            $start_time = microtime(true);

            // First, get total count of users
            $count_query = "
                SELECT COUNT(DISTINCT u.ID) as total
                FROM {$wpdb->users} u
                LEFT JOIN {$wpdb->prefix}wc_customer_lookup cl ON u.ID = cl.user_id
                LEFT JOIN {$wpdb->prefix}wc_order_stats os ON cl.customer_id = os.customer_id AND os.status = 'wc-completed'
                WHERE u.ID > 0
            ";
            $total_users = $wpdb->get_var($count_query);

            if (!$total_users) {
                throw new \Exception('No users found to process');
            }

            // Log the start of processing
            $this->logger->info('[CRON] Starting batch processing', array(
                'total_users' => $total_users,
                'batch_size' => $batch_size,
                'pid' => getmypid()
            ));

            // Process users in batches
            while ($offset < $total_users) {
                $batch_start_time = microtime(true);

                // Get users for this batch
                $users_query = $wpdb->prepare("
                    SELECT 
                        u.ID as user_id,
                        u.user_email,
                        COALESCE(ROUND(
                            SUM(CASE 
                                WHEN os.date_created >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                                THEN os.total_sales 
                                ELSE 0 
                            END), 2
                        ), 0) as yearly_spend,
                        COALESCE(ROUND(SUM(os.total_sales), 2), 0) as total_spend
                    FROM {$wpdb->users} u
                    LEFT JOIN {$wpdb->prefix}wc_customer_lookup cl ON u.ID = cl.user_id
                    LEFT JOIN {$wpdb->prefix}wc_order_stats os ON cl.customer_id = os.customer_id AND os.status = 'wc-completed'
                    WHERE u.ID > 0
                    GROUP BY u.ID, u.user_email
                    ORDER BY u.ID ASC
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
                $batch_errors = 0;

                foreach ($users as $user) {
                    try {
                        // Update spending data
                        update_user_meta($user->user_id, '_wc_memberships_profile_field_average_spend_last_year', $user->yearly_spend);
                        update_user_meta($user->user_id, '_wc_memberships_profile_field_total_spend_all_time', $user->total_spend);

                        // Skip if manual override is enabled
                        $manual_override = get_user_meta($user->user_id, '_wc_memberships_profile_field_manual_discount_override', true);
                        if ($manual_override === 'yes') {
                            $batch_skipped++;
                            $batch_processed++;
                            continue;
                        }

                        // Calculate and update tier
                        if ($admin->calculate_and_update_user_tier($user->user_id)) {
                            $batch_updated++;
                        }
                        
                        $batch_processed++;
                        
                        // Update progress for each user
                        if ($batch_processed % 5 == 0) { // Update every 5 users
                            $total_processed_temp = $total_processed + $batch_processed;
                            $progress = round(($total_processed_temp / $total_users) * 100, 2);
                            update_option('mdm_auto_calc_last_run_stats', array(
                                'is_running' => true,
                                'progress' => $progress,
                                'last_run' => current_time('mysql'),
                                'records_processed' => $total_processed_temp,
                                'records_updated' => $total_updated + $batch_updated,
                                'records_skipped' => $total_skipped + $batch_skipped,
                                'records_errored' => $total_errors + $batch_errors,
                                'total_users' => $total_users,
                                'pid' => getmypid()
                            ));
                        }
                    } catch (\Exception $e) {
                        $this->logger->error('[CRON] Error processing user', array(
                            'user_id' => $user->user_id,
                            'error' => $e->getMessage()
                        ));
                        $batch_errors++;
                        continue;
                    }
                }

                // Update totals
                $total_processed += $batch_processed;
                $total_updated += $batch_updated;
                $total_skipped += $batch_skipped;
                $total_errors += $batch_errors;

                // Update progress after each batch
                $progress = round(($total_processed / $total_users) * 100, 2);
                update_option('mdm_auto_calc_last_run_stats', array(
                    'is_running' => true,
                    'progress' => $progress,
                    'last_run' => current_time('mysql'),
                    'records_processed' => $total_processed,
                    'records_updated' => $total_updated,
                    'records_skipped' => $total_skipped,
                    'records_errored' => $total_errors,
                    'total_users' => $total_users,
                    'pid' => getmypid()
                ));

                $offset += $batch_size;
                
                // Add a small delay between batches
                usleep(100000); // 100ms delay
            }

            $execution_time = round(microtime(true) - $start_time, 2);

            // Final update of last run statistics
            update_option('mdm_auto_calc_last_run_stats', array(
                'is_running' => false,
                'progress' => 100,
                'last_run' => current_time('mysql'),
                'records_processed' => $total_processed,
                'records_updated' => $total_updated,
                'records_skipped' => $total_skipped,
                'records_errored' => $total_errors,
                'execution_time' => $execution_time,
                'total_users' => $total_users,
                'completed' => true,
                'end_time' => current_time('mysql')
            ));

            $this->logger->info('[CRON] Completed automatic tier calculation', array(
                'total_processed' => $total_processed,
                'total_updated' => $total_updated,
                'total_skipped' => $total_skipped,
                'total_errors' => $total_errors,
                'execution_time' => $execution_time,
                'next_scheduled' => wp_next_scheduled('mdm_auto_calculation')
            ));

        } catch (\Exception $e) {
            $this->logger->error('[CRON] Error during automatic tier calculation', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            
            // Update last run status with error
            update_option('mdm_auto_calc_last_run_stats', array(
                'is_running' => false,
                'error' => true,
                'error_message' => $e->getMessage(),
                'last_run' => current_time('mysql'),
                'records_processed' => $total_processed ?? 0,
                'records_updated' => $total_updated ?? 0,
                'records_skipped' => $total_skipped ?? 0,
                'records_errored' => ($total_errors ?? 0) + 1,
                'total_users' => $total_users ?? 0,
                'completed' => false,
                'end_time' => current_time('mysql')
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