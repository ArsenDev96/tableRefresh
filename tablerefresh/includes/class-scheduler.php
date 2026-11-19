<?php
/**
 * Automatic synchronization with WP-Cron.
 *
 * @package TableRefresh
 */

namespace ArsenManukyan\TableRefresh;

defined( 'ABSPATH' ) || exit;

/**
 * One recurring WP-Cron event per table with automatic sync, using WordPress's own schedules.
 *
 * WP-Cron runs when the site receives visits, so a sync can run later than planned on sites
 * with little traffic (or only when a real cron job calls wp-cron.php, with DISABLE_WP_CRON).
 * Overlapping runs are harmless: the table lock lets only one of them write.
 *
 * WP-Cron runs all due events one after another in one request, oldest first, and moves each event
 * to its next time before it runs it. A web request that has used up its time budget starts no
 * further sync and hands the remaining tables to the next request (see defer()), so that a host that
 * stops long requests does not cut off the same table every time. A deferred table keeps the time at
 * which it became due, so tables that wait longer run first, however often they are deferred.
 *
 * Every change goes through apply(), which leaves exactly one event per table with the
 * configured schedule, or none if automatic sync is off or paused.
 */
final class Scheduler {

	const HOOK = 'tablerefresh_scheduled_sync';

	/**
	 * Post meta: the due time of the last scheduled sync that started (the last turn taken).
	 */
	const TURN_META_KEY = '_tablerefresh_turn';

	/**
	 * Seconds that a web request running scheduled tasks may have run before it starts no further sync.
	 */
	const TIME_BUDGET = 10;

	/**
	 * Scheduled syncs started in this request.
	 *
	 * @var int
	 */
	private static int $started = 0;

	/**
	 * When the first scheduled sync of this request started (if the request start time is unknown).
	 *
	 * @var float
	 */
	private static float $first_start = 0.0;

	/**
	 * Times at which the events that WP-Cron is about to run in this request were due, by post ID.
	 *
	 * @var array<int, int>
	 */
	private static array $due_times = array();

	/**
	 * Registers the hooks.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'forget_deleted_table' ) );
		add_filter( 'pre_reschedule_event', array( __CLASS__, 'note_due_time' ), 10, 2 );
	}

	/**
	 * Notes when an event of a table was due, right before WP-Cron (or WP-CLI) moves it to its next
	 * time and runs it. Changes nothing.
	 *
	 * @param mixed  $pre   Value of the filter (returned as it is).
	 * @param object $event Event, with the time at which it was due.
	 * @return mixed
	 */
	public static function note_due_time( $pre, $event ) {
		if ( is_object( $event ) && isset( $event->hook, $event->timestamp, $event->args[0] ) && self::HOOK === $event->hook ) {
			self::$due_times[ (int) $event->args[0] ] = (int) $event->timestamp;
		}
		return $pre;
	}

	/**
	 * Cron callback.
	 *
	 * @param int $post_id Table post ID.
	 */
	public static function run( $post_id ): void {
		$post_id = (int) $post_id;
		$due     = isset( self::$due_times[ $post_id ] ) ? self::$due_times[ $post_id ] : null;
		unset( self::$due_times[ $post_id ] );
		if ( self::over_budget() ) {
			self::defer( $post_id, $due );
			return;
		}
		if ( 0 === self::$started++ ) {
			self::$first_start = microtime( true );
		}
		if ( null !== $due && $due <= time() && null !== Sync_Config::get( $post_id ) ) {
			// Before the sync, so that an overlapping request does not hand this turn back (see defer()).
			// A sync that is cut off has used its turn as well. Not for a table without a configuration
			// (it may have been deleted), and never a time that has not come yet (see defer()).
			update_post_meta( $post_id, self::TURN_META_KEY, $due );
		}
		$result = ( new Sync_Runner() )->run( $post_id, 'scheduled' );

		// Every other outcome already applied the configuration. When two cron runners reschedule
		// the same event at the same moment, the second identical write changes no row, so
		// update_option() keeps that runner's older copy of the cron array, and the runner's next
		// write drops this event (or keeps the old one as well). The runner that finds the table
		// busy may be that runner: make its copy hold exactly one event again.
		if ( 'busy' === $result['outcome'] ) {
			self::apply( $post_id, Sync_Config::get( $post_id ) );
		}
	}

