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
	 * Writes (or replaces) the journal entry for a table.
	 *
	 * @param string               $table_id Table ID.
	 * @param array<string, mixed> $entry    Entry.
	 * @return bool
	 */
	public static function write( string $table_id, array $entry ): bool {
		global $wpdb;
		$json = wp_json_encode( $entry );
		if ( false === $json ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be durable and bypass caches.
		$result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", self::option_name( $table_id ), $json ) );
		return false !== $result && null !== self::read( $table_id );
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
	 * @param string $table_id Table ID.
	 */
	public static function delete( string $table_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See write().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::option_name( $table_id ) ) );
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
