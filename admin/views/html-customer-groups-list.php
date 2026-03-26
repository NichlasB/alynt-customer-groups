<?php
/**
 * Customer Groups admin list and create form template.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h2><?php esc_html_e( 'Add New Group', 'alynt-customer-groups' ); ?></h2>
<form method="post">
	<?php wp_nonce_field( 'wccg_customer_groups_action', 'wccg_customer_groups_nonce' ); ?>
	<input type="hidden" name="action" value="add_group">

	<table class="form-table">
		<tr>
			<th scope="row">
				<label for="group_name"><?php esc_html_e( 'Group Name', 'alynt-customer-groups' ); ?></label>
			</th>
			<td>
				<input name="group_name" type="text" id="group_name" class="regular-text" required>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="group_description"><?php esc_html_e( 'Description', 'alynt-customer-groups' ); ?></label>
			</th>
			<td>
				<textarea name="group_description" id="group_description" class="regular-text"></textarea>
			</td>
		</tr>
	</table>

	<?php submit_button( __( 'Add New Group', 'alynt-customer-groups' ) ); ?>
</form>

<h2><?php esc_html_e( 'Existing Groups', 'alynt-customer-groups' ); ?></h2>
<table class="wp-list-table widefat fixed striped" aria-label="<?php esc_attr_e( 'Existing Groups', 'alynt-customer-groups' ); ?>">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Group ID', 'alynt-customer-groups' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Group Name', 'alynt-customer-groups' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Description', 'alynt-customer-groups' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Actions', 'alynt-customer-groups' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( ! empty( $groups ) ) : ?>
			<?php foreach ( $groups as $group ) : ?>
				<?php $is_default = ( (int) $default_group_id === (int) $group->group_id ); ?>
				<tr
				<?php
				if ( $is_default ) :
					?>
					style="background-color: #f0f6fc;"<?php endif; ?>>
					<td><?php echo esc_html( $group->group_id ); ?></td>
					<td>
						<?php echo esc_html( $group->group_name ); ?>
						<?php if ( $is_default ) : ?>
							<span class="dashicons dashicons-star-filled" aria-hidden="true" style="color: #2271b1; font-size: 16px; vertical-align: middle;" title="<?php esc_attr_e( 'Default group for ungrouped customers', 'alynt-customer-groups' ); ?>"></span>
							<span style="color: #2271b1; font-size: 11px; font-weight: bold;"><?php esc_html_e( 'DEFAULT', 'alynt-customer-groups' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $group->group_description ); ?></td>
					<td>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'wccg_customer_groups_action', 'wccg_customer_groups_nonce' ); ?>
							<input type="hidden" name="action" value="delete_group">
							<input type="hidden" name="group_id" value="<?php echo esc_attr( $group->group_id ); ?>">
							<?php
							if ( $is_default ) {
								submit_button(
									__( 'Delete', 'alynt-customer-groups' ),
									'delete',
									'',
									false,
									array(
										'disabled' => 'disabled',
										'title'    => __( 'Cannot delete default group', 'alynt-customer-groups' ),
									)
								);
							} else {
								submit_button( __( 'Delete', 'alynt-customer-groups' ), 'delete', '', false );
							}
							?>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php else : ?>
			<tr>
				<td colspan="4" class="no-items"><?php esc_html_e( 'No customer groups have been created yet.', 'alynt-customer-groups' ); ?></td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>
