<?php
/**
 * Plugin uninstallation script
 */

// If uninstall not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Perform uninstallation only if current user has sufficient permissions
if ( ! current_user_can( 'activate_plugins' ) ) {
	return;
}

global $wpdb;

// Define tables to remove
$tables = array(
	$wpdb->prefix . 'customer_groups',
	$wpdb->prefix . 'user_groups',
	$wpdb->prefix . 'pricing_rules',
	$wpdb->prefix . 'rule_products',
	$wpdb->prefix . 'rule_categories',
	$wpdb->prefix . 'wccg_error_log', // Added error log table
);

// Remove scheduled events
wp_clear_scheduled_hook( 'wccg_cleanup_cron' );

// Log uninstallation if WP_DEBUG is enabled
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	error_log( 'Starting Alynt Customer Groups uninstallation' );
}

// Drop tables with error checking
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	if ( $wpdb->last_error ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "Error dropping table {$table}: " . $wpdb->last_error );
		}
	}
}

// Clean up all plugin options
$options = array(
	'wccg_version',
	'wccg_installation_date',
	'wccg_last_cleanup',
	'wccg_settings', // If you add settings in the future
	'wccg_default_group_id', // Default group for ungrouped customers
	'wccg_default_group_custom_title', // Custom title for default group display
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear all plugin transients
$transient_pattern = $wpdb->esc_like( '_transient_wccg_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_pattern ) );
$timeout_pattern = $wpdb->esc_like( '_transient_timeout_wccg_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout_pattern ) );

// Remove user meta
$user_meta_pattern = $wpdb->esc_like( 'wccg_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $user_meta_pattern ) );

// Clear any cached data
wp_cache_flush();

// Log completion if WP_DEBUG is enabled
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	error_log( 'Completed Alynt Customer Groups uninstallation' );
}
