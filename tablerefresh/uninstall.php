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

// Scheduled syncs (already removed on deactivation).
wp_unschedule_hook( 'tablerefresh_scheduled_sync' );

// Sync baselines, editor sessions, sync configurations, and history stored on TablePress table posts.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$tablerefresh_post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_tablerefresh_' ) . '%' ) );
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_tablerefresh_' ) . '%'
	)
);
// phpcs:enable
// A persistent object cache would otherwise keep serving the deleted values.
foreach ( $tablerefresh_post_ids as $tablerefresh_post_id ) {
	wp_cache_delete( (int) $tablerefresh_post_id, 'post_meta' );
}
