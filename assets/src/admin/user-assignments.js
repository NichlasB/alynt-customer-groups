export function initUserAssignments($) {
    const strings = (window.wccg_admin && window.wccg_admin.strings) || {};

    if (!$('body').hasClass('customer-groups_page_wccg_user_assignments')) {
        return;
    }

    $("button[name='export_csv']").off('click.wccg').on('click.wccg', function(e) {
        if ($("input[name='user_ids[]']:checked").length === 0) {
            e.preventDefault();
            alert(strings.export_users_required || 'Please select at least one user to export.');
        }
    });

    $('#select-all-users').off('click.wccg').on('click.wccg', function() {
        $("input[name='user_ids[]']").prop('checked', this.checked);
    });

    let lastChecked = null;
    const checkboxes = $("input[name='user_ids[]']");
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
}
