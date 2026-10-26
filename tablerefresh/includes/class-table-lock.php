<?php
/**
 * Per-table mutual exclusion.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A lock row in the options table, acquired with an atomic INSERT IGNORE.
 *
 * Same technique as WP_Upgrader::create_lock(). add_option() is not used because it
 * runs INSERT ... ON DUPLICATE KEY UPDATE, which never fails on an existing row.
 * Every lock has an owner token and an expiry time, so a process that crashed is
 * taken over (compare-and-swap) once its lock has expired. Lock rows are never read
 * through get_option(), so persistent object caches cannot return stale values.
 */
final class Table_Lock {

	const PREFIX = 'tablerefresh_lock_';

	/**
	 * Option name of this lock.
	 *
	 * @var string
	 */
	private string $option_name;

	/**
	 * Stored value "{expires}|{token}" that proves ownership.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Whether an expired lock of another process was taken over.
	 *
	 * @var bool
	 */
	private bool $took_over;

	/**
	 * Constructor.
	 *
	 * @param string $option_name Option name.
	 * @param string $value       Owner value.
	 * @param bool   $took_over   Whether an expired lock was taken over.
	 */
	private function __construct( string $option_name, string $value, bool $took_over ) {
		$this->option_name = $option_name;
		$this->value       = $value;
		$this->took_over   = $took_over;
	}

	/**
	 * Tries to acquire the lock for a table without waiting.
	 *
	 * @param string $table_id Table ID.
	 * @return Table_Lock|WP_Error
	 */
	public static function acquire( string $table_id ) {
		global $wpdb;

		/**
		 * Filters the lifetime of a sync lock, after which it is considered abandoned.
		 *
		 * Must be longer than the longest possible sync (download timeout plus saving).
		 *
		 * @param int    $ttl      Seconds. Default 300.
		 * @param string $table_id Table ID.
		 */
		$ttl   = max( 1, (int) apply_filters( 'tablerefresh_lock_ttl', 300, $table_id ) );
		$name  = self::option_name( $table_id );
		$value = ( time() + $ttl ) . '|' . wp_generate_password( 24, false );

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock.
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value ) );
			if ( false === $inserted ) {
				return new WP_Error( 'tablerefresh_lock_failed', __( 'The sync lock could not be created.', 'tablerefresh' ) );
			}
			if ( 1 === $inserted ) {
				return new self( $name, $value, false );
			}

			$current = self::read( $name );
			if ( null === $current ) {
				continue; // Released in the meantime; try once more.
			}

			$expires = (int) strtok( $current, '|' );
			if ( $expires > time() ) {
				return new WP_Error(
					'tablerefresh_locked',
					__( 'Another synchronization of this table is in progress.', 'tablerefresh' ),
					array( 'expires' => $expires )
				);
			}

			// Expired: take over only if nobody else did in the meantime.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap.
			$swapped = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $current ) );
			if ( 1 === $swapped ) {
				return new self( $name, $value, true );
			}
			return new WP_Error( 'tablerefresh_locked', __( 'Another synchronization of this table is in progress.', 'tablerefresh' ) );
		}

		return new WP_Error( 'tablerefresh_locked', __( 'Another synchronization of this table is in progress.', 'tablerefresh' ) );
	}

	/**
	 * Whether this process still owns the lock.
	 *
	 * @param bool $for_update Lock the row until the current transaction ends, so that
	 *                         nobody can take the lock over before the commit.
	 * @return bool
	 */
	public function is_held( bool $for_update = false ): bool {
		return self::read( $this->option_name, $for_update ) === $this->value;
	}

	/**
	 * Whether an expired lock from another (crashed) process was taken over.
	 *
	 * @return bool
	 */
	public function took_over(): bool {
		return $this->took_over;
	}

	/**
	 * Releases the lock if this process still owns it.
	 *
	 * @return bool Whether the lock was released.
	 */
	public function release(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic release of own lock only.
		return 1 === $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $this->option_name, $this->value ) );
	}

	/**
	 * Whether any process currently holds an unexpired lock for a table.
	 *
	 * @param string $table_id Table ID.
	 * @return bool
	 */
	public static function is_locked( string $table_id ): bool {
		$current = self::read( self::option_name( $table_id ) );
		return null !== $current && (int) strtok( $current, '|' ) > time();
	}

	/**
	 * Option name for a table's lock.
	 *
	 * @param string $table_id Table ID.
	 * @return string
	 */
	public static function option_name( string $table_id ): string {
		return self::PREFIX . md5( $table_id );
	}

	/**
	 * Reads a lock value directly from the database.
	 *
	 * @param string $name       Option name.
	 * @param bool   $for_update Whether to use SELECT ... FOR UPDATE.
	 * @return string|null
	 */
	private static function read( string $name, bool $for_update = false ): ?string {
		global $wpdb;
		$sql = $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) . ( $for_update ? ' FOR UPDATE' : '' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		$value = $wpdb->get_var( $sql );
		return null === $value ? null : (string) $value;
	}
}
