export function initPricingRuleSort($) {
    const strings = (window.wccg_pricing_rules && window.wccg_pricing_rules.strings) || {};

    const persistOrder = ($tableBody) => {
        const order = [];
        $tableBody.find('tr').each(function() {
            const ruleId = $(this).data('rule-id');
            if (ruleId) {
                order.push(ruleId);
            }
        });

        $.ajax({
            url: wccg_pricing_rules.ajax_url,
            type: 'POST',
            data: {
                action: 'wccg_reorder_pricing_rules',
                nonce: wccg_pricing_rules.nonce,
                order
            },
            success(response) {
                if (!response.success) {
                    alert((strings.failed_update_rule_order || 'Failed to update rule order:') + ' ' + response.data.message);
                    $tableBody.sortable('cancel');
                }
            },
            error() {
                alert(strings.error_update_rule_order || 'An error occurred while updating the rule order.');
                $tableBody.sortable('cancel');
            }
        });
    };

    if (!$('#wccg-sortable-rules').length || typeof $.fn.sortable === 'undefined') {
        return;
    }

    $('#wccg-sortable-rules').sortable({
        handle: '.wccg-drag-handle',
        placeholder: 'wccg-sortable-placeholder',
        cursor: 'move',
        opacity: 0.8,
        helper(e, tr) {
            const $originals = tr.children();
            const $helper = tr.clone();
            $helper.children().each(function(index) {
                $(this).width($originals.eq(index).width());
            });
            return $helper;
        },
        update() {
            persistOrder($('#wccg-sortable-rules'));
        }
    });

    $(document).off('click.wccg', '.wccg-reorder-btn').on('click.wccg', '.wccg-reorder-btn', function() {
        const $button = $(this);
        const $row = $button.closest('tr');
        const $tableBody = $('#wccg-sortable-rules');

        if ($button.data('direction') === 'up') {
            const $previousRow = $row.prevAll('tr[data-rule-id]').first();
            if ($previousRow.length) {
                $previousRow.before($row);
                $button.trigger('focus');
                persistOrder($tableBody);
            }
            return;
        }

        const $nextRow = $row.nextAll('tr[data-rule-id]').first();
        if ($nextRow.length) {
            $nextRow.after($row);
            $button.trigger('focus');
            persistOrder($tableBody);
        }
    });
}
