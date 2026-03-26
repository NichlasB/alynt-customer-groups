<?php
/**
 * Pricing rule edit modal template.
 *
 * @package Alynt_Customer_Groups
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="wccg-edit-rule-modal" class="wccg-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="wccg-modal-title">
	<div class="wccg-modal-overlay"></div>
	<div class="wccg-modal-container" tabindex="-1">
		<div class="wccg-modal-header">
			<h2 id="wccg-modal-title"><?php esc_html_e( 'Edit Pricing Rule', 'alynt-customer-groups' ); ?></h2>
			<button type="button" class="wccg-modal-close" aria-label="<?php esc_attr_e( 'Close', 'alynt-customer-groups' ); ?>">
				<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
			</button>
		</div>
		<div class="wccg-modal-body">
			<input type="hidden" id="wccg-edit-rule-id" value="">
			<div class="wccg-modal-grid">
				<div class="wccg-modal-field">
					<label for="wccg-edit-group"><?php esc_html_e( 'Customer Group', 'alynt-customer-groups' ); ?></label>
					<select id="wccg-edit-group">
						<?php foreach ( $groups as $group ) : ?>
							<?php if ( $group->group_name !== 'Regular Customers' ) : ?>
								<option value="<?php echo esc_attr( $group->group_id ); ?>"><?php echo esc_html( $group->group_name ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="wccg-modal-field">
					<label for="wccg-edit-discount-type"><?php esc_html_e( 'Discount Type', 'alynt-customer-groups' ); ?></label>
					<select id="wccg-edit-discount-type">
						<option value="fixed"><?php esc_html_e( 'Fixed Amount Discount', 'alynt-customer-groups' ); ?></option>
						<option value="percentage"><?php esc_html_e( 'Percentage Discount', 'alynt-customer-groups' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Fixed amount discounts take precedence over percentage discounts.', 'alynt-customer-groups' ); ?></p>
				</div>
				<div class="wccg-modal-field">
					<label for="wccg-edit-discount-value"><?php esc_html_e( 'Discount Value', 'alynt-customer-groups' ); ?></label>
					<input type="number" id="wccg-edit-discount-value" step="0.01" min="0">
					<p class="description wccg-edit-discount-hint"><?php esc_html_e( 'Enter the fixed discount amount.', 'alynt-customer-groups' ); ?></p>
				</div>
			</div>
			<div class="wccg-modal-selects">
				<div class="wccg-modal-field">
					<label for="wccg-edit-products"><?php esc_html_e( 'Assigned Products', 'alynt-customer-groups' ); ?></label>
					<p class="description"><?php esc_html_e( 'Hold Ctrl (Windows) or Cmd (Mac) to select multiple. Product rules override category rules.', 'alynt-customer-groups' ); ?></p>
					<select id="wccg-edit-products" multiple size="8">
						<?php foreach ( $all_products as $product ) : ?>
							<option value="<?php echo esc_attr( $product->get_id() ); ?>"><?php echo esc_html( $product->get_name() ); ?> (<?php echo esc_html( get_woocommerce_currency_symbol() . $product->get_regular_price() ); ?>)</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="wccg-modal-field">
					<label for="wccg-edit-categories"><?php esc_html_e( 'Assigned Categories', 'alynt-customer-groups' ); ?></label>
					<p class="description"><?php esc_html_e( 'Hold Ctrl (Windows) or Cmd (Mac) to select multiple. Applies to all products in selected categories.', 'alynt-customer-groups' ); ?></p>
					<select id="wccg-edit-categories" multiple size="8">
						<?php foreach ( $all_categories as $category ) : ?>
							<?php $depth = count( get_ancestors( $category->term_id, 'product_cat', 'taxonomy' ) ); ?>
							<option value="<?php echo esc_attr( $category->term_id ); ?>"><?php echo esc_html( str_repeat( '— ', $depth ) . $category->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
		</div>
		<div class="wccg-modal-footer">
			<span class="wccg-save-status wccg-modal-message" role="status" aria-live="polite" aria-atomic="true"></span>
			<button type="button" class="button wccg-modal-cancel"><?php esc_html_e( 'Cancel', 'alynt-customer-groups' ); ?></button>
			<button type="button" class="button button-primary wccg-modal-save"><?php esc_html_e( 'Save Changes', 'alynt-customer-groups' ); ?></button>
		</div>
	</div>
</div>

