<?php
// Removes the plugin's log table and settings when it is deleted from the Plugins screen.

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'capi_auditor_log' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
delete_option( 'capi_auditor_settings' );
delete_option( 'capi_auditor_db_version' );
wp_clear_scheduled_hook( 'capi_auditor_purge' );
