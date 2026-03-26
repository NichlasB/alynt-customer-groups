<?php
/**
 * Admin notice rendering for dependency errors.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders admin error notices when plugin dependencies are not met.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Plugin_Admin_Notices {

	/**
	 * Display a notice when the PHP version requirement is not met.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function php_version_notice() {
		$this->display_error_notice(
			sprintf(
				/* translators: %s: minimum supported PHP version. */
				esc_html__( 'Alynt Customer Groups requires PHP version %s or higher.', 'alynt-customer-groups' ),
				'7.4'
			)
		);
	}

	/**
	 * Display a notice when the WordPress version requirement is not met.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function wp_version_notice() {
		$this->display_error_notice(
			sprintf(
				/* translators: %s: minimum supported WordPress version. */
				esc_html__( 'Alynt Customer Groups requires WordPress version %s or higher.', 'alynt-customer-groups' ),
				'5.8'
			)
		);
	}

	/**
	 * Display a notice when WooCommerce is not installed or active.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function woocommerce_notice() {
		$this->display_error_notice( esc_html__( 'Alynt Customer Groups requires WooCommerce to be installed and activated.', 'alynt-customer-groups' ) );
	}

	/**
	 * Display a notice when the WooCommerce version requirement is not met.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function woocommerce_version_notice() {
		$this->display_error_notice(
			sprintf(
				/* translators: %s: minimum supported WooCommerce version. */
				esc_html__( 'Alynt Customer Groups requires WooCommerce version %s or higher.', 'alynt-customer-groups' ),
				'5.0'
			)
		);
	}

	/**
	 * Render an error notice in the WordPress admin.
	 *
	 * @since  1.0.0
	 * @param  string $message The already-escaped message to display.
	 * @return void
	 */
	protected function display_error_notice( $message ) {
		?>
		<div class="notice notice-error">
			<p><?php echo wp_kses_post( $message ); ?></p>
		</div>
		<?php
	}
}

