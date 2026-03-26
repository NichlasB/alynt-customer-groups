export function initPricingRuleForm($) {
    const strings = (window.wccg_pricing_rules && window.wccg_pricing_rules.strings) || {};

    if (!$('body').hasClass('customer-groups_page_wccg_pricing_rules')) {
        return;
    }

    $('#product-select').select2({
        placeholder: strings.search_products_placeholder || 'Search and select products...',
        allowClear: true,
        width: '100%',
        closeOnSelect: false
    });

    $('#category-select').select2({
        placeholder: strings.search_categories_placeholder || 'Search and select categories...',
        allowClear: true,
        width: '100%',
        closeOnSelect: false
    });

    $('#discount_type').off('change.wccg').on('change.wccg', function() {
        const $value = $('#discount_value');
        if ($(this).val() === 'percentage') {
            $value.attr('max', '100');
        } else {
            $value.removeAttr('max');
        }
    });

    const validateScheduleDates = () => {
        const startDate = $('#start_date').val();
        const endDate = $('#end_date').val();
        if (startDate && endDate && new Date(endDate) <= new Date(startDate)) {
            return {
                valid: false,
                message: strings.end_date_after_start || 'End date must be after start date.'
            };
        }
        return { valid: true };
    };

    $('#start_date, #end_date').off('change.wccg').on('change.wccg', function() {
        const validation = validateScheduleDates();
        if (!validation.valid) {
            alert(validation.message);
            $(this).val('');
        }
    });

    $('.wccg-pricing-rules-form').closest('form').off('submit.wccg').on('submit.wccg', function(e) {
        const validation = validateScheduleDates();
        if (!validation.valid) {
            e.preventDefault();
            alert(validation.message);
            $('#end_date').focus();
        }
    });
}
