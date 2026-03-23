<?php
/**
 * LLM Bot Monitor — Uninstall handler.
 * Runs when the plugin is deleted from the WordPress admin.
 * Removes ALL plugin data: table, options, cron events.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove scheduled cron event.
wp_clear_scheduled_hook( 'llm_bot_monitor_daily_cleanup' );

// Drop the log table.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}llm_bot_log" );

// Delete all plugin options.
delete_option( 'llm_bot_monitor_db_version' );

// Clean up any orphaned options matching our prefix.
$wpdb->query( $wpdb->prepare(
	"DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE %s",
	$wpdb->esc_like( 'llm_bot_monitor_' ) . '%'
) );
