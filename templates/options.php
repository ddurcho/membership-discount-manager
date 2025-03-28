<?php defined('ABSPATH') || exit; ?>

<div class="wrap">
    <h1><?php echo esc_html__('Nestwork Discounts Options', 'membership-discount-manager'); ?></h1>

    <?php settings_errors('mdm_messages'); ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=nestwork-options')); ?>">
        <?php wp_nonce_field('mdm_options_nonce'); ?>
        
        <div class="card">
            <h2><?php _e('Discount Tiers Configuration', 'membership-discount-manager'); ?></h2>
            <p><?php _e('Configure the spending thresholds and discount percentages for each tier.', 'membership-discount-manager'); ?></p>
            
            <table class="form-table">
                <thead>
                    <tr>
                        <th><?php _e('Tier', 'membership-discount-manager'); ?></th>
                        <th><?php _e('Minimum Spend ($)', 'membership-discount-manager'); ?></th>
                        <th><?php _e('Discount (%)', 'membership-discount-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $tiers = array(
                        'none' => __('None', 'membership-discount-manager'),
                        'bronze' => __('Bronze', 'membership-discount-manager'),
                        'silver' => __('Silver', 'membership-discount-manager'),
                        'gold' => __('Gold', 'membership-discount-manager'),
                        'platinum' => __('Platinum', 'membership-discount-manager'),
                    );

                    foreach ($tiers as $tier_key => $tier_name) :
                        $min_spend = isset($tier_settings[$tier_key]['min_spend']) ? $tier_settings[$tier_key]['min_spend'] : 0;
                        $discount = isset($tier_settings[$tier_key]['discount']) ? $tier_settings[$tier_key]['discount'] : 0;
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($tier_name); ?></strong></td>
                            <td>
                                <input type="number" 
                                    name="tier[<?php echo esc_attr($tier_key); ?>][min_spend]" 
                                    value="<?php echo esc_attr($min_spend); ?>" 
                                    class="regular-text" 
                                    min="0" 
                                    step="0.01"
                                    <?php echo $tier_key === 'none' ? 'readonly' : ''; ?>
                                >
                            </td>
                            <td>
                                <input type="number" 
                                    name="tier[<?php echo esc_attr($tier_key); ?>][discount]" 
                                    value="<?php echo esc_attr($discount); ?>" 
                                    class="regular-text" 
                                    min="0" 
                                    max="100" 
                                    step="0.1"
                                    <?php echo $tier_key === 'none' ? 'readonly' : ''; ?>
                                >
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="description">
                <?php _e('Note: The "None" tier always has $0 minimum spend and 0% discount.', 'membership-discount-manager'); ?>
            </p>
        </div>

        <div class="card">
            <h2><?php _e('Automatic Updates', 'membership-discount-manager'); ?></h2>
            <p><?php _e('Customer tiers are automatically updated daily based on their spending history.', 'membership-discount-manager'); ?></p>
            <p><?php _e('Last update:', 'membership-discount-manager'); ?> <strong><?php echo get_option('mdm_last_update', __('Never', 'membership-discount-manager')); ?></strong></p>
        </div>

        <div class="card">
            <h2><?php _e('Logging Settings', 'membership-discount-manager'); ?></h2>
            <p><?php _e('Configure how the plugin handles logging and debugging information.', 'membership-discount-manager'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Enable Logging', 'membership-discount-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                name="mdm_logging_enabled" 
                                value="1" 
                                <?php checked(get_option('mdm_logging_enabled', true)); ?>
                            >
                            <?php _e('Enable logging of plugin activities', 'membership-discount-manager'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Logs are stored in the plugin\'s logs directory and are automatically rotated daily.', 'membership-discount-manager'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Debug Mode', 'membership-discount-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                name="mdm_debug_mode" 
                                value="1" 
                                <?php checked(get_option('mdm_debug_mode', false)); ?>
                            >
                            <?php _e('Enable debug logging', 'membership-discount-manager'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Includes additional debug information in logs. Only enable this when troubleshooting issues.', 'membership-discount-manager'); ?>
                        </p>
                    </td>
                </tr>
                <?php if (get_option('mdm_logging_enabled', true)): ?>
                <tr>
                    <th scope="row"><?php _e('Log Management', 'membership-discount-manager'); ?></th>
                    <td>
                        <button type="button" id="mdm-clear-logs" class="button">
                            <?php _e('Clear All Logs', 'membership-discount-manager'); ?>
                        </button>
                        <p class="description">
                            <?php _e('This will permanently delete all log files.', 'membership-discount-manager'); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="card">
            <h2><?php _e('Membership Fields Sync', 'membership-discount-manager'); ?></h2>
            <p><?php _e('Update custom membership fields for all users based on their current spending data.', 'membership-discount-manager'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Last Sync', 'membership-discount-manager'); ?></th>
                    <td>
                        <strong class="mdm-last-sync-time">
                            <?php 
                            $last_sync = get_option('mdm_last_fields_sync', false);
                            echo $last_sync ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_sync)) : __('Never', 'membership-discount-manager');
                            ?>
                        </strong>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Batch Size', 'membership-discount-manager'); ?></th>
                    <td>
                        <input type="number" 
                            id="mdm-batch-size" 
                            name="mdm_sync_batch_size"
                            min="1" 
                            max="100" 
                            value="<?php echo esc_attr(get_option('mdm_sync_batch_size', 20)); ?>" 
                            class="small-text"
                        >
                        <p class="description">
                            <?php _e('Number of users to process in each batch (1-100). Lower numbers may be slower but more stable.', 'membership-discount-manager'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Fields to Update', 'membership-discount-manager'); ?></th>
                    <td>
                        <ul class="mdm-fields-list">
                            <li><code>discount_tier</code> - <?php _e('Current discount level', 'membership-discount-manager'); ?></li>
                            <li><code>average_spend_last_year</code> - <?php _e('Average spending in the past year', 'membership-discount-manager'); ?></li>
                            <li><code>total_spend_all_time</code> - <?php _e('Lifetime spending amount', 'membership-discount-manager'); ?></li>
                            <li><code>manual_discount_override</code> - <?php _e('Manual tier assignment status', 'membership-discount-manager'); ?></li>
                        </ul>
                    </td>
                </tr>
            </table>

            <div class="mdm-sync-actions">
                <button type="button" id="mdm-sync-fields" class="button button-primary">
                    <?php _e('Sync Membership Fields', 'membership-discount-manager'); ?>
                </button>
                <span class="spinner"></span>
                <div id="mdm-sync-progress" class="hidden">
                    <div class="mdm-progress-bar">
                        <div class="mdm-progress-fill"></div>
                    </div>
                    <p class="mdm-progress-status"></p>
                </div>
            </div>
        </div>

        <?php submit_button(__('Save Changes', 'membership-discount-manager')); ?>
    </form>
</div>

<style>
.card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    margin-top: 20px;
    padding: 20px;
    position: relative;
    max-width: 800px;
}

.card h2 {
    margin-top: 0;
    color: #1d2327;
    font-size: 1.3em;
    margin-bottom: 1em;
}

.form-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1em;
}

.form-table th,
.form-table td {
    padding: 15px 10px;
    text-align: left;
    border-bottom: 1px solid #f0f0f0;
}

.form-table thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #e2e4e7;
}

