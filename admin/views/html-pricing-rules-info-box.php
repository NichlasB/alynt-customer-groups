<?php
/**
 * Pricing rules hierarchy info box template.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wccg-info-box">
	<h3><?php esc_html_e( 'Pricing Rules Hierarchy', 'alynt-customer-groups' ); ?></h3>
	<div class="wccg-info-grid">
		<div class="wccg-info-column">
			<h4><?php esc_html_e( 'Rule Precedence', 'alynt-customer-groups' ); ?></h4>
			<ul>
				<li><?php esc_html_e( 'Product-specific rules override category rules', 'alynt-customer-groups' ); ?></li>
				<li><?php esc_html_e( 'Fixed discounts take precedence over percentage discounts', 'alynt-customer-groups' ); ?></li>
				<li><?php esc_html_e( 'Higher discount values take precedence over lower ones', 'alynt-customer-groups' ); ?></li>
				<li><?php esc_html_e( 'For equal discounts, the most recently created rule wins', 'alynt-customer-groups' ); ?></li>
			</ul>
		</div>
		<div class="wccg-info-column">
			<h4><?php esc_html_e( 'Category Rules', 'alynt-customer-groups' ); ?></h4>
			<ul>
				<li><?php esc_html_e( 'Apply to all products in the category', 'alynt-customer-groups' ); ?></li>
				<li><?php esc_html_e( 'Include parent category rules', 'alynt-customer-groups' ); ?></li>
				<li><?php esc_html_e( 'Best discount automatically applies', 'alynt-customer-groups' ); ?></li>
			</ul>
		</div>
		<?php if ( ! empty( $conflicts ) ) : ?>
			<div class="wccg-info-column wccg-conflicts">
				<h4><?php esc_html_e( 'Current Conflicts', 'alynt-customer-groups' ); ?></h4>
				<ul>
					<?php foreach ( $conflicts as $conflict ) : ?>
						<li><?php echo esc_html( $conflict ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</div>
	<?php if ( ! empty( $conflicts_notice ) ) : ?>
		<p class="description"><?php echo esc_html( $conflicts_notice ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $conflicts ) ) : ?>
		<p class="wccg-conflict-notice">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<?php esc_html_e( 'Conflicts don\'t break anything - the hierarchy rules above determine which discount applies.', 'alynt-customer-groups' ); ?>
		</p>
	<?php endif; ?>
</div>
