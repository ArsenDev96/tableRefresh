<?php
/**
 * Bounded retention of revisions created by synchronization.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps only the newest revisions that TableRefresh created, and never touches others.
 *
 * TablePress table posts support revisions, and WordPress keeps an unlimited number by
 * default, so every changed sync would store a full copy of the table forever.
 *
 * - Revisions created by a sync are marked with post meta in the same transaction.
 * - After a successful sync, marked revisions beyond the newest N are deleted
 *   (`tablerefresh_revisions_to_keep`, default 5; 0 creates none).
 * - While TableRefresh writes, WordPress's own pruning is suspended for the table, so that a
 *   site-wide limit (WP_POST_REVISIONS) never deletes manually created revisions in favor of
 *   synchronized ones. For manual saves, that limit is raised by the number of kept sync
 *   revisions, so they do not take the place of manual revisions either.
 */
final class Revision_Policy {

	const META_KEY = '_tablerefresh_sync_revision';

	/**
	 * Post ID of the table that TableRefresh is currently writing, or 0.
	 *
	 * @var int
	 */
	private static int $writing_post_id = 0;

	/**
	 * Registers the hooks.
	 */
	public static function init(): void {
		add_filter( 'wp_revisions_to_keep', array( __CLASS__, 'filter_revisions_to_keep' ), 20, 2 );
	}

	/**
	 * Number of sync revisions to keep per table.
	 *
	 * @param int $post_id Table post ID.
	 * @return int -1 for unlimited.
	 */
	public static function to_keep( int $post_id ): int {
		/**
		 * Filters how many revisions created by synchronization are kept per table.
		 *
		 * Revisions created manually in TablePress are never deleted by TableRefresh.
		 *
		 * @param int $keep    Default 5. 0 creates no revisions, -1 keeps all.
		 * @param int $post_id Table post ID.
		 */
		return max( -1, (int) apply_filters( 'tablerefresh_revisions_to_keep', 5, $post_id ) );
	}

	/**
	 * Marks the start and end of a sync write to a table.
	 *
	 * @param int $post_id Table post ID, or 0 when the write ends.
	 */
	public static function writing( int $post_id ): void {
		self::$writing_post_id = $post_id;
	}

	/**
	 * Adjusts WordPress's revision limit for TablePress tables.
	 *
	 * @param int      $num  Revisions to keep (-1: all).
	 * @param \WP_Post $post Post.
	 * @return int
	 */
	public static function filter_revisions_to_keep( $num, $post ) {
		if ( ! $post instanceof \WP_Post || ! TablePress_Adapter::is_available() || TablePress_Adapter::post_type() !== $post->post_type ) {
			return $num;
		}
		if ( self::$writing_post_id === $post->ID ) {
			return 0 === self::to_keep( $post->ID ) ? 0 : -1; // No WordPress pruning during a sync.
		}
		$num = (int) $num;
		return $num < 0 ? $num : $num + self::count( $post->ID );
	}

	/**
	 * Marks a revision as created by synchronization.
	 *
	 * Uses add_metadata() because add_post_meta() would attach the meta to the parent post.
	 *
	 * @param int $revision_id Revision ID.
	 */
	public static function mark( int $revision_id ): void {
		add_metadata( 'post', $revision_id, self::META_KEY, '1', true );
	}

	/**
	 * Highest revision ID of a table, to recognize revisions created afterwards.
	 *
	 * @param int $post_id Table post ID.
	 * @return int
	 */
	public static function max_revision_id( int $post_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Must reflect the database state.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision'", $post_id ) );
	}

	/**
	 * IDs of sync revisions of a table, newest first.
	 *
	 * @param int $post_id  Table post ID.
	 * @param int $after_id Only revisions with a higher ID.
	 * @return int[]
	 */
	public static function sync_revision_ids( int $post_id, int $after_id = 0 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Must reflect the database state.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_parent = %d AND p.post_type = 'revision' AND p.ID > %d ORDER BY p.ID DESC", self::META_KEY, $post_id, $after_id ) );
		return array_map( 'intval', $ids );
	}

	/**
	 * Number of sync revisions of a table.
	 *
	 * @param int $post_id Table post ID.
	 * @return int
	 */
	public static function count( int $post_id ): int {
		return count( self::sync_revision_ids( $post_id ) );
	}

	/**
	 * Deletes sync revisions beyond the retention limit.
	 *
	 * @param int $post_id Table post ID.
	 * @return int Number of deleted revisions.
	 */
	public static function prune( int $post_id ): int {
		$keep = self::to_keep( $post_id );
		if ( $keep < 0 ) {
			return 0;
		}
		return self::delete( array_slice( self::sync_revision_ids( $post_id ), $keep ) );
	}

	/**
	 * Deletes sync revisions created after a given revision ID (left behind by a failed write).
	 *
	 * @param int $post_id  Table post ID.
	 * @param int $after_id Revision ID before the write.
	 * @return int Number of deleted revisions.
	 */
	public static function delete_created_after( int $post_id, int $after_id ): int {
		return self::delete( self::sync_revision_ids( $post_id, $after_id ) );
	}

	/**
	 * Deletes revisions.
	 *
	 * @param int[] $revision_ids Revision IDs.
	 * @return int Number deleted.
	 */
	private static function delete( array $revision_ids ): int {
		$deleted = 0;
		foreach ( $revision_ids as $revision_id ) {
			if ( wp_delete_post_revision( $revision_id ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}
}
