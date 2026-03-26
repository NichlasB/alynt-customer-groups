<?php
/**
 * Plugin uninstallation script.
 *
 * @package Alynt_Customer_Groups
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Perform uninstallation only if current user has sufficient permissions.
if ( ! current_user_can( 'activate_plugins' ) ) {
	return;
}

// Respect preserve-data setting.
$preserve_data = (bool) get_option( 'wccg_preserve_data_on_uninstall', false );
if ( is_multisite() ) {
	$preserve_data = $preserve_data || (bool) get_site_option( 'wccg_preserve_data_on_uninstall', false );
}

if ( $preserve_data ) {
	return;
}

global $wpdb;

// Log uninstallation if WP_DEBUG is enabled.
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	error_log( 'Starting Alynt Customer Groups uninstallation' );
}

// Clean a single site's plugin data in the active blog context.
$cleanup_site_data = static function () use ( $wpdb ) {
	$tables = array(
		$wpdb->prefix . 'customer_groups',
		$wpdb->prefix . 'user_groups',
		$wpdb->prefix . 'pricing_rules',
		$wpdb->prefix . 'rule_products',
		$wpdb->prefix . 'rule_categories',
		$wpdb->prefix . 'wccg_error_log',
	);

	// Remove scheduled events.
	wp_clear_scheduled_hook( 'wccg_cleanup_cron' );
	wp_clear_scheduled_hook( 'wccg_check_expired_rules' );

	// Drop tables with error checking.
	foreach ( $tables as $table ) {
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be parameterized; uninstall drops only trusted plugin-owned tables.
			'DROP TABLE IF EXISTS ' . $table
		);
		if ( $wpdb->last_error && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "Error dropping table {$table}: " . $wpdb->last_error );
		}
	}

	// Clean up all plugin options.
	$options = array(
		'wccg_version',
		'wccg_schema_version',
		'wccg_installation_date',
		'wccg_last_cleanup',
		'wccg_settings',
		'wccg_default_group_id',
		'wccg_default_group_custom_title',
		'wccg_customer_groups_action_notice',
		'wccg_preserve_data_on_uninstall',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Clear all plugin transients.
	$transient_pattern = $wpdb->esc_like( '_transient_wccg_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_pattern ) );
	$timeout_pattern = $wpdb->esc_like( '_transient_timeout_wccg_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout_pattern ) );
};

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		$cleanup_site_data();
		restore_current_blog();
	}
} else {
	$cleanup_site_data();
}

// Remove user meta.
$user_meta_pattern = $wpdb->esc_like( 'wccg_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $user_meta_pattern ) );

// Clear any cached data.
wp_cache_flush();

// Log completion if WP_DEBUG is enabled.
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	error_log( 'Completed Alynt Customer Groups uninstallation' );
}
