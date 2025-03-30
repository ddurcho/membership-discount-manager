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
        // Use transient to prevent multiple checks within a short time period
        $check_lock = get_transient('mdm_cron_check_lock');
        if ($check_lock) {
            return;
        }
        
        // Set a transient lock for 1 minute
        set_transient('mdm_cron_check_lock', true, 60);

        // Only check if auto calculation is enabled
        if (!get_option('mdm_auto_calc_enabled', false)) {
            return;
        }

        // Get current schedule status
        $next_run = wp_next_scheduled('mdm_auto_calculation');
        $frequency = get_option('mdm_auto_calc_frequency', 'daily');

        // Only log in debug mode and only if something changed
        if (get_option('mdm_debug_mode', false)) {
            $last_status = get_transient('mdm_cron_last_status');
            $current_status = array(
                'next_run' => $next_run,
                'frequency' => $frequency
            );

            if ($last_status !== $current_status) {
                $this->logger->debug('[CRON] Checking cron schedule status', array(
                    'auto_calc_enabled' => '1',
                    'current_frequency' => $frequency,
                    'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : 'Not scheduled'
                ));
                set_transient('mdm_cron_last_status', $current_status, HOUR_IN_SECONDS);
            }
        }

        // Schedule if not already scheduled
        if (!$next_run) {
            wp_schedule_event(time(), $frequency, 'mdm_auto_calculation');
            
            if (get_option('mdm_debug_mode', false)) {
                $this->logger->debug('[CRON] Scheduled new cron job', array(
                    'frequency' => $frequency,
                    'next_run' => wp_next_scheduled('mdm_auto_calculation')
                ));
            }
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

            $start_time = microtime(true);
            
            // Use the shared processing function from Admin class
            $admin = new \MembershipDiscountManager\Admin();
            $batch_size = get_option('mdm_sync_batch_size', 20);
            
            // Initialize totals
            $total_processed = 0;
            $total_updated = 0;
            $total_skipped = 0;
            $total_errors = 0;
            $offset = 0;
            $is_complete = false;

            // Process all batches
            while (!$is_complete) {
                $stats = $admin->process_sync_batch('CRON', $offset, $batch_size);
                
                // Update totals
                $total_processed += $stats['processed'];
                $total_updated += $stats['updated'];
                $total_skipped += $stats['skipped'];
                
                // Check if we're done
                $is_complete = ($offset + $stats['processed'] >= $stats['total']);
                
                // Update offset for next batch
                $offset += $batch_size;

                // Add a small delay between batches to prevent server overload
                if (!$is_complete) {
                    usleep(100000); // 0.1 second delay
                }
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
                'execution_time' => $execution_time
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
            
            // Update stats to show error state
            update_option('mdm_auto_calc_last_run_stats', array(
                'is_running' => false,
                'progress' => 0,
                'last_run' => current_time('mysql'),
                'error' => $e->getMessage()
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