<?php
/**
 * User Assignments admin page template.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e('User Assignments', 'alynt-customer-groups'); ?></h1>
    <?php settings_errors('wccg_user_assignments'); ?>

    <form method="get">
        <input type="hidden" name="page" value="wccg_user_assignments">
        <div class="tablenav top">
            <div class="alignleft actions">
                <label for="user-search" class="screen-reader-text"><?php esc_html_e('Search users', 'alynt-customer-groups'); ?></label>
                <input type="text" id="user-search" name="search" value="<?php echo esc_attr($params['search']); ?>" placeholder="<?php esc_attr_e('Search users...', 'alynt-customer-groups'); ?>">
                <label for="date-from" class="screen-reader-text"><?php esc_html_e('Date registered from', 'alynt-customer-groups'); ?></label>
                <input type="date" id="date-from" name="date_from" value="<?php echo esc_attr($params['date_from']); ?>">
                <label for="date-to" class="screen-reader-text"><?php esc_html_e('Date registered to', 'alynt-customer-groups'); ?></label>
                <input type="date" id="date-to" name="date_to" value="<?php echo esc_attr($params['date_to']); ?>">
                <label for="per_page" class="screen-reader-text"><?php esc_html_e('Users per page', 'alynt-customer-groups'); ?></label>
                <select name="per_page" id="per_page">
                    <?php foreach (array(100, 200, 500, 1000) as $option) : ?>
                        <option value="<?php echo esc_attr($option); ?>" <?php selected($params['users_per_page'] === $option); ?>>
                            <?php echo esc_html($option . ' per page'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="group-filter" class="screen-reader-text"><?php esc_html_e('Filter by group', 'alynt-customer-groups'); ?></label>
                <select name="group_filter" id="group-filter">
                    <option value="0"><?php esc_html_e('All Groups', 'alynt-customer-groups'); ?></option>
                    <?php foreach ($groups as $group) : ?>
                        <option value="<?php echo esc_attr($group->group_id); ?>" <?php selected($params['group_filter'], $group->group_id); ?>>
                            <?php echo esc_html($group->group_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" class="button" value="<?php esc_attr_e('Apply', 'alynt-customer-groups'); ?>">
            </div>
            <?php
            echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo;'),
                'next_text' => __('&raquo;'),
                'total' => ceil($total_users / $params['users_per_page']),
                'current' => $params['current_page'],
                'add_args' => array(
                    'search' => $params['search'],
                    'per_page' => $params['users_per_page'],
                    'orderby' => $params['orderby'],
                    'order' => $params['order'],
                    'group_filter' => $params['group_filter'],
                    'date_from' => $params['date_from'],
                    'date_to' => $params['date_to']
                )
            ));
            ?>
        </div>
    </form>

    <form method="post">
        <?php wp_nonce_field('wccg_user_assignments_action', 'wccg_user_assignments_nonce'); ?>
        <input type="hidden" name="action" value="assign_users">

        <table class="wp-list-table widefat fixed striped" aria-label="<?php esc_attr_e('User Assignments', 'alynt-customer-groups'); ?>">
            <thead>
                <tr>
                    <th scope="col" style="width: 50px;">#</th>
                    <th scope="col"><label for="select-all-users" class="screen-reader-text"><?php esc_html_e('Select all users', 'alynt-customer-groups'); ?></label><input type="checkbox" id="select-all-users"></th>
                    <th scope="col"><a href="<?php echo esc_url($sorting_urls['display_name']); ?>"><?php esc_html_e('Name', 'alynt-customer-groups'); ?><?php echo $sort_indicators['display_name']; ?></a></th>
                    <th scope="col"><a href="<?php echo esc_url($sorting_urls['user_email']); ?>"><?php esc_html_e('Email', 'alynt-customer-groups'); ?><?php echo $sort_indicators['user_email']; ?></a></th>
                    <th scope="col"><a href="<?php echo esc_url($sorting_urls['user_registered']); ?>"><?php esc_html_e('Registered', 'alynt-customer-groups'); ?><?php echo $sort_indicators['user_registered']; ?></a></th>
                    <th scope="col"><?php esc_html_e('Current Group', 'alynt-customer-groups'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $counter = ($params['current_page'] - 1) * $params['users_per_page'] + 1; ?>
                <?php foreach ($users as $user) : ?>
                    <?php $display_name = empty($user->first_name) && empty($user->last_name) ? esc_html($user->user_login) : esc_html(trim($user->first_name . ' ' . $user->last_name)); ?>
                    <tr>
                        <td style="text-align: center;"><?php echo esc_html($counter++); ?></td>
                        <td><input type="checkbox" name="user_ids[]" value="<?php echo esc_attr($user->ID); ?>" aria-label="<?php printf(esc_attr__('Select %s', 'alynt-customer-groups'), $display_name); ?>"></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=alynt-wc-customer-order-manager-edit&id=' . $user->ID)); ?>"><?php echo $display_name; ?></a>
                        </td>
                        <td><?php echo esc_html($user->user_email); ?></td>
                        <td><?php echo esc_html(date('Y-m-d', strtotime($user->user_registered))); ?></td>
                        <td><?php echo $query->get_user_group_name($user->ID, $user_groups, $groups); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="tablenav bottom">
            <div class="alignleft actions bulkactions">
                <h2><?php esc_html_e('Assign to Group', 'alynt-customer-groups'); ?></h2>
                <label for="assign_group_id" class="screen-reader-text"><?php esc_html_e('Assign to Group', 'alynt-customer-groups'); ?></label>
                <select name="group_id" id="assign_group_id" required>
                    <?php foreach ($groups as $group) : ?>
                        <option value="<?php echo esc_attr($group->group_id); ?>"><?php echo esc_html($group->group_name); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button(__('Assign Selected Users', 'alynt-customer-groups'), 'primary', 'submit', false); ?>
                <button type="submit" name="action" value="unassign_users" class="button" style="margin-left: 10px;"><?php esc_html_e('Unassign Selected Users', 'alynt-customer-groups'); ?></button>
                <button type="submit" name="export_csv" class="button" style="margin-left: 10px;"><?php esc_html_e('Export Selected Users to CSV', 'alynt-customer-groups'); ?></button>
            </div>
        </div>
    </form>
</div>

