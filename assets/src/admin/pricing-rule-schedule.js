export function initPricingRuleSchedule($) {
    const strings = (window.wccg_pricing_rules && window.wccg_pricing_rules.strings) || {};

    const setStatusMessage = ($message, type, text) => {
        $message.attr('role', type === 'error' ? 'alert' : 'status');
        const color = type === 'error' ? '#dc3232' : (type === 'success' ? '#46b450' : '#666');
        $message.html('<span style="color: ' + color + ';">' + text + '</span>');
    };

    const convertUTCtoLocal = (utcString) => {
        if (!utcString) {
            return '';
        }
        const date = new Date(utcString + ' UTC');
        return [
            date.getFullYear(),
            String(date.getMonth() + 1).padStart(2, '0'),
            String(date.getDate()).padStart(2, '0')
        ].join('-') + 'T' + String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0');
    };

    const convertLocalToUTC = (localString) => {
        if (!localString) {
            return '';
        }
        return new Date(localString).toISOString().slice(0, 19).replace('T', ' ');
    };

    $(document).off('click.wccg', '.wccg-edit-schedule-btn').on('click.wccg', '.wccg-edit-schedule-btn', function() {
        const ruleId = $(this).data('rule-id');
        $('.wccg-schedule-edit-row').hide();
        $('#edit-schedule-' + ruleId).show();
    });

    $(document).off('click.wccg', '.wccg-cancel-schedule-btn').on('click.wccg', '.wccg-cancel-schedule-btn', function() {
        const ruleId = $(this).data('rule-id');
        const $editRow = $('#edit-schedule-' + ruleId);
        const $editBtn = $('.wccg-edit-schedule-btn[data-rule-id="' + ruleId + '"]');
        $editRow.find('.wccg-edit-start-date').val($editBtn.data('start-date') ? convertUTCtoLocal($editBtn.data('start-date')) : '');
        $editRow.find('.wccg-edit-end-date').val($editBtn.data('end-date') ? convertUTCtoLocal($editBtn.data('end-date')) : '');
        $editRow.find('.wccg-schedule-edit-message').html('');
        $editRow.hide();
    });

    $(document).off('click.wccg', '.wccg-save-schedule-btn').on('click.wccg', '.wccg-save-schedule-btn', function() {
        const ruleId = $(this).data('rule-id');
        const $editRow = $('#edit-schedule-' + ruleId);
        const $saveBtn = $(this);
        const $message = $editRow.find('.wccg-schedule-edit-message');
        const startDate = $editRow.find('.wccg-edit-start-date').val();
        const endDate = $editRow.find('.wccg-edit-end-date').val();

        if (startDate && endDate && new Date(endDate) <= new Date(startDate)) {
            setStatusMessage($message, 'error', strings.end_date_after_start || 'End date must be after start date.');
            return;
        }

        $saveBtn.prop('disabled', true).text(strings.saving || 'Saving...');
        setStatusMessage($message, 'info', strings.updating_schedule || 'Updating schedule...');

        $.ajax({
            url: wccg_pricing_rules.ajax_url,
            type: 'POST',
            data: {
                action: 'wccg_update_rule_schedule',
                nonce: wccg_pricing_rules.nonce,
                rule_id: ruleId,
                start_date: startDate,
                end_date: endDate
            },
            success(response) {
                if (!response.success) {
                    setStatusMessage($message, 'error', (strings.error_prefix || 'Error:') + ' ' + response.data.message);
                    return;
                }

                const $scheduleCell = $editRow.prev('tr').find('.wccg-schedule-cell');
                const $editBtn = $('.wccg-edit-schedule-btn[data-rule-id="' + ruleId + '"]');
                $scheduleCell.html(response.data.schedule_badge_html + response.data.schedule_display_html);
                $editBtn.data('start-date', startDate ? convertLocalToUTC(startDate) : '');
                $editBtn.data('end-date', endDate ? convertLocalToUTC(endDate) : '');
                setStatusMessage($message, 'success', response.data.message);
                setTimeout(() => {
                    $editRow.hide();
                    $message.attr('role', 'status').html('');
                }, 1500);
            },
            error() {
                setStatusMessage($message, 'error', strings.schedule_update_error || 'An error occurred while updating the schedule.');
            },
            complete() {
                $saveBtn.prop('disabled', false).text(strings.save_schedule || 'Save Schedule');
            }
        });
    });
}
