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
		<form method="post" style="display:inline;">
			<?php wp_nonce_field( 'wccg_pricing_rules_action', 'wccg_pricing_rules_nonce' ); ?>
			<input type="hidden" name="action" value="enable_all_rules">
			<button type="submit" id="wccg-enable-all-rules" class="button button-secondary"><?php esc_html_e( 'Enable All Rules', 'alynt-customer-groups' ); ?></button>
		</form>
		<form method="post" style="display:inline;">
			<?php wp_nonce_field( 'wccg_pricing_rules_action', 'wccg_pricing_rules_nonce' ); ?>
			<input type="hidden" name="action" value="disable_all_rules">
			<button type="submit" id="wccg-disable-all-rules" class="button button-secondary"><?php esc_html_e( 'Disable All Rules', 'alynt-customer-groups' ); ?></button>
		</form>
		<span style="margin-left: 10px; border-left: 1px solid #ccc; padding-left: 20px;"></span>
		<form method="post" style="display:inline;">
			<?php wp_nonce_field( 'wccg_pricing_rules_action', 'wccg_pricing_rules_nonce' ); ?>
			<input type="hidden" name="action" value="delete_all_rules">
			<button type="submit" id="wccg-delete-all-rules" class="button button-secondary" style="color: #a00;"><?php esc_html_e( 'Delete All Pricing Rules', 'alynt-customer-groups' ); ?></button>
		</form>
		<?php if ( ! empty( $rule_order_enabled ) ) : ?>
			<span id="wccg-rule-order-status" class="wccg-inline-status" role="status" aria-live="polite" aria-atomic="true"></span>
		<?php else : ?>
			<span class="description"><?php esc_html_e( 'Reordering is available only when all pricing rules fit on one page.', 'alynt-customer-groups' ); ?></span>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( ! empty( $pagination ) && $pagination['total_items'] > 0 ) : ?>
		<form method="get" style="margin-left:auto; display:flex; gap:8px; align-items:center;">
			<input type="hidden" name="page" value="wccg_pricing_rules">
			<label for="wccg-per-page"><?php esc_html_e( 'Rules per page:', 'alynt-customer-groups' ); ?></label>
			<select name="per_page" id="wccg-per-page">
				<?php foreach ( $pagination['per_page_options'] as $option ) : ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $pagination['per_page'], $option ); ?>><?php echo esc_html( $option ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( $pagination['current_page'] > 1 ) : ?>
				<input type="hidden" name="paged" value="<?php echo esc_attr( $pagination['current_page'] ); ?>">
			<?php endif; ?>
			<button type="submit" class="button button-secondary"><?php esc_html_e( 'Apply', 'alynt-customer-groups' ); ?></button>
		</form>
	<?php endif; ?>
</div>
