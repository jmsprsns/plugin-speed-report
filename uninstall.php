<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 *
 * @package Plugin_Speed_Report
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( (array) get_option( 'plugin_speed_report_runs', array() ) as $plugin_speed_report_run ) {
	if ( is_array( $plugin_speed_report_run ) && isset( $plugin_speed_report_run['id'] ) && is_string( $plugin_speed_report_run['id'] ) ) {
		delete_option( 'plugin_speed_report_run_' . $plugin_speed_report_run['id'] );
	}
}

delete_option( 'plugin_speed_report_settings' );
delete_option( 'plugin_speed_report_runs' );
delete_option( 'plugin_speed_report_rules' );
delete_option( 'plugin_speed_report_scan' );
delete_option( 'plugin_speed_report_lock' );
delete_option( 'plugin_speed_report_token' );
delete_option( 'plugin_speed_report_sample' );

// Saved scans the list lost track of.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'plugin_speed_report_run_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

// The must-use timing loader, if it's still there and is ours.
$plugin_speed_report_loader = WPMU_PLUGIN_DIR . '/0-plugin-speed-report.php';

if ( file_exists( $plugin_speed_report_loader ) && false !== strpos( (string) file_get_contents( $plugin_speed_report_loader ), '@plugin-speed-report-loader' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	wp_delete_file( $plugin_speed_report_loader );
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-plugin-speed-report' );
