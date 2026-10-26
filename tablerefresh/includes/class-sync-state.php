<?php
/**
 * Baseline of the last synchronization of a table.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers which data TableRefresh last wrote to a table, to detect manual edits since then.
 *
 * Stored as hidden post meta on the TablePress table post. It is written in the same
 * transaction as the table data and is part of every snapshot, journal entry, and restore,
 * so it can never describe data that is not stored. A copy of a table (made in TablePress)
 * has no baseline, so its first sync is treated like the first sync of any table.
 */
final class Sync_State {

	const META_KEY = '_tablerefresh_sync_state';

	/**
	 * Fingerprint of table data, independent of how TablePress encodes the JSON.
	 *
	 * @param array<int, array<int, string>> $data Table data.
	 * @return string
	 */
	public static function data_hash( array $data ): string {
		return md5( (string) wp_json_encode( $data ) );
	}

	/**
	 * Builds the stored value for a sync of the given data.
	 *
	 * @param string $data_hash Result of data_hash().
	 * @return string
	 */
	public static function encode( string $data_hash ): string {
		return (string) wp_json_encode(
			array(
				'data_md5'  => $data_hash,
				'synced_at' => self::now(),
			)
		);
	}

	/**
	 * Decodes the baseline from a snapshot.
	 *
	 * @param array<string, string|null> $snapshot Snapshot from TablePress_Adapter::read_snapshot().
	 * @return array{data_md5: string, synced_at: string}|null Null if the table was never synced.
	 */
	public static function from_snapshot( array $snapshot ): ?array {
		$state = isset( $snapshot[ self::META_KEY ] ) ? json_decode( (string) $snapshot[ self::META_KEY ], true ) : null;
		if ( ! is_array( $state ) || ! isset( $state['data_md5'], $state['synced_at'] ) ) {
			return null;
		}
		return array(
			'data_md5'  => (string) $state['data_md5'],
			'synced_at' => (string) $state['synced_at'],
		);
	}

	/**
	 * Reads the baseline of a table post.
	 *
	 * @param int $post_id Table post ID.
	 * @return array{data_md5: string, synced_at: string}|null
	 */
	public static function read( int $post_id ): ?array {
		$snapshot = TablePress_Adapter::read_snapshot( $post_id );
		return null === $snapshot ? null : self::from_snapshot( $snapshot );
	}

	/**
	 * Current time with microseconds, comparable as a number.
	 *
	 * @return string
	 */
	public static function now(): string {
		return sprintf( '%.6F', microtime( true ) );
	}
}
