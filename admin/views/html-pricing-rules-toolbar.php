<?php
/**
 * Pricing rules bulk actions toolbar template.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
	<?php if ( ! empty( $pricing_rules_view ) ) : ?>
		<button type="button" id="wccg-enable-all-rules" class="button button-secondary"><?php esc_html_e( 'Enable All Rules', 'alynt-customer-groups' ); ?></button>
		<button type="button" id="wccg-disable-all-rules" class="button button-secondary"><?php esc_html_e( 'Disable All Rules', 'alynt-customer-groups' ); ?></button>
		<span style="margin-left: 10px; border-left: 1px solid #ccc; padding-left: 20px;"></span>
		<button type="button" id="wccg-delete-all-rules" class="button button-secondary" style="color: #a00;"><?php esc_html_e( 'Delete All Pricing Rules', 'alynt-customer-groups' ); ?></button>
		<span id="wccg-rule-order-status" class="wccg-inline-status" role="status" aria-live="polite" aria-atomic="true"></span>
	<?php endif; ?>
</div>