	/**
	 * Whether this scheduled-task request has used up its time budget and should start no further sync.
	 *
	 * The first scheduled sync of a request always starts, so every request makes progress.
	 *
	 * @return bool
	 */
	private static function over_budget(): bool {
		if ( 0 === self::$started || ! wp_doing_cron() ) {
			return false;
		}
		/**
		 * Filters how long a request running scheduled tasks may have run before it starts no further sync.
		 *
		 * WP-Cron runs all due syncs one after another in one request. A host that stops long requests
		 * would cut off the sync in progress, and since the order of the syncs stays the same, the same
		 * table every time. Syncs that are not started run with the next request. The first due sync of a
		 * request always starts, and a sync that starts can take up to its download timeout (15 seconds)
		 * and the time to save the table longer.
		 *
		 * @param float $seconds Default 10 for web requests; 0 (no budget) on the command line (a server
		 *                       cron job running wp-cron.php or WP-CLI), where PHP sets no time limit.
		 *                       0 or less disables the budget.
		 */
		$budget = (float) apply_filters( 'tablerefresh_cron_time_budget', 'cli' === PHP_SAPI ? 0 : self::TIME_BUDGET );
		if ( $budget <= 0 ) {
			return false;
		}
		$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : self::$first_start;
		return microtime( true ) - $start >= $budget;
	}

	/**
	 * Hands the sync of a table to the next request running scheduled tasks.
	 *
	 * WP-Cron has already moved the event to its next regular time. It goes back to the time at
	 * which it was due, as WP-Cron reported it before moving it (note_due_time()). A table that is
	 * deferred again goes back to the same time again, so it keeps its place before every table that
	 * became due later, and the oldest waiting table is the first sync of the next request, which
	 * always runs. The due time is one of the table's regular times, so these stay the same.
	 *
	 * The event in WP-Cron's list is the record of the waiting table. Besides it, run() keeps the due
	 * time of the last turn taken (TURN_META_KEY); every later due time is greater, also after
	 * apply() replaced the event (a table turned off, paused, or set to another frequency). A turn
	 * time that has not come yet cannot be a turn taken (the server clock was set back, or the value
	 * comes from a server whose clock was ahead): it is removed, so that it cannot keep the table
	 * from being handed on. apply() removes the meta with the configuration, uninstall with the
	 * other TableRefresh meta.
	 *
	 * @param int      $post_id Table post ID.
	 * @param int|null $due     Time at which the event was due, if WP-Cron reported it.
	 */
	private static function defer( int $post_id, ?int $due ): void {
		$config = Sync_Config::get( $post_id );
		if ( null === $config || empty( $config['enabled'] ) || '' !== $config['attention'] ) {
			self::apply( $post_id, $config ); // Removes the event.
			return;
		}
		$events = self::events_of( $post_id );
		if ( 1 !== count( $events ) ) {
			// This request's copy of WP-Cron's list may be outdated (see place_event()): look at the stored list.
			self::refresh_cron();
			$events = self::events_of( $post_id );
		}
		$event = 1 === count( $events ) ? $events[0] : null;
		if ( null === $event || $config['frequency'] !== $event['schedule'] || $event['interval'] <= 0 ) {
			// Not what WP-Cron leaves behind (for example, the frequency changed meanwhile): one event, due now.
			self::place_event( $post_id, time(), $config['frequency'] );
			return;
		}
		$interval = $event['interval'];
		$latest   = min( time(), $event['time'] - $interval ); // The last regular time that has passed.

		$turn = (int) get_post_meta( $post_id, self::TURN_META_KEY, true );
		if ( $turn > time() ) {
			delete_post_meta( $post_id, self::TURN_META_KEY ); // Not a turn taken: from another clock.
			$turn = 0;
		}

		// An overlapping request that started from the same list of due events may already have started
		// the sync for this due time (it may still be running): that was the table's turn. Handing it back
		// would sync the table once more. The event stays at its next regular time, as after a busy run.
		if ( null !== $due && $turn >= $due ) {
			return;
		}

		// Only a regular time of the current schedule that has passed (not, for example, an event run early).
		$time = null !== $due && $due <= $latest && 0 === ( $latest - $due ) % $interval ? $due : $latest;

		if ( ! self::place_event( $post_id, $time, $config['frequency'] ) && ! self::events_of( $post_id ) ) {
			self::apply( $post_id, $config ); // No event at all (not left by place_event()): start the interval anew.
		}
	}

