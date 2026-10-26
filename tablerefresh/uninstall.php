<?php
/**
 * Removes TableRefresh's own data. TablePress tables are not touched.
 *
 * @package TableRefresh
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Locks and recovery journals; option names are prefixed and hashed per table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'tablerefresh_lock_' ) . '%',
		$wpdb->esc_like( 'tablerefresh_journal_' ) . '%'
	)
);
