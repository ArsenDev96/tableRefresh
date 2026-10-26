<?php
/**
 * Write-ahead journal for table updates.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

defined( 'ABSPATH' ) || exit;

/**
 * Stores a raw snapshot of a table before it is modified, outside of the write transaction.
 *
 * If the process dies during the write (fatal error, timeout, killed worker), the next
 * sync of that table finds the journal and either confirms that the write was rolled back
 * or completed, or restores the snapshot. This also protects databases without
 * transactional tables (e.g. MyISAM), where a crash can leave a half-written table.
 */
final class Journal {

	const PREFIX = 'tablerefresh_journal_';

	/**
	 * Creates the journal entry for a table. Never replaces an existing entry.
	 *
	 * An existing entry belongs to an update that is still running elsewhere, or still has to be
	 * recovered, so the write fails and the caller must not modify the table.
	 *
	 * @param string               $table_id Table ID.
	 * @param array<string, mixed> $entry    Entry, with the lock owner in "owner".
	 * @return bool Whether this entry is now the table's journal.
	 */
	public static function write( string $table_id, array $entry ): bool {
		global $wpdb;
		$json = wp_json_encode( $entry );
		if ( false === $json || empty( $entry['owner'] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be durable and bypass caches.
		$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::option_name( $table_id ), $json ) );
		$stored = self::read( $table_id );
		return 1 === $result && null !== $stored && isset( $stored['owner'] ) && $stored['owner'] === $entry['owner'];
	}

	/**
	 * Reads the journal entry for a table.
	 *
	 * @param string $table_id Table ID.
	 * @return array<string, mixed>|null
	 */
	public static function read( string $table_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See write().
		$json = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::option_name( $table_id ) ) );
		if ( null === $json ) {
			return null;
		}
		$entry = json_decode( $json, true );
		return is_array( $entry ) ? $entry : null;
	}

	/**
	 * Deletes the journal entry for a table.
	 *
	 * @param string      $table_id Table ID.
	 * @param string|null $owner    Only delete the entry of this lock owner. Null deletes any entry
	 *                              (recovery, which runs while holding the lock).
	 */
	public static function delete( string $table_id, ?string $owner = null ): void {
		global $wpdb;
		if ( null === $owner ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See write().
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::option_name( $table_id ) ) );
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See write().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::option_name( $table_id ), '%' . $wpdb->esc_like( '"owner":' . wp_json_encode( $owner ) ) . '%' ) );
	}

	/**
	 * Option name for a table's journal.
	 *
	 * @param string $table_id Table ID.
	 * @return string
	 */
	public static function option_name( string $table_id ): string {
		return self::PREFIX . md5( $table_id );
	}
}