.form-table input[type="number"] {
    width: 150px;
}

.description {
    color: #666;
    font-style: italic;
    margin-top: 1em;
}

.regular-text {
    width: 25em;
    max-width: 100%;
}

.mdm-fields-list {
    margin: 0;
    padding: 0;
}

.mdm-fields-list li {
    margin-bottom: 8px;
}

.mdm-fields-list code {
    background: #f0f0f1;
    padding: 2px 6px;
    border-radius: 3px;
}

.mdm-sync-actions {
    margin-top: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.mdm-sync-actions .spinner {
    float: none;
    margin: 0;
}

.mdm-progress-bar {
    margin-top: 10px;
    height: 20px;
    background: #f0f0f1;
    border-radius: 10px;
    overflow: hidden;
    width: 100%;
    max-width: 400px;
}

.mdm-progress-fill {
    height: 100%;
    background: #2271b1;
    width: 0;
    transition: width 0.3s ease;
}

.mdm-progress-status {
    margin: 10px 0 0;
    font-style: italic;
}

.hidden {
    display: none;
}

.mdm-log-actions {
    margin-top: 15px;
}

#mdm-clear-logs {
    margin-right: 10px;
}

.form-table td label {
    display: block;
    margin-bottom: 5px;
}

.form-table td .description {
    margin-top: 5px;
    color: #666;
}
</style>

<script>
jQuery(document).ready(function($) {
    $('#mdm-clear-logs').on('click', function() {
        if (confirm('<?php _e('Are you sure you want to clear all log files? This cannot be undone.', 'membership-discount-manager'); ?>')) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mdm_clear_logs',
                    nonce: mdmAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('<?php _e('All log files have been cleared.', 'membership-discount-manager'); ?>');
                    } else {
                        alert('<?php _e('Failed to clear log files.', 'membership-discount-manager'); ?>');
                    }
                }
            });
        }
    });
});</script> 