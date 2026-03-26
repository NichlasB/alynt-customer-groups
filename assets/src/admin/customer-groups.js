export function initCustomerGroups($) {
    const strings = (window.wccg_admin && window.wccg_admin.strings) || {};

    if (!$('body').hasClass('toplevel_page_wccg_customer_groups')) {
        return;
    }

    $('form input[name="action"][value="delete_group"]').closest('form').off('submit.wccg').on('submit.wccg', function(e) {
        if (!confirm(strings.delete_group_confirm || 'Are you sure you want to delete this group? This action cannot be undone.')) {
            e.preventDefault();
        }
    });

    $('input[name="group_name"]').off('input.wccg').on('input.wccg', function() {
        const value = $(this).val();
        if (value.length > 255) {
            alert(strings.group_name_max || 'Group name cannot exceed 255 characters.');
            $(this).val(value.substring(0, 255));
        }
    });
}
