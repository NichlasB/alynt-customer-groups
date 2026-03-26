export function initPricingRuleModal($) {
    const strings = (window.wccg_pricing_rules && window.wccg_pricing_rules.strings) || {};
    const $modal = $('#wccg-edit-rule-modal');
    let $lastFocused = null;

    const getFocusableElements = () => $modal.find('button, input, select, textarea, [href], [tabindex]:not([tabindex="-1"])').filter(':visible:not([disabled])');

    const setStatusMessage = (type, message) => {
        const $message = $modal.find('.wccg-modal-message');
        $message.attr('role', type === 'error' ? 'alert' : 'status');
        const color = type === 'error' ? '#dc3232' : (type === 'success' ? '#46b450' : '#666');
        $message.html('<span style="color: ' + color + ';">' + message + '</span>');
    };

    const closeModal = () => {
        $modal.fadeOut(200, () => {
            if ($lastFocused && $lastFocused.length) {
                $lastFocused.trigger('focus');
            }
        });
        $('body').removeClass('wccg-modal-open');
        $modal.find('.wccg-modal-message').attr('role', 'status').html('');
        $modal.find('.wccg-modal-save').prop('disabled', false).text(strings.save_changes || 'Save Changes');
    };

    $(document).off('click.wccg', '.wccg-edit-rule-btn').on('click.wccg', '.wccg-edit-rule-btn', function() {
        const ruleId = $(this).data('rule-id');
        $lastFocused = $(this);
        $('.wccg-schedule-edit-row').hide();
        setStatusMessage('info', strings.loading_rule_data || 'Loading rule data...');
        $modal.find('.wccg-modal-save').prop('disabled', true);
        $modal.fadeIn(200, () => {
            $modal.find('.wccg-modal-container').trigger('focus');
        });
        $('body').addClass('wccg-modal-open');

        $.ajax({
            url: wccg_pricing_rules.ajax_url,
            type: 'POST',
            data: {
                action: 'wccg_get_rule_data',
                nonce: wccg_pricing_rules.nonce,
                rule_id: ruleId
            },
            success(response) {
                if (!response.success) {
                    setStatusMessage('error', (strings.error_prefix || 'Error:') + ' ' + response.data.message);
                    return;
                }

                const rule = response.data.rule;
                $('#wccg-edit-rule-id').val(ruleId);
                $('#wccg-edit-group').val(rule.group_id);
                $('#wccg-edit-discount-type').val(rule.discount_type).trigger('change');
                $('#wccg-edit-discount-value').val(rule.discount_value);
                $('#wccg-edit-products').val(response.data.product_ids.map(String));
                $('#wccg-edit-categories').val(response.data.category_ids.map(String));
                $modal.find('.wccg-modal-message').attr('role', 'status').html('');
                $modal.find('.wccg-modal-save').prop('disabled', false);
            },
            error() {
                setStatusMessage('error', strings.failed_load_rule_data || 'Failed to load rule data.');
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

        $saveBtn.prop('disabled', true).text(strings.saving || 'Saving...');
        setStatusMessage('info', strings.updating_rule || 'Updating rule...');

        $.ajax({
            url: wccg_pricing_rules.ajax_url,
            type: 'POST',
            data: payload,
            success(response) {
                if (!response.success) {
                    setStatusMessage('error', (strings.error_prefix || 'Error:') + ' ' + response.data.message);
                    return;
                }

                const $ruleRow = $('tr[data-rule-id="' + payload.rule_id + '"]').first();
                $ruleRow.find('td:eq(2)').text(response.data.group_name);
                let discountTypeHtml = response.data.discount_type;
                if (response.data.discount_type_raw === 'fixed') {
                    discountTypeHtml += ' <span class="dashicons dashicons-star-filled" aria-hidden="true" title="' + (strings.fixed_precedence_short || 'Fixed discounts take precedence') + '"></span>';
                }
                $ruleRow.find('td:eq(3)').html(discountTypeHtml);
                $ruleRow.find('td:eq(4)').html(response.data.discount_value);
                $ruleRow.find('td:eq(5)').html(response.data.product_names.length ? '<span class="rule-type-indicator product">' + (strings.product_rule || 'Product Rule') + '</span><br>' + response.data.product_names.join(', ') : '');
                $ruleRow.find('td:eq(6)').html(response.data.category_names.length ? '<span class="rule-type-indicator category">' + (strings.category_rule || 'Category Rule') + '</span><br>' + response.data.category_names.join(', ') : '');
                setStatusMessage('success', response.data.message);
                setTimeout(closeModal, 1000);
            },
            error() {
                setStatusMessage('error', strings.rule_update_error || 'An error occurred while updating the rule.');
            },
            complete() {
                $saveBtn.prop('disabled', false).text(strings.save_changes || 'Save Changes');
            }
        });
    });
}
