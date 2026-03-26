<?php
/**
 * Pricing rules table template.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<?php include WCCG_PATH . 'admin/views/html-pricing-rules-modal.php'; ?>
<?php include WCCG_PATH . 'admin/views/html-pricing-rules-toolbar.php'; ?>
<div class="wccg-pricing-rules-table-wrapper">
    <table class="wp-list-table widefat fixed striped wccg-pricing-rules-table" aria-label="<?php esc_attr_e('Pricing Rules', 'alynt-customer-groups'); ?>">
        <thead>
            <tr>
                <th class="wccg-drag-handle-header" scope="col" style="width: 30px;"><span class="screen-reader-text"><?php esc_html_e('Reorder', 'alynt-customer-groups'); ?></span></th>
                <th scope="col"><?php esc_html_e('Status', 'alynt-customer-groups'); ?></th>
                <th scope="col"><?php esc_html_e('Group Name', 'alynt-customer-groups'); ?></th>
                <th scope="col"><?php esc_html_e('Discount Type', 'alynt-customer-groups'); ?></th>
                <th scope="col"><?php esc_html_e('Discount Value', 'alynt-customer-groups'); ?></th>
                <th scope="col"><?php esc_html_e('Assigned Products', 'alynt-customer-groups'); ?></th>
                <th scope="col"><?php esc_html_e('Assigned Categories', 'alynt-customer-groups'); ?></th>
                <th scope="col"><?php esc_html_e('Schedule', 'alynt-customer-groups'); ?></th>
                <th scope="col"><?php esc_html_e('Created', 'alynt-customer-groups'); ?></th>
                <th scope="col"><?php esc_html_e('Actions', 'alynt-customer-groups'); ?></th>
            </tr>
        </thead>
        <tbody id="wccg-sortable-rules">
            <?php foreach ($pricing_rules_view as $rule) : ?>
                <tr data-rule-id="<?php echo esc_attr($rule['rule_id']); ?>" class="wccg-sortable-row">
                    <td class="wccg-drag-handle">
                        <span class="dashicons dashicons-menu" aria-hidden="true" title="<?php esc_attr_e('Drag to reorder', 'alynt-customer-groups'); ?>"></span>
                        <button type="button" class="wccg-reorder-btn wccg-reorder-up screen-reader-text" data-direction="up" data-rule-id="<?php echo esc_attr($rule['rule_id']); ?>" aria-label="<?php printf(esc_attr__('Move rule for %s up', 'alynt-customer-groups'), $rule['group_name']); ?>"><?php esc_html_e('Move up', 'alynt-customer-groups'); ?></button>
                        <button type="button" class="wccg-reorder-btn wccg-reorder-down screen-reader-text" data-direction="down" data-rule-id="<?php echo esc_attr($rule['rule_id']); ?>" aria-label="<?php printf(esc_attr__('Move rule for %s down', 'alynt-customer-groups'), $rule['group_name']); ?>"><?php esc_html_e('Move down', 'alynt-customer-groups'); ?></button>
                    </td>
                    <td>
                        <label class="wccg-toggle-switch">
                            <input type="checkbox" class="wccg-rule-toggle" data-rule-id="<?php echo esc_attr($rule['rule_id']); ?>" aria-label="<?php printf(esc_attr__('Enable rule for %s', 'alynt-customer-groups'), $rule['group_name']); ?>" <?php checked($rule['is_active'], 1); ?>>
                            <span class="wccg-toggle-slider"></span>
                        </label>
                        <span class="wccg-status-text"><?php echo $rule['is_active'] ? esc_html__('Active', 'alynt-customer-groups') : esc_html__('Inactive', 'alynt-customer-groups'); ?></span>
                    </td>
                    <td><?php echo esc_html($rule['group_name']); ?></td>
                    <td>
                        <?php echo esc_html(ucfirst($rule['discount_type'])); ?>
                        <?php if ($rule['discount_type'] === 'fixed') : ?>
                            <span class="dashicons dashicons-star-filled" aria-hidden="true" title="<?php esc_attr_e('Fixed discounts take precedence', 'alynt-customer-groups'); ?>"></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($rule['discount_value_display']); ?></td>
                    <td>
                        <?php if (!empty($rule['product_names'])) : ?>
                            <span class="rule-type-indicator product"><?php esc_html_e('Product Rule', 'alynt-customer-groups'); ?></span><br>
                            <?php echo esc_html(implode(', ', $rule['product_names'])); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($rule['category_names'])) : ?>
                            <span class="rule-type-indicator category"><?php esc_html_e('Category Rule', 'alynt-customer-groups'); ?></span><br>
                            <?php echo esc_html(implode(', ', $rule['category_names'])); ?>
                        <?php endif; ?>
                    </td>
                    <td class="wccg-schedule-cell <?php echo !$rule['is_active'] ? 'wccg-schedule-inactive' : ''; ?>">
                        <?php echo $rule['schedule']['badge_html']; ?>
                        <?php echo $rule['schedule']['display_html']; ?>
                        <?php if (!$rule['is_active'] && $rule['schedule']['has_schedule']) : ?>
                            <div class="wccg-schedule-warning">
                                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                <?php esc_html_e('Rule is inactive', 'alynt-customer-groups'); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($rule['created_at']))); ?></td>
                    <td>
                        <div class="wccg-actions-wrapper">
                            <button type="button" class="wccg-edit-rule-btn" data-rule-id="<?php echo esc_attr($rule['rule_id']); ?>" title="<?php esc_attr_e('Edit Rule', 'alynt-customer-groups'); ?>" aria-label="<?php printf(esc_attr__('Edit rule for %s', 'alynt-customer-groups'), $rule['group_name']); ?>">
                                <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                                <span class="button-text"><?php esc_html_e('Edit', 'alynt-customer-groups'); ?></span>
                            </button>
                            <button type="button" class="wccg-edit-schedule-btn" data-rule-id="<?php echo esc_attr($rule['rule_id']); ?>" data-is-active="<?php echo esc_attr($rule['is_active']); ?>" data-start-date="<?php echo esc_attr($rule['start_date'] ?? ''); ?>" data-end-date="<?php echo esc_attr($rule['end_date'] ?? ''); ?>" title="<?php echo esc_attr($rule['is_active'] ? __('Edit Schedule', 'alynt-customer-groups') : __('Note: Rule is currently inactive. Enable the toggle for schedule to take effect.', 'alynt-customer-groups')); ?>" aria-label="<?php printf(esc_attr__('Edit schedule for %s', 'alynt-customer-groups'), $rule['group_name']); ?>">
                                <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                                <span class="button-text"><?php esc_html_e('Schedule', 'alynt-customer-groups'); ?></span>
                            </button>
                            <form method="post" class="wccg-delete-rule-form">
                                <?php wp_nonce_field('wccg_pricing_rules_action', 'wccg_pricing_rules_nonce'); ?>
                                <input type="hidden" name="action" value="delete_rule">
                                <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule['rule_id']); ?>">
                                <button type="submit" class="button-link-delete" title="<?php esc_attr_e('Delete Rule', 'alynt-customer-groups'); ?>" aria-label="<?php printf(esc_attr__('Delete rule for %s', 'alynt-customer-groups'), $rule['group_name']); ?>" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this pricing rule?', 'alynt-customer-groups'); ?>');">
                                    <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                    <span class="button-text"><?php esc_html_e('Delete', 'alynt-customer-groups'); ?></span>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <tr class="wccg-schedule-edit-row" id="edit-schedule-<?php echo esc_attr($rule['rule_id']); ?>" style="display: none;">
                    <td colspan="10">
                        <div class="wccg-schedule-edit-form">
                            <h4><?php esc_html_e('Edit Schedule', 'alynt-customer-groups'); ?></h4>
                            <?php if (!$rule['is_active']) : ?>
                                <div class="wccg-inactive-rule-warning">
                                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                    <strong><?php esc_html_e('Warning:', 'alynt-customer-groups'); ?></strong>
                                    <?php esc_html_e('This rule is currently inactive. The schedule will not take effect until you enable the rule using the toggle switch.', 'alynt-customer-groups'); ?>
                                </div>
                            <?php endif; ?>
                            <div class="wccg-edit-schedule-fields">
                                <div class="wccg-edit-field">
                                    <label for="edit-start-date-<?php echo esc_attr($rule['rule_id']); ?>"><?php esc_html_e('Start Date & Time:', 'alynt-customer-groups'); ?></label>
                                    <input type="datetime-local" id="edit-start-date-<?php echo esc_attr($rule['rule_id']); ?>" class="wccg-edit-start-date" value="<?php echo esc_attr($rule['start_local']); ?>">
                                </div>
                                <div class="wccg-edit-field">
                                    <label for="edit-end-date-<?php echo esc_attr($rule['rule_id']); ?>"><?php esc_html_e('End Date & Time:', 'alynt-customer-groups'); ?></label>
                                    <input type="datetime-local" id="edit-end-date-<?php echo esc_attr($rule['rule_id']); ?>" class="wccg-edit-end-date" value="<?php echo esc_attr($rule['end_local']); ?>">
                                </div>
                            </div>
                            <div class="wccg-edit-schedule-actions">
                                <button type="button" class="button button-primary wccg-save-schedule-btn" data-rule-id="<?php echo esc_attr($rule['rule_id']); ?>"><?php esc_html_e('Save Schedule', 'alynt-customer-groups'); ?></button>
                                <button type="button" class="button wccg-cancel-schedule-btn" data-rule-id="<?php echo esc_attr($rule['rule_id']); ?>"><?php esc_html_e('Cancel', 'alynt-customer-groups'); ?></button>
                                <span class="wccg-save-status wccg-schedule-edit-message" role="status" aria-live="polite" aria-atomic="true"></span>
                            </div>
                            <p class="description"><?php printf(esc_html__('Leave fields blank to remove schedule restrictions. Times are in %s timezone.', 'alynt-customer-groups'), '<code>' . esc_html(wp_timezone_string()) . '</code>'); ?></p>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($pricing_rules_view)) : ?>
                <tr>
                    <td colspan="10" class="no-items"><?php esc_html_e('No pricing rules found.', 'alynt-customer-groups'); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