	/**
	 * Makes the event at a given time the only event of a table.
	 *
	 * Adds before it removes, and removes only once the new event is in WP-Cron's list: a process
	 * that ends at any point leaves the table with at least one event (possibly with more, which the
	 * next run of the table replaces with one), and a failed write leaves the events as they are.
	 *
	 * WP-Cron's list is one option, and every request changes its own copy of it. A write that fails,
	 * or that changes no row because another request stored the very same list a moment before,
	 * leaves this request's copy outdated (update_option() does not update it then); its next write
	 * would then drop or duplicate events. After such a write, the copy is read again. The result is
	 * checked, and repaired once.
	 *
	 * @param int    $post_id   Table post ID.
	 * @param int    $time      Time of the event.
	 * @param string $frequency Schedule of the event.
	 * @return bool Whether the table has exactly this event now.
	 */
	private static function place_event( int $post_id, int $time, string $frequency ): bool {
		$args = array( $post_id );
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			if ( ! self::has_event( $post_id, $time, $frequency ) ) {
				if ( true !== wp_schedule_event( $time, $frequency, self::HOOK, $args ) ) {
					self::refresh_cron();
				}
				if ( ! self::has_event( $post_id, $time, $frequency ) ) {
					continue; // Not scheduled: the other events stay.
				}
			}
			$removed = true;
			foreach ( self::events_of( $post_id ) as $event ) {
				if ( $time === $event['time'] && $args === $event['args'] ) {
					continue;
				}
				if ( true !== wp_unschedule_event( $event['time'], self::HOOK, $event['args'] ) ) {
					$removed = false;
				}
			}
			if ( ! $removed ) {
				self::refresh_cron();
			}
			if ( 1 === count( self::events_of( $post_id ) ) && self::has_event( $post_id, $time, $frequency ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a table has an event at a given time with a given schedule.
	 *
	 * @param int    $post_id   Table post ID.
	 * @param int    $time      Time of the event.
	 * @param string $frequency Schedule of the event.
	 * @return bool
	 */
	private static function has_event( int $post_id, int $time, string $frequency ): bool {
		foreach ( self::events_of( $post_id ) as $event ) {
			if ( $time === $event['time'] && $frequency === $event['schedule'] && array( $post_id ) === $event['args'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Makes this request read WP-Cron's list from the database again.
	 */
	private static function refresh_cron(): void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'cron', 'options' );
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
			if ( null === $config ) {
				delete_post_meta( $post_id, self::TURN_META_KEY ); // Nothing of the scheduler stays without a configuration.
			}
			return;
		}
		if ( ! $restart && $event && $config['frequency'] === $event->schedule && 1 === self::count_events( $post_id ) ) {
			return;
		}
		$schedules = wp_get_schedules();
		$interval  = isset( $schedules[ $config['frequency'] ]['interval'] ) ? (int) $schedules[ $config['frequency'] ]['interval'] : DAY_IN_SECONDS;
		self::place_event( $post_id, time() + $interval, $config['frequency'] ); // Never without an event in between.
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
	 * The scheduled events of a table, as this request sees WP-Cron's list, earliest first.
	 *
	 * @param int $post_id Table post ID.
	 * @return array<int, array{time: int, schedule: string, interval: int, args: array<int, mixed>}>
	 */
	private static function events_of( int $post_id ): array {
		$events = array();
		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			if ( isset( $hooks[ self::HOOK ] ) && is_array( $hooks[ self::HOOK ] ) ) {
				foreach ( $hooks[ self::HOOK ] as $event ) {
					if ( isset( $event['args'][0] ) && $post_id === (int) $event['args'][0] ) {
						$events[] = array(
							'time'     => (int) $time,
							'schedule' => isset( $event['schedule'] ) ? (string) $event['schedule'] : '',
							'interval' => isset( $event['interval'] ) ? (int) $event['interval'] : 0,
							'args'     => $event['args'],
						);
					}
				}
			}
		}
		return $events;
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
