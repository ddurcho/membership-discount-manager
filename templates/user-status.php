<?php defined('ABSPATH') || exit; ?>

<div class="wrap">
    <h1><?php _e('User Status', 'membership-discount-manager'); ?></h1>

    <form method="get">
        <input type="hidden" name="page" value="nestwork-user-status">
        <p class="search-box">
            <label class="screen-reader-text" for="user-search-input"><?php _e('Search Users:', 'membership-discount-manager'); ?></label>
            <input type="search" id="user-search-input" name="s" value="<?php echo esc_attr($search); ?>">
            <input type="submit" id="search-submit" class="button" value="<?php esc_attr_e('Search Users', 'membership-discount-manager'); ?>">
        </p>
    </form>

    <div class="tablenav top">
        <div class="tablenav-pages">
            <?php
            echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo;'),
                'next_text' => __('&raquo;'),
                'total' => $total_pages,
                'current' => $page,
            ));
            ?>
        </div>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col"><?php _e('Customer ID', 'membership-discount-manager'); ?></th>
                <th scope="col"><?php _e('User ID', 'membership-discount-manager'); ?></th>
                <th scope="col"><?php _e('Name', 'membership-discount-manager'); ?></th>
                <th scope="col"><?php _e('Email', 'membership-discount-manager'); ?></th>
                <th scope="col"><?php _e('Membership Status', 'membership-discount-manager'); ?></th>
                <th scope="col"><?php _e('Membership Count', 'membership-discount-manager'); ?></th>
                <th scope="col"><?php _e('Actions', 'membership-discount-manager'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($results)) : ?>
                <?php foreach ($results as $user) : ?>
                    <tr>
                        <td><?php echo esc_html($user->customer_id); ?></td>
                        <td><?php echo esc_html($user->user_id); ?></td>
                        <td><?php echo esc_html($user->full_name); ?></td>
                        <td><?php echo esc_html($user->user_email); ?></td>
                        <td>
                            <?php
                            $status_class = '';
                            $status_text = __('No Membership', 'membership-discount-manager');
                            
                            if ($user->membership_status) {
                                switch ($user->membership_status) {
                                    case 'wcm-active':
                                        $status_class = 'status-active';
                                        $status_text = __('Active', 'membership-discount-manager');
                                        break;
                                    case 'wcm-expired':
                                        $status_class = 'status-expired';
                                        $status_text = __('Expired', 'membership-discount-manager');
                                        break;
                                    case 'wcm-cancelled':
                                        $status_class = 'status-cancelled';
                                        $status_text = __('Cancelled', 'membership-discount-manager');
                                        break;
                                    default:
                                        $status_class = 'status-other';
                                        $status_text = ucfirst(str_replace('wcm-', '', $user->membership_status));
                                }
                            }
                            ?>
                            <span class="membership-status <?php echo esc_attr($status_class); ?>">
                                <?php echo esc_html($status_text); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($user->membership_count ?: '0'); ?></td>
                        <td>
                            <?php if ($user->user_id) : ?>
                                <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $user->user_id)); ?>" class="button button-small">
                                    <?php _e('Edit User', 'membership-discount-manager'); ?>
                                </a>
                                <?php if ($user->membership_count > 0) : ?>
                                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=wc_user_membership&author=' . $user->user_id)); ?>" class="button button-small">
                                        <?php _e('View Memberships', 'membership-discount-manager'); ?>
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="7"><?php _e('No users found.', 'membership-discount-manager'); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
            echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo;'),
                'next_text' => __('&raquo;'),
                'total' => $total_pages,
                'current' => $page,
            ));
            ?>
        </div>
    </div>
</div>

<style>
.membership-status {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
}

.status-active {
    background-color: #c6e1c6;
    color: #5b841b;
}

.status-expired {
    background-color: #f8dda7;
    color: #94660c;
}

.status-cancelled {
    background-color: #eba3a3;
    color: #761919;
}

.status-other {
    background-color: #e5e5e5;
    color: #777;
}

.button-small {
    margin: 2px !important;
}
</style> 