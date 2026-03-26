export function initUserAssignments($) {
    const strings = (window.wccg_admin && window.wccg_admin.strings) || {};

    if (!$('body').hasClass('customer-groups_page_wccg_user_assignments')) {
        return;
    }

    const $form = $('.wccg-user-assignments-form');
    const $feedback = $form.find('.wccg-bulk-feedback');

    const getSelectedCount = () => $form.find("input[name='user_ids[]']:checked").length;

    const setFeedback = (message, isBusy = false) => {
        $feedback.text(message || '');
        $feedback.attr('aria-busy', isBusy ? 'true' : 'false');
    };

    const getBulkMessage = (actionType, count) => {
        if (actionType === 'assign') {
            if (count === 1) {
                return strings.bulk_assign_processing_single || 'Assigning 1 user...';
            }

            return (strings.bulk_assign_processing_multiple || 'Assigning %d users...').replace('%d', count);
        }

        if (actionType === 'unassign') {
            if (count === 1) {
                return strings.bulk_unassign_processing_single || 'Unassigning 1 user...';
            }

            return (strings.bulk_unassign_processing_multiple || 'Unassigning %d users...').replace('%d', count);
        }

        if (count === 1) {
            return strings.bulk_export_processing_single || 'Exporting 1 user...';
        }

        return (strings.bulk_export_processing_multiple || 'Exporting %d users...').replace('%d', count);
    };

    const getConfirmationMessage = (actionType, count) => {
        if (actionType === 'assign') {
            const groupName = $('#assign_group_id option:selected').text().trim();
            if (count === 1) {
                return strings.bulk_assign_confirm_single || 'Assign the selected user to this group?';
            }

            return (strings.bulk_assign_confirm_multiple || 'Assign %1$d selected users to "%2$s"?')
                .replace('%1$d', count)
                .replace('%2$s', groupName);
        }

        if (count === 1) {
            return strings.bulk_unassign_confirm_single || 'Unassign the selected user from its current group?';
        }

        return (strings.bulk_unassign_confirm_multiple || 'Unassign %d selected users from their current groups?').replace('%d', count);
    };

    $form.find("button[name='export_csv']").off('click.wccg').on('click.wccg', function(e) {
        if (getSelectedCount() === 0) {
            e.preventDefault();
            alert(strings.export_users_required || 'Please select at least one user to export.');
        }
    });

    $('#select-all-users').off('click.wccg').on('click.wccg', function() {
        $form.find("input[name='user_ids[]']").prop('checked', this.checked);
    });

    let lastChecked = null;
    const checkboxes = $form.find("input[name='user_ids[]']");
    checkboxes.off('click.wccg').on('click.wccg', function(e) {
        if (!lastChecked) {
            lastChecked = this;
            return;
        }

        if (e.shiftKey) {
            const start = checkboxes.index(this);
            const end = checkboxes.index(lastChecked);
            checkboxes.slice(Math.min(start, end), Math.max(start, end) + 1).prop('checked', lastChecked.checked);
        }

        lastChecked = this;
    });

    $(document).off('mousedown.wccg', 'input[type=checkbox]').on('mousedown.wccg', 'input[type=checkbox]', function(e) {
        if (e.shiftKey) {
            e.preventDefault();
        }
    });

    $('#group-filter, #per_page').off('change.wccg').on('change.wccg', function() {
        $(this).closest('form').submit();
    });

    $('#date-from, #date-to').off('change.wccg').on('change.wccg', function() {
        const fromDate = $('#date-from').val();
        const toDate = $('#date-to').val();
        if (fromDate && toDate && fromDate > toDate) {
            alert(strings.date_range_invalid || 'From date cannot be later than To date.');
            $(this).val('');
        }
    });

    $form.off('submit.wccg').on('submit.wccg', function(e) {
        const $submitter = $(document.activeElement);
        const count = getSelectedCount();
        const actionType = $submitter.data('action-type') || 'assign';

        if (count === 0) {
            e.preventDefault();
            alert(strings.bulk_action_notice || 'Please select at least one user before running a bulk action.');
            return;
        }

        if (actionType === 'assign' || actionType === 'unassign') {
            const confirmed = window.confirm(getConfirmationMessage(actionType, count));
            if (!confirmed) {
                e.preventDefault();
                return;
            }
        }

        setFeedback(getBulkMessage(actionType, count), true);
        $form.attr('aria-busy', 'true');
        $form.find('.wccg-bulk-action-btn').prop('disabled', true);
        $submitter.attr('aria-busy', 'true');
    });
}
