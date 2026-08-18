<?php
/**
 * Uninstall Accesstive App.
 *
 * @package AccesstiveApp
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'accesstive_app_token' );

global $wpdb;
if ( isset( $wpdb->options ) ) {
	$accesstive_app_like         = $wpdb->esc_like( '_transient_accesstive_app_' ) . '%';
	$accesstive_app_timeout_like = $wpdb->esc_like( '_transient_timeout_accesstive_app_' ) . '%';
	$accesstive_app_asset_like   = $wpdb->esc_like( 'accesstive_app_asset_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$accesstive_app_like,
			$accesstive_app_timeout_like,
			$accesstive_app_asset_like
		)
	);
}
