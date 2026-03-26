export function initPricingRulesPage($) {
    const strings = (window.wccg_admin && window.wccg_admin.strings) || {};

    if (!$('body').hasClass('customer-groups_page_wccg_pricing_rules')) {
        return;
    }

    $('#discount_value').off('input.wccg').on('input.wccg', function() {
        const value = parseFloat($(this).val());
        const type = $('#discount_type').val();
        if (type === 'percentage' && (value < 0 || value > 100)) {
            alert(strings.discount_percentage_range || 'Percentage discount must be between 0 and 100.');
            $(this).val('');
        }
        if (type === 'fixed' && value < 0) {
            alert(strings.discount_fixed_negative || 'Fixed discount cannot be negative.');
            $(this).val('');
        }
    });

    $('.wccg-rule-toggle').off('change.wccg').on('change.wccg', function(e) {
        const $toggle = $(this);
        if ($toggle.data('programmatic-change')) {
            $toggle.data('programmatic-change', false);
            return;
        }
        if ($toggle.data('is-updating')) {
            e.preventDefault();
            return false;
        }

        const ruleId = $toggle.data('rule-id');
        const newStatus = $toggle.prop('checked') ? 1 : 0;
        $toggle.data('is-updating', true).prop('disabled', true);

        $.ajax({
            url: wccg_pricing_rules.ajax_url,
            type: 'POST',
            data: {
                action: 'wccg_toggle_pricing_rule',
                nonce: wccg_pricing_rules.nonce,
                rule_id: ruleId,
                new_status: newStatus
            },
            success(response) {
                if (!response.success) {
                    alert((strings.error_prefix || 'Error:') + ' ' + response.data.message);
                    $toggle.data('programmatic-change', true).prop('checked', !$toggle.prop('checked'));
                    return;
                }

                const isActive = parseInt(response.data.is_active, 10);
                const isChecked = isActive === 1;
                const $row = $toggle.closest('tr');
                const $scheduleCell = $row.find('.wccg-schedule-cell');
                const $statusText = $toggle.closest('td').find('.wccg-status-text');
                const $editBtn = $row.find('.wccg-edit-schedule-btn');
                const $editRow = $row.next('.wccg-schedule-edit-row');
                const $warning = $editRow.find('.wccg-inactive-rule-warning');
                const hasSchedule = $scheduleCell.find('.wccg-schedule-dates').length > 0;

                $statusText.text(isChecked ? (strings.status_active || 'Active') : (strings.status_inactive || 'Inactive'));
                $toggle.data('programmatic-change', true).prop('checked', isChecked);
                $editBtn.attr('data-is-active', isActive);

                if (isChecked) {
                    $scheduleCell.removeClass('wccg-schedule-inactive');
                    $scheduleCell.find('.wccg-schedule-warning').remove();
                    $editBtn.removeAttr('title');
                    $warning.remove();
                } else {
                    $scheduleCell.addClass('wccg-schedule-inactive');
                    if (hasSchedule && !$scheduleCell.find('.wccg-schedule-warning').length) {
                        $scheduleCell.append('<div class="wccg-schedule-warning"><span class="dashicons dashicons-warning"></span>' + (strings.rule_inactive || 'Rule is inactive') + '</div>');
                    }
                    $editBtn.attr('title', strings.inactive_schedule_title || 'Note: Rule is currently inactive. Enable the toggle for schedule to take effect.');
                    if ($editRow.is(':visible') && !$warning.length) {
                        $editRow.find('.wccg-schedule-edit-form h4').after('<div class="wccg-inactive-rule-warning"><span class="dashicons dashicons-warning"></span><strong>' + (strings.warning_label || 'Warning:') + '</strong> ' + (strings.inactive_schedule_warning || 'This rule is currently inactive. The schedule will not take effect until you enable the rule using the toggle switch.') + '</div>');
                    }
                }
            },
            error() {
                alert(strings.failed_update_rule_status || 'Failed to update rule status. Please try again.');
                $toggle.data('programmatic-change', true).prop('checked', !$toggle.prop('checked'));
            },
            complete() {
                $toggle.data('is-updating', false).prop('disabled', false);
            }
        });
    });

    $('#wccg-enable-all-rules, #wccg-disable-all-rules').off('click.wccg').on('click.wccg', function(e) {
        e.preventDefault();
        const disable = $(this).attr('id') === 'wccg-disable-all-rules';
        if (disable && !confirm(strings.disable_all_confirm || 'Are you sure you want to disable all pricing rules?')) {
            return;
        }

        const $button = $(this);
        const originalText = $button.text();
        const status = disable ? 0 : 1;
        $button.prop('disabled', true).text(disable ? (strings.disabling || 'Disabling...') : (strings.enabling || 'Enabling...'));

        $.ajax({
            url: wccg_pricing_rules.ajax_url,
            type: 'POST',
            data: {
                action: 'wccg_bulk_toggle_pricing_rules',
                nonce: wccg_pricing_rules.nonce,
                status
            },
            success(response) {
                if (response.success) {
                    $('.wccg-rule-toggle').each(function() {
                        $(this).data('programmatic-change', true).prop('checked', !!status).closest('td').find('.wccg-status-text').text(status ? (strings.status_active || 'Active') : (strings.status_inactive || 'Inactive'));
                    });
                    alert(response.data.message);
                } else {
                    alert((strings.error_prefix || 'Error:') + ' ' + response.data.message);
                }
            },
            error() {
                alert(status ? (strings.failed_enable_pricing || 'Failed to enable pricing rules. Please try again.') : (strings.failed_disable_pricing || 'Failed to disable pricing rules. Please try again.'));
            },
            complete() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    });

    $('#wccg-delete-all-rules').off('click.wccg').on('click.wccg', function(e) {
        e.preventDefault();
        if (!confirm(strings.delete_all_confirm_one || 'Are you sure you want to delete ALL pricing rules? This action cannot be undone!')) {
            return;
        }
        if (!confirm(strings.delete_all_confirm_two || 'This will permanently delete ALL pricing rules. Are you absolutely sure?')) {
            return;
        }

        const $button = $(this);
        $button.prop('disabled', true).text(strings.deleting || 'Deleting...');
        $.ajax({
            url: wccg_pricing_rules.ajax_url,
            type: 'POST',
            data: {
                action: 'wccg_delete_all_pricing_rules',
                nonce: wccg_pricing_rules.nonce
            },
            success(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                    return;
                }
                alert((strings.error_prefix || 'Error:') + ' ' + response.data.message);
                $button.prop('disabled', false).text(strings.delete_all_label || 'Delete All Pricing Rules');
            },
            error() {
                alert(strings.failed_delete_pricing || 'Failed to delete pricing rules. Please try again.');
                $button.prop('disabled', false).text(strings.delete_all_label || 'Delete All Pricing Rules');
            }
        });
    });
}
