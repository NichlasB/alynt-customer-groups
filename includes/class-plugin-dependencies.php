<?php
/**
 * Plugin dependency checking and upgrade scheduling.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verifies PHP, WordPress, and WooCommerce version requirements and schedules database upgrades.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Plugin_Dependencies {
	private static $instance   = null;
	private $installed_version = '';

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Plugin_Dependencies
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Check all dependency requirements and queue admin notices for any that fail.
	 *
	 * @since  1.0.0
	 * @param  WCCG_Customer_Groups $plugin The main plugin instance used to attach notice callbacks.
	 * @return bool True if all dependencies are met, false if any check failed.
	 */
	public function check_dependencies( $plugin ) {
		$dependencies_met = true;

		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			/**
			 * Fires in the admin area to display a PHP version requirement notice.
			 *
			 * @since 1.0.0
			 */
			add_action( 'admin_notices', array( $plugin, 'php_version_notice' ) );
			$dependencies_met = false;
		}

		if ( version_compare( $GLOBALS['wp_version'], '5.8', '<' ) ) {
			/**
			 * Fires in the admin area to display a WordPress version requirement notice.
			 *
			 * @since 1.0.0
			 */
			add_action( 'admin_notices', array( $plugin, 'wp_version_notice' ) );
			$dependencies_met = false;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			/**
			 * Fires in the admin area to display a missing WooCommerce dependency notice.
			 *
			 * @since 1.0.0
			 */
			add_action( 'admin_notices', array( $plugin, 'woocommerce_notice' ) );
			$dependencies_met = false;
		}

		if ( class_exists( 'WooCommerce' ) && defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '5.0', '<' ) ) {
			/**
			 * Fires in the admin area to display a WooCommerce version requirement notice.
			 *
			 * @since 1.0.0
			 */
			add_action( 'admin_notices', array( $plugin, 'woocommerce_version_notice' ) );
			$dependencies_met = false;
		}

		return $dependencies_met;
	}

	/**
	 * Schedule a database upgrade check when the installed version differs from the current version.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function schedule_upgrade_check() {
		$installed_version = get_option( 'wccg_version', '' );

		if ( $installed_version === WCCG_VERSION ) {
			return;
		}

		$this->installed_version = $installed_version;

		/**
		 * Fires once all plugins are loaded so pending database upgrades can run before normal startup.
		 *
		 * @since 1.0.0
		 */
		add_action( 'plugins_loaded', array( $this, 'run_upgrade_check' ), 5 );
	}

	/**
	 * Execute any pending database upgrades after all plugins are loaded.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function run_upgrade_check() {
		if ( ! class_exists( 'WCCG_Database' ) ) {
			return;
		}

		$db              = WCCG_Database::instance();
		$upgrade_success = $db->run_upgrades( $this->installed_version );

		if ( $upgrade_success ) {
			update_option( 'wccg_version', WCCG_VERSION );
			return;
		}

		if ( class_exists( 'WCCG_Utilities' ) ) {
			WCCG_Utilities::instance()->log_error(
				'Plugin upgrade failed',
				array(
					'from_version' => $this->installed_version,
					'to_version'   => WCCG_VERSION,
				),
				'critical'
			);
		}
	}
}
