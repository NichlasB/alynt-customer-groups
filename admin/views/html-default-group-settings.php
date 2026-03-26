<?php
/**
 * Default group settings panel template.
 *
 * @package Alynt_Customer_Groups
 * @since   1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wccg-default-group-section" style="background: #f0f0f1; padding: 15px; margin: 20px 0; border-left: 4px solid #2271b1;">
    <h2 style="margin-top: 0;"><?php esc_html_e('Default Group for Ungrouped Customers', 'alynt-customer-groups'); ?></h2>
    <p><?php esc_html_e('Select a group to apply pricing rules to customers who are not assigned to any group. This is useful for retail customers or promotional pricing.', 'alynt-customer-groups'); ?></p>

    <form method="post" style="margin-top: 15px;">
        <?php wp_nonce_field('wccg_customer_groups_action', 'wccg_customer_groups_nonce'); ?>
        <input type="hidden" name="action" value="set_default_group">

        <table class="form-table" style="margin-top: 0;">
            <tr>
                <th scope="row" style="padding-top: 0;">
                    <label for="default_group_id"><?php esc_html_e('Default Group', 'alynt-customer-groups'); ?></label>
                </th>
                <td style="padding-top: 0;">
                    <select name="default_group_id" id="default_group_id" class="regular-text">
                        <option value="0" <?php selected($default_group_id, 0); ?>>
                            <?php esc_html_e('None (Ungrouped customers see regular prices)', 'alynt-customer-groups'); ?>
                        </option>
                        <?php foreach ($groups as $group) : ?>
                            <option value="<?php echo esc_attr($group->group_id); ?>" <?php selected($default_group_id, $group->group_id); ?>>
                                <?php echo esc_html($group->group_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($default_group_id > 0) : ?>
                        <p class="description">
                            <span class="dashicons dashicons-yes-alt" aria-hidden="true" style="color: #46b450;"></span>
                            <?php printf(esc_html__('Currently set to: %s', 'alynt-customer-groups'), '<strong>' . esc_html($default_group_name) . '</strong>'); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="custom_title"><?php esc_html_e('Custom Title', 'alynt-customer-groups'); ?></label>
                </th>
                <td>
                    <input type="text" name="custom_title" id="custom_title" class="regular-text" value="<?php echo esc_attr($custom_title); ?>" placeholder="<?php esc_attr_e('e.g., Thanksgiving, Holiday Sale, VIP', 'alynt-customer-groups'); ?>">
                    <p class="description">
                        <?php esc_html_e('Custom title shown in the site banner and cart/checkout labels (e.g., "Enjoy [Title] pricing" and "[Title] Pricing Applied"). Leave empty to use the group name.', 'alynt-customer-groups'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Update Default Group', 'alynt-customer-groups'), 'primary', '', false); ?>
    </form>
</div>

