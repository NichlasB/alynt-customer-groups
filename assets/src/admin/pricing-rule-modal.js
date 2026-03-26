export function initPricingRuleModal($) {
    const strings = (window.wccg_pricing_rules && window.wccg_pricing_rules.strings) || {};
    const $modal = $('#wccg-edit-rule-modal');
    let $lastFocused = null;

    const getFocusableElements = () => $modal.find('button, input, select, textarea, [href], [tabindex]:not([tabindex="-1"])').filter(':visible:not([disabled])');
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
    const hydrateRemoteSelect = ($select, options) => {
        $select.empty();

        (options || []).forEach((option) => {
            const optionNode = new Option(option.text, option.id, true, true);
            $select.append(optionNode);
        });

        $select.trigger('change');
    };

    const getFriendlyError = (jqXHR, fallbackMessage) => {
        if (jqXHR && jqXHR.status === 403) {
            return strings.session_expired || 'Your session has expired. Reload the page and try again.';
        }

        if (jqXHR && jqXHR.statusText === 'timeout') {
            return strings.request_timeout || 'The request took too long. Please try again.';
        }

        if (typeof navigator !== 'undefined' && navigator.onLine === false) {
            return strings.network_error || 'Connection lost. Check your internet connection and try again.';
        }

        const responseMessage = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message;
        return responseMessage || fallbackMessage || strings.generic_request_error || 'Something unexpected happened. Please try again.';
    };

    const setStatusMessage = (type, message) => {
        const $message = $modal.find('.wccg-modal-message');
        $message.attr('role', type === 'error' ? 'alert' : 'status');
        const color = type === 'error' ? '#dc3232' : (type === 'success' ? '#46b450' : '#666');
        $message.empty().append($('<span>').css('color', color).text(message));
    };

    const closeModal = () => {
        $modal.fadeOut(200, () => {
            if ($lastFocused && $lastFocused.length) {
                $lastFocused.trigger('focus');
            }
        });
        $('body').removeClass('wccg-modal-open');
        $modal.find('.wccg-modal-message').attr('role', 'status').empty();
        $modal.find('.wccg-modal-save').prop('disabled', false).text(strings.save_changes || 'Save Changes').removeAttr('aria-busy');
        hydrateRemoteSelect($('#wccg-edit-products'), []);
        hydrateRemoteSelect($('#wccg-edit-categories'), []);
        $modal.removeAttr('aria-busy');
    };

    const $productSelect = $('#wccg-edit-products');
    const $categorySelect = $('#wccg-edit-categories');
    const hasSelect2 = typeof $.fn.select2 === 'function';
    if ($productSelect.hasClass('select2-hidden-accessible')) {
        $productSelect.select2('destroy');
    }
    if ($categorySelect.hasClass('select2-hidden-accessible')) {
        $categorySelect.select2('destroy');
    }
    if (hasSelect2) {
        $productSelect.select2(buildRemoteSelectConfig('wccg_search_products', strings.search_products_placeholder || 'Search and select products...'));
        $categorySelect.select2(buildRemoteSelectConfig('wccg_search_categories', strings.search_categories_placeholder || 'Search and select categories...'));
    }

    $(document).off('click.wccg', '.wccg-edit-rule-btn').on('click.wccg', '.wccg-edit-rule-btn', function() {
        const ruleId = $(this).data('rule-id');
        $lastFocused = $(this);
        $('.wccg-schedule-edit-row').hide();
        setStatusMessage('info', strings.loading_rule_data || 'Loading rule data...');
        $modal.find('.wccg-modal-save').prop('disabled', true);
        $modal.attr('aria-busy', 'true');
        $modal.fadeIn(200, () => {
            $modal.find('.wccg-modal-container').trigger('focus');
        });
        $('body').addClass('wccg-modal-open');

        $.ajax({
            url: wccg_pricing_rules.ajax_url,
            type: 'POST',
            timeout: 15000,
            data: {
                action: 'wccg_get_rule_data',
                nonce: wccg_pricing_rules.nonce,
                rule_id: ruleId
            },
            success(response) {
                if (!response.success) {
                    setStatusMessage('error', response.data && response.data.message ? response.data.message : (strings.failed_load_rule_data || 'Could not load the pricing rule. Please try again.'));
                    return;
                }

                const rule = response.data.rule;
                $('#wccg-edit-rule-id').val(ruleId);
                $('#wccg-edit-group').val(rule.group_id);
                $('#wccg-edit-discount-type').val(rule.discount_type).trigger('change');
                $('#wccg-edit-discount-value').val(rule.discount_value);
                hydrateRemoteSelect($productSelect, response.data.product_options || []);
                hydrateRemoteSelect($categorySelect, response.data.category_options || []);
                $modal.find('.wccg-modal-message').attr('role', 'status').empty();
                $modal.find('.wccg-modal-save').prop('disabled', false);
            },
            error(jqXHR, textStatus) {
                const fallbackMessage = textStatus === 'timeout'
                    ? (strings.rule_load_timeout || 'Loading the pricing rule took too long. Please try again.')
                    : (strings.failed_load_rule_data || 'Could not load the pricing rule. Please try again.');
                setStatusMessage('error', getFriendlyError({ ...jqXHR, statusText: textStatus }, fallbackMessage));
            },
            complete() {
                $modal.attr('aria-busy', 'false');
            }
        });
    });

    $(document).off('click.wccg', '.wccg-modal-close, .wccg-modal-cancel').on('click.wccg', '.wccg-modal-close, .wccg-modal-cancel', closeModal);
    $(document).off('click.wccg', '.wccg-modal-overlay').on('click.wccg', '.wccg-modal-overlay', closeModal);
    $(document).off('keydown.wccgModal').on('keydown.wccgModal', function(e) {
        if (!$modal.is(':visible')) {
            return;
        }

        if (e.key === 'Escape') {
            closeModal();
            return;
        }

        if (e.key !== 'Tab') {
            return;
        }

        const $focusable = getFocusableElements();
        if (!$focusable.length) {
            return;
        }

        const first = $focusable.get(0);
        const last = $focusable.get($focusable.length - 1);

        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
            return;
        }

        if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });

    $(document).off('change.wccg', '#wccg-edit-discount-type').on('change.wccg', '#wccg-edit-discount-type', function() {
        const $valueInput = $('#wccg-edit-discount-value');
        if ($(this).val() === 'percentage') {
            $valueInput.attr('max', '100');
            $('.wccg-edit-discount-hint').text(strings.percentage_hint || 'Enter a percentage between 0 and 100.');
        } else {
            $valueInput.removeAttr('max');
            $('.wccg-edit-discount-hint').text(strings.fixed_hint || 'Enter the fixed discount amount.');
        }
    });

    $(document).off('input.wccg', '#wccg-edit-discount-value').on('input.wccg', '#wccg-edit-discount-value', function() {
        const value = parseFloat($(this).val());
        const type = $('#wccg-edit-discount-type').val();
        if (type === 'percentage' && value > 100) {
            $(this).val(100);
        } else if (value < 0) {
            $(this).val(0);
        }
    });

    $(document).off('click.wccg', '.wccg-modal-save').on('click.wccg', '.wccg-modal-save', function() {
        const $saveBtn = $(this);
        const payload = {
            action: 'wccg_update_pricing_rule',
            nonce: wccg_pricing_rules.nonce,
            rule_id: $('#wccg-edit-rule-id').val(),
            group_id: $('#wccg-edit-group').val(),
            discount_type: $('#wccg-edit-discount-type').val(),
            discount_value: $('#wccg-edit-discount-value').val(),
            product_ids: $('#wccg-edit-products').val() || [],
            category_ids: $('#wccg-edit-categories').val() || []
        };

        if (!payload.group_id) {
            setStatusMessage('error', strings.select_customer_group || 'Please select a customer group.');
            return;
        }
        if (!payload.discount_value || payload.discount_value <= 0) {
            setStatusMessage('error', strings.enter_valid_discount || 'Please enter a valid discount value.');
            return;
        }
        if (payload.discount_type === 'percentage' && payload.discount_value > 100) {
            setStatusMessage('error', strings.percentage_exceed || 'Percentage discount cannot exceed 100%.');
            return;
        }

        $saveBtn.prop('disabled', true).text(strings.saving || 'Saving...').attr('aria-busy', 'true');
        $modal.attr('aria-busy', 'true');
        setStatusMessage('info', strings.updating_rule || 'Updating rule...');

        $.ajax({
            url: wccg_pricing_rules.ajax_url,
            type: 'POST',
            timeout: 15000,
            data: payload,
            success(response) {
                if (!response.success) {
                    setStatusMessage('error', response.data && response.data.message ? response.data.message : (strings.rule_update_error || 'Could not update the pricing rule. Please try again.'));
                    return;
                }

                const $ruleRow = $('tr[data-rule-id="' + payload.rule_id + '"]').first();
                $ruleRow.find('td:eq(2)').text(response.data.group_name);
                const $typeCell = $ruleRow.find('td:eq(3)').empty().append(document.createTextNode(response.data.discount_type));
                if (response.data.discount_type_raw === 'fixed') {
                    $typeCell.append(' ').append($('<span>').addClass('dashicons dashicons-star-filled').attr({'aria-hidden': 'true', 'title': strings.fixed_precedence_short || 'Fixed discounts take precedence'}));
                }
                $ruleRow.find('td:eq(4)').html(response.data.discount_value);
                const $productCell = $ruleRow.find('td:eq(5)').empty();
                if (response.data.product_names.length) {
                    $productCell.append($('<span>').addClass('rule-type-indicator product').text(strings.product_rule || 'Product Rule')).append('<br>').append(document.createTextNode(response.data.product_names.join(', ')));
                }
                const $categoryCell = $ruleRow.find('td:eq(6)').empty();
                if (response.data.category_names.length) {
                    $categoryCell.append($('<span>').addClass('rule-type-indicator category').text(strings.category_rule || 'Category Rule')).append('<br>').append(document.createTextNode(response.data.category_names.join(', ')));
                }
                setStatusMessage('success', response.data.message);
                setTimeout(closeModal, 1000);
            },
            error(jqXHR, textStatus) {
                const fallbackMessage = textStatus === 'timeout'
                    ? (strings.rule_update_timeout || 'Saving the pricing rule took too long. Please try again.')
                    : (strings.rule_update_error || 'Could not update the pricing rule. Please try again.');
                setStatusMessage('error', getFriendlyError({ ...jqXHR, statusText: textStatus }, fallbackMessage));
            },
            complete() {
                $saveBtn.prop('disabled', false).text(strings.save_changes || 'Save Changes').removeAttr('aria-busy');
                $modal.attr('aria-busy', 'false');
            }
        });
    });
}
