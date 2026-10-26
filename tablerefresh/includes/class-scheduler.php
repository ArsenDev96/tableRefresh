<?php
/**
 * Automatic synchronization with WP-Cron.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

defined( 'ABSPATH' ) || exit;

/**
 * One recurring WP-Cron event per table with automatic sync, using WordPress's own schedules.
 *
 * WP-Cron runs when the site receives visits, so a sync can run later than planned on sites
 * with little traffic (or only when a real cron job calls wp-cron.php, with DISABLE_WP_CRON).
 * Overlapping runs are harmless: the table lock lets only one of them write.
 *
 * Every change goes through apply(), which leaves exactly one event per table with the
 * configured schedule, or none if automatic sync is off or paused.
 */
final class Scheduler {

	const HOOK = 'tablerefresh_scheduled_sync';

	/**
	 * Registers the hooks.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'forget_deleted_table' ) );
	}

	/**
	 * Cron callback.
	 *
	 * @param int $post_id Table post ID.
	 */
	public static function run( $post_id ): void {
		( new Sync_Runner() )->run( (int) $post_id, 'scheduled' );
	}

	/**
	 * Makes the scheduled event of a table match its configuration.
	 *
	 * @param int                       $post_id Table post ID.
	 * @param array<string, mixed>|null $config  Configuration, or null if there is none.
	 * @param bool                      $restart Start the interval anew (after a change of frequency).
	 */
	public static function apply( int $post_id, ?array $config, bool $restart = false ): void {
		$args  = array( $post_id );
		$event = wp_get_scheduled_event( self::HOOK, $args );
		if ( null === $config || empty( $config['enabled'] ) || '' !== $config['attention'] ) {
			wp_clear_scheduled_hook( self::HOOK, $args );
			return;
		}
		if ( ! $restart && $event && $config['frequency'] === $event->schedule && 1 === self::count_events( $post_id ) ) {
			return;
		}
		$schedules = wp_get_schedules();
		$interval  = isset( $schedules[ $config['frequency'] ]['interval'] ) ? (int) $schedules[ $config['frequency'] ]['interval'] : DAY_IN_SECONDS;
		wp_clear_scheduled_hook( self::HOOK, $args );
		wp_schedule_event( time() + $interval, $config['frequency'], self::HOOK, $args );
	}

	/**
	 * Time of the next scheduled sync of a table.
	 *
	 * @param int $post_id Table post ID.
	 * @return int|null Unix time, or null if none is scheduled.
	 */
	public static function next_run( int $post_id ): ?int {
		$time = wp_next_scheduled( self::HOOK, array( $post_id ) );
		return false === $time ? null : (int) $time;
	}

	/**
	 * Brings all events in line with the stored configurations: adds missing ones, removes
	 * duplicates and events of tables without a configuration. Runs on activation.
	 */
	public static function reconcile(): void {
		$configs = Sync_Config::all();
		foreach ( self::scheduled_post_ids() as $post_id ) {
			if ( ! isset( $configs[ $post_id ] ) ) {
				wp_clear_scheduled_hook( self::HOOK, array( $post_id ) );
			}
		}
		foreach ( $configs as $post_id => $config ) {
			self::apply( $post_id, $config );
		}
	}

	/**
	 * Removes all events (on deactivation). Configurations are kept.
	 */
	public static function clear_all(): void {
		wp_unschedule_hook( self::HOOK );
	}

	/**
	 * Drops the configuration and event of a table that TablePress deletes.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function forget_deleted_table( $post_id ): void {
		$post_id = (int) $post_id;
		if ( null !== Sync_Config::get( $post_id ) || null !== self::next_run( $post_id ) ) {
			wp_clear_scheduled_hook( self::HOOK, array( $post_id ) );
			Sync_Config::delete( $post_id );
		}
	}

	/**
	 * Number of scheduled events for a table.
	 *
	 * @param int $post_id Table post ID.
	 * @return int
	 */
	public static function count_events( int $post_id ): int {
		return count( array_keys( self::scheduled_post_ids(), $post_id, true ) );
	}

	/**
	 * Post IDs of all scheduled events, once per event.
	 *
	 * @return int[]
	 */
	private static function scheduled_post_ids(): array {
		$post_ids = array();
		foreach ( (array) _get_cron_array() as $hooks ) {
			if ( isset( $hooks[ self::HOOK ] ) && is_array( $hooks[ self::HOOK ] ) ) {
				foreach ( $hooks[ self::HOOK ] as $event ) {
					$post_ids[] = isset( $event['args'][0] ) ? (int) $event['args'][0] : 0;
				}
			}
		}
		return $post_ids;
	}
}
