<?php
/**
 * Recent synchronization results of a table.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

defined( 'ABSPATH' ) || exit;

/**
 * A short, bounded history of sync attempts, stored as hidden post meta on the table post.
 *
 * Entries hold only what an administrator needs to read: time, trigger, outcome, a readable
 * message, and the table size. No source URLs, response bodies, or internal error details.
 */
final class Sync_Log {

	const META_KEY = '_tablerefresh_log';

	/**
	 * Number of attempts kept per table.
	 */
	const MAX_ENTRIES = 10;

	/**
	 * Reads the history of a table post.
	 *
	 * @param int $post_id Table post ID.
	 * @return array{entries: array<int, array<string, mixed>>, last_success: int} Newest entry first;
	 *                                                                          last_success is 0 if none.
	 */
	public static function get( int $post_id ): array {
		$value   = json_decode( (string) get_post_meta( $post_id, self::META_KEY, true ), true );
		$entries = is_array( $value ) && isset( $value['entries'] ) && is_array( $value['entries'] ) ? array_values( $value['entries'] ) : array();
		return array(
			'entries'      => array_slice( $entries, 0, self::MAX_ENTRIES ),
			'last_success' => is_array( $value ) && isset( $value['last_success'] ) ? (int) $value['last_success'] : 0,
		);
	}

	/**
	 * The most recent attempt, or null.
	 *
	 * @param int $post_id Table post ID.
	 * @return array<string, mixed>|null
	 */
	public static function last( int $post_id ): ?array {
		$log = self::get( $post_id );
		return isset( $log['entries'][0] ) ? $log['entries'][0] : null;
	}

	/**
	 * Adds an attempt.
	 *
	 * @param int                  $post_id Table post ID.
	 * @param array<string, mixed> $entry {
	 *     The attempt.
	 *
	 *     @type int    $time    Unix time.
	 *     @type string $trigger "manual", "scheduled", "setup", or "replace".
	 *     @type string $outcome "updated", "unchanged", "conflict", or "failed".
	 *     @type string $message Readable result.
	 *     @type int    $rows    Rows stored (successful syncs).
	 *     @type int    $columns Columns stored (successful syncs).
	 * }
	 */
	public static function add( int $post_id, array $entry ): void {
		$log   = self::get( $post_id );
		$entry = array(
			'time'    => isset( $entry['time'] ) ? (int) $entry['time'] : time(),
			'trigger' => isset( $entry['trigger'] ) ? (string) $entry['trigger'] : 'manual',
			'outcome' => isset( $entry['outcome'] ) ? (string) $entry['outcome'] : 'failed',
			'message' => isset( $entry['message'] ) ? (string) $entry['message'] : '',
			'rows'    => isset( $entry['rows'] ) ? (int) $entry['rows'] : 0,
			'columns' => isset( $entry['columns'] ) ? (int) $entry['columns'] : 0,
		);
		array_unshift( $log['entries'], $entry );
		if ( in_array( $entry['outcome'], array( 'updated', 'unchanged' ), true ) ) {
			$log['last_success'] = $entry['time'];
		}
		$log['entries'] = array_slice( $log['entries'], 0, self::MAX_ENTRIES );
		update_post_meta( $post_id, self::META_KEY, wp_slash( (string) wp_json_encode( $log ) ) );
	}

	/**
	 * Removes the history of a table post.
	 *
	 * @param int $post_id Table post ID.
	 */
	public static function delete( int $post_id ): void {
		delete_post_meta( $post_id, self::META_KEY );
	}
}
