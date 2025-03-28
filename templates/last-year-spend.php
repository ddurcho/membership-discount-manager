<?php defined('ABSPATH') || exit; ?>

<div class="wrap">
    <h1><?php echo esc_html__('Last Year Customer Spending', 'membership-discount-manager'); ?></h1>

    <form method="get">
        <input type="hidden" name="page" value="nestwork-last-year">
        <p class="search-box">
            <label class="screen-reader-text" for="user-search-input"><?php esc_html_e('Search Users:', 'membership-discount-manager'); ?></label>
            <input type="search" id="user-search-input" name="s" value="<?php echo esc_attr($search); ?>">
            <input type="submit" id="search-submit" class="button" value="<?php esc_attr_e('Search Users', 'membership-discount-manager'); ?>">
        </p>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Customer', 'membership-discount-manager'); ?></th>
                <th><?php esc_html_e('Email', 'membership-discount-manager'); ?></th>
                <th><?php esc_html_e('Total Sales', 'membership-discount-manager'); ?></th>
                <th><?php esc_html_e('Total Tax', 'membership-discount-manager'); ?></th>
                <th><?php esc_html_e('Net Total', 'membership-discount-manager'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($results)) : ?>
                <?php foreach ($results as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row->full_name); ?></td>
                        <td><?php echo esc_html($row->user_email); ?></td>
                        <td><?php echo wc_price($row->total_sales_last_year); ?></td>
                        <td><?php echo wc_price($row->total_tax_last_year); ?></td>
                        <td><?php echo wc_price($row->total_net_last_year); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="5"><?php esc_html_e('No customers found.', 'membership-discount-manager'); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1) : ?>
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
    <?php endif; ?>
</div> 