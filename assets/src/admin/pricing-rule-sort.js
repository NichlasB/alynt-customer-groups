export function initPricingRuleSort($) {
    const strings = (window.wccg_pricing_rules && window.wccg_pricing_rules.strings) || {};

    const $tableBody = $('#wccg-sortable-rules');
    const $status = $('#wccg-rule-order-status');
    let pendingRequest = null;
    let queuedOrder = null;
    let queuedTableBody = null;

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

    const setStatus = (message, type = 'info') => {
        if (!$status.length) {
            return;
        }

        $status.removeClass('is-error is-success is-loading');
        if (type === 'error') {
            $status.addClass('is-error');
        } else if (type === 'success') {
            $status.addClass('is-success');
        } else if (type === 'loading') {
            $status.addClass('is-loading');
        }

        $status.text(message || '');
        $status.attr('aria-busy', type === 'loading' ? 'true' : 'false');
    };

    const collectOrder = ($body) => {
        const order = [];
        $body.find('tr').each(function() {
            const ruleId = $(this).data('rule-id');
            if (ruleId) {
                order.push(ruleId);
            }
        });

        return order;
    };

    const persistOrder = ($body, originalOrder, orderOverride = null) => {
        const order = Array.isArray(orderOverride) ? orderOverride : collectOrder($body);

        if (pendingRequest) {
            queuedOrder = order;
            queuedTableBody = $body;
            setStatus(strings.order_save_queued || 'Saving the latest order after the current update finishes...', 'loading');
            return;
        }

        setStatus(strings.saving_rule_order || 'Saving rule order...', 'loading');
        $body.attr('aria-busy', 'true');

        pendingRequest = $.ajax({
            url: wccg_pricing_rules.ajax_url,
            type: 'POST',
            timeout: 15000,
            data: {
                action: 'wccg_reorder_pricing_rules',
                nonce: wccg_pricing_rules.nonce,
                order
            },
            success(response) {
                if (!response.success) {
                    setStatus(response.data && response.data.message ? response.data.message : (strings.error_update_rule_order || 'Could not save the new rule order. Please try again.'), 'error');
                    $body.sortable('cancel');
                    return;
                }

                setStatus(response.data.message || (strings.rule_order_saved || 'Rule order saved.'), 'success');
            },
            error(jqXHR, textStatus) {
                const fallbackMessage = textStatus === 'timeout'
                    ? (strings.rule_order_timeout || 'Saving the rule order took too long. Please try again.')
                    : (strings.error_update_rule_order || 'Could not save the new rule order. Please try again.');
                const friendlyMessage = getFriendlyError({ ...jqXHR, statusText: textStatus }, fallbackMessage);
                setStatus(friendlyMessage, 'error');

                if (Array.isArray(originalOrder) && originalOrder.length) {
                    const rowMap = new Map();
                    $body.find('tr[data-rule-id]').each(function() {
                        rowMap.set(String($(this).data('rule-id')), $(this));
                    });
                    originalOrder.forEach((ruleId) => {
                        const $row = rowMap.get(String(ruleId));
                        if ($row && $row.length) {
                            $body.append($row);
                        }
                    });
                } else if ($body.data('ui-sortable')) {
                    $body.sortable('cancel');
                }

                if (window.confirm(friendlyMessage + ' ' + (strings.retry_now_confirm || 'Retry now?'))) {
                    persistOrder($body, originalOrder, originalOrder);
                }
            },
            complete() {
                pendingRequest = null;
                $body.attr('aria-busy', 'false');

                if (queuedOrder && queuedTableBody && queuedTableBody.length) {
                    const nextBody = queuedTableBody;
                    queuedOrder = null;
                    queuedTableBody = null;
                    persistOrder(nextBody, collectOrder(nextBody));
                }
            }
        });
    };

    if (!$tableBody.length || typeof $.fn.sortable === 'undefined') {
        return;
    }

    $tableBody.sortable({
        handle: '.wccg-drag-handle',
        placeholder: 'wccg-sortable-placeholder',
        cursor: 'move',
        opacity: 0.8,
        start(event, ui) {
            ui.item.data('original-order', collectOrder($tableBody));
            setStatus(strings.dragging_rule_order || 'Rule order changed. Release to save.', 'loading');
        },
        helper(e, tr) {
            const $originals = tr.children();
            const $helper = tr.clone();
            $helper.children().each(function(index) {
                $(this).width($originals.eq(index).width());
            });
            return $helper;
        },
        update() {
            const originalOrder = $tableBody.find('tr.ui-sortable-handle').first().data('original-order') || collectOrder($tableBody);
            persistOrder($tableBody, originalOrder);
        }
    });

    $(document).off('click.wccg', '.wccg-reorder-btn').on('click.wccg', '.wccg-reorder-btn', function() {
        const $button = $(this);
        const $row = $button.closest('tr');
        const originalOrder = collectOrder($tableBody);

        if ($button.data('direction') === 'up') {
            const $previousRow = $row.prevAll('tr[data-rule-id]').first();
            if ($previousRow.length) {
                $previousRow.before($row);
                $button.trigger('focus');
                persistOrder($tableBody, originalOrder);
            }
            return;
        }

        const $nextRow = $row.nextAll('tr[data-rule-id]').first();
        if ($nextRow.length) {
            $nextRow.after($row);
            $button.trigger('focus');
            persistOrder($tableBody, originalOrder);
        }
    });
}
