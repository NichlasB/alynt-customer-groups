<?php
/**
 * Pricing rule create form template.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wccg-pricing-rules-form">
	<h2><?php esc_html_e( 'Select Customer Group', 'alynt-customer-groups' ); ?></h2>
	<label for="group_id" class="screen-reader-text"><?php esc_html_e( 'Customer Group', 'alynt-customer-groups' ); ?></label>
	<select name="group_id" id="group_id" required>
		<?php foreach ( $groups as $group ) : ?>
			<?php if ( $group->group_name !== 'Regular Customers' ) : ?>
				<option value="<?php echo esc_attr( $group->group_id ); ?>" <?php selected( (int) $form_values['group_id'], (int) $group->group_id ); ?>><?php echo esc_html( $group->group_name ); ?></option>
			<?php endif; ?>
		<?php endforeach; ?>
	</select>

	<h2><?php esc_html_e( 'Discount Settings', 'alynt-customer-groups' ); ?></h2>
	<table class="form-table">
		<tr>
			<th scope="row"><label for="discount_type"><?php esc_html_e( 'Discount Type', 'alynt-customer-groups' ); ?></label></th>
			<td>
				<select name="discount_type" id="discount_type" required>
					<option value="fixed" <?php selected( $form_values['discount_type'], 'fixed' ); ?>><?php esc_html_e( 'Fixed Amount Discount', 'alynt-customer-groups' ); ?></option>
					<option value="percentage" <?php selected( $form_values['discount_type'], 'percentage' ); ?>><?php esc_html_e( 'Percentage Discount', 'alynt-customer-groups' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Fixed amount discounts take precedence over percentage discounts.', 'alynt-customer-groups' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="discount_value"><?php esc_html_e( 'Discount Value', 'alynt-customer-groups' ); ?></label></th>
			<td>
				<input name="discount_value" type="number" step="0.01" id="discount_value" value="<?php echo esc_attr( $form_values['discount_value'] ); ?>" required>
				<p class="description discount-type-hint fixed"<?php echo $form_values['discount_type'] === 'percentage' ? ' style="display:none;"' : ''; ?>><?php esc_html_e( 'Enter the fixed discount amount in your store\'s currency.', 'alynt-customer-groups' ); ?></p>
				<p class="description discount-type-hint percentage"<?php echo $form_values['discount_type'] === 'percentage' ? '' : ' style="display:none;"'; ?>><?php esc_html_e( 'Enter a percentage between 0 and 100.', 'alynt-customer-groups' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Schedule (Optional)', 'alynt-customer-groups' ); ?></h2>
	<table class="form-table">
		<tr>
			<th scope="row"><label for="start_date"><?php esc_html_e( 'Start Date & Time', 'alynt-customer-groups' ); ?></label></th>
			<td>
				<input name="start_date" type="datetime-local" id="start_date" value="<?php echo esc_attr( $form_values['start_date'] ); ?>">
				<p class="description"><?php esc_html_e( 'When should this pricing rule become active? Leave blank for immediate activation.', 'alynt-customer-groups' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="end_date"><?php esc_html_e( 'End Date & Time', 'alynt-customer-groups' ); ?></label></th>
			<td>
				<input name="end_date" type="datetime-local" id="end_date" value="<?php echo esc_attr( $form_values['end_date'] ); ?>">
				<p class="description"><?php esc_html_e( 'When should this pricing rule expire? Leave blank for no expiration.', 'alynt-customer-groups' ); ?></p>
			</td>
		</tr>
		<tr>
			<td colspan="2">
				<p class="description">
					<strong><?php esc_html_e( 'Note:', 'alynt-customer-groups' ); ?></strong>
					<?php esc_html_e( 'Times are based on your site timezone:', 'alynt-customer-groups' ); ?>
					<code><?php echo esc_html( wp_timezone_string() ); ?></code>.
					<?php esc_html_e( 'Leave both fields blank for the rule to be always active.', 'alynt-customer-groups' ); ?>
				</p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Select Products', 'alynt-customer-groups' ); ?></h2>
	<div class="wccg-selection-section">
		<p class="description"><?php esc_html_e( 'Product-specific rules override category rules. Hold down Ctrl (Windows) or Command (Mac) to select multiple items.', 'alynt-customer-groups' ); ?></p>
		<select id="product-select" name="product_ids[]" multiple class="wccg-native-select" size="10">
			<?php foreach ( $all_products as $product ) : ?>
				<option value="<?php echo esc_attr( $product->get_id() ); ?>" <?php selected( in_array( (int) $product->get_id(), $form_values['product_ids'], true ) ); ?>>
					<?php echo esc_html( $product->get_name() ); ?>
					(<?php echo esc_html( get_woocommerce_currency_symbol() . $product->get_regular_price() ); ?>)
				</option>
			<?php endforeach; ?>
		</select>
	</div>

	<h2><?php esc_html_e( 'Select Categories', 'alynt-customer-groups' ); ?></h2>
	<div class="wccg-selection-section">
		<p class="description"><?php esc_html_e( 'Category rules apply to all products in selected categories, including child categories.', 'alynt-customer-groups' ); ?></p>
		<select id="category-select" name="category_ids[]" multiple class="wccg-native-select" size="10">
			<?php foreach ( $all_categories as $category ) : ?>
				<?php $depth = count( get_ancestors( $category->term_id, 'product_cat', 'taxonomy' ) ); ?>
				<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( in_array( (int) $category->term_id, $form_values['category_ids'], true ) ); ?>><?php echo esc_html( str_repeat( '— ', $depth ) . $category->name ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>

	<?php submit_button( __( 'Save Pricing Rule', 'alynt-customer-groups' ) ); ?>
</div>
