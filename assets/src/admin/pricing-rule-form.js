export function initPricingRuleForm($) {
    const strings = (window.wccg_pricing_rules && window.wccg_pricing_rules.strings) || {};
    const buildRemoteSelectConfig = (action, placeholder) => ({
        placeholder,
        allowClear: true,
        width: '100%',
        closeOnSelect: false,
        minimumInputLength: 1,
        ajax: {
            url: wccg_pricing_rules.ajax_url,
            dataType: 'json',
            delay: 250,
            cache: true,
            data(params) {
                return {
                    action,
                    nonce: wccg_pricing_rules.nonce,
                    term: params.term || ''
                };
            },
            processResults(response) {
                const results = response && response.success && response.data && Array.isArray(response.data.results)
                    ? response.data.results
                    : [];

                return { results };
            }
        }
    });

    if (!$('body').hasClass('customer-groups_page_wccg_pricing_rules')) {
        return;
    }

    const $productSelect = $('#product-select');
    const $categorySelect = $('#category-select');
    if (typeof $.fn.select2 !== 'function') {
        return;
    }

    if ($productSelect.hasClass('select2-hidden-accessible')) {
        $productSelect.select2('destroy');
    }
    if ($categorySelect.hasClass('select2-hidden-accessible')) {
        $categorySelect.select2('destroy');
    }

    $productSelect.select2(buildRemoteSelectConfig('wccg_search_products', strings.search_products_placeholder || 'Search and select products...'));
    $categorySelect.select2(buildRemoteSelectConfig('wccg_search_categories', strings.search_categories_placeholder || 'Search and select categories...'));

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
