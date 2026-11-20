<?php
/**
 * Configured automatic synchronization: configuration, WP-Cron events, history, pausing.
 *
 * @package TableRefresh
 */

use ArsenManukyan\TableRefresh\Editor_Guard;
use ArsenManukyan\TableRefresh\Plugin;
use ArsenManukyan\TableRefresh\Scheduler;
use ArsenManukyan\TableRefresh\Sync_Config;
use ArsenManukyan\TableRefresh\Sync_Log;
use ArsenManukyan\TableRefresh\Sync_Runner;
use ArsenManukyan\TableRefresh\Sync_State;
use ArsenManukyan\TableRefresh\Table_Lock;
use ArsenManukyan\TableRefresh\TablePress_Adapter;

/**
 * @covers \ArsenManukyan\TableRefresh\Sync_Config
 * @covers \ArsenManukyan\TableRefresh\Sync_Log
 * @covers \ArsenManukyan\TableRefresh\Sync_Runner
 * @covers \ArsenManukyan\TableRefresh\Scheduler
 */
class ScheduledSyncTest extends Integration_Test_Case {

	const V1 = "Item,Price\nApple,1\nPear,2\n";
	const V2 = "Item,Price\nApple,1\nPear,3\n";

	/**
	 * Table posts configured by the current test.
	 *
	 * @var int[]
	 */
	private array $configured = array();

	protected function tearDown(): void {
		foreach ( $this->configured as $post_id ) {
			wp_clear_scheduled_hook( Scheduler::HOOK, array( $post_id ) );
			Sync_Config::delete( $post_id );
		}
		parent::tearDown();
	}

	/**
	 * Creates a table and configures automatic sync for it, like the admin screen does.
	 *
	 * @param string $url       Source URL.
	 * @param string $frequency Frequency.
	 * @return array{0: string, 1: int} Table ID and post ID.
	 */
	private function configured_table( string $url, string $frequency = 'daily' ): array {
		$table_id = $this->create_table( array( array( 'initial' ) ) );
		$post_id  = TablePress_Adapter::get_post_id( $table_id );
		$config   = array(
			'url'       => $url,
			'frequency' => $frequency,
			'enabled'   => true,
		);
		Sync_Config::save( $post_id, $config );
		Scheduler::apply( $post_id, Sync_Config::get( $post_id ) );
		$this->configured[] = $post_id;
		return array( $table_id, $post_id );
	}

	private function run_scheduled( int $post_id ): void {
		do_action( Scheduler::HOOK, $post_id ); // What WP-Cron calls.
	}

	private function edit_in_tablepress( string $table_id, string $value ): void {
		$table               = $this->load_table( $table_id );
		$table['data'][1][1] = $value;
		wp_set_current_user( 1 );
		$this->assertNotInstanceOf( WP_Error::class, TablePress::$model_table->save( $table ) );
		wp_set_current_user( 0 );
	}

	public function test_configuration_round_trip_and_url_validation() {
		list( , $post_id ) = $this->configured_table( 'https://docs.google.com/spreadsheets/d/abc/edit#gid=0', 'weekly' );
		$config            = Sync_Config::get( $post_id );
		$this->assertSame( 'weekly', $config['frequency'] );
		$this->assertTrue( $config['enabled'] );
		$this->assertSame( '', $config['attention'] );
		$this->assertSame( 'Google Sheets', Sync_Config::source_label( $config['url'] ) );
		$this->assertSame( 'example.com', Sync_Config::source_label( 'https://example.com/a.csv' ) );
		$this->assertArrayHasKey( $post_id, Sync_Config::all() );

		$this->assertSame( 'https://example.com/a.csv', Sync_Config::validate_url( '  https://example.com/a.csv ' ) );
		foreach ( array( '', 'example.com/a.csv', 'ftp://example.com/a.csv', 'javascript:alert(1)', 'https://exa mple.com/', 'https://user:pw@example.com/a.csv', 'https://' . str_repeat( 'a', 2050 ) ) as $invalid ) {
			$this->assertInstanceOf( WP_Error::class, Sync_Config::validate_url( $invalid ), $invalid );
		}
	}

	public function test_exactly_one_event_follows_the_configuration() {
		list( , $post_id ) = $this->configured_table( $this->fixture( 's.csv', self::V1 ) );
		$this->assertSame( 1, Scheduler::count_events( $post_id ) );
		$this->assertSame( 'daily', wp_get_scheduled_event( Scheduler::HOOK, array( $post_id ) )->schedule );
		$this->assertGreaterThan( time() + DAY_IN_SECONDS - 60, Scheduler::next_run( $post_id ), 'The first automatic sync is one interval after setup.' );

		Scheduler::apply( $post_id, Sync_Config::get( $post_id ) );
		$this->assertSame( 1, Scheduler::count_events( $post_id ), 'Applying twice must not add an event.' );

		$config              = Sync_Config::get( $post_id );
		$config['frequency'] = 'hourly';
		Sync_Config::save( $post_id, $config );
		Scheduler::apply( $post_id, Sync_Config::get( $post_id ) );
		$this->assertSame( 1, Scheduler::count_events( $post_id ) );
		$this->assertSame( 'hourly', wp_get_scheduled_event( Scheduler::HOOK, array( $post_id ) )->schedule );

		$config['enabled'] = false;
		Sync_Config::save( $post_id, $config );
		Scheduler::apply( $post_id, Sync_Config::get( $post_id ) );
		$this->assertSame( 0, Scheduler::count_events( $post_id ) );

		// Duplicates and events of unconfigured tables are removed; missing events are added.
		$config['enabled'] = true;
		Sync_Config::save( $post_id, $config );
		wp_schedule_event( time() + 100, 'hourly', Scheduler::HOOK, array( $post_id ) );
		wp_schedule_event( time() + 200, 'daily', Scheduler::HOOK, array( $post_id ) );
		wp_schedule_event( time() + 300, 'daily', Scheduler::HOOK, array( 999999999 ) );
		Scheduler::reconcile();
		$this->assertSame( 1, Scheduler::count_events( $post_id ) );
		$this->assertSame( 'hourly', wp_get_scheduled_event( Scheduler::HOOK, array( $post_id ) )->schedule );
		$this->assertSame( 0, Scheduler::count_events( 999999999 ) );
	}

	public function test_scheduled_sync_updates_the_table_and_records_history() {
		$url                      = $this->fixture( 's.csv', self::V1 );
		list( $table_id, $post_id ) = $this->configured_table( $url );

		$this->run_scheduled( $post_id );
		$this->assertSame( array( array( 'Item', 'Price' ), array( 'Apple', '1' ), array( 'Pear', '2' ) ), $this->load_table( $table_id )['data'] );
		$this->run_scheduled( $post_id );

		$log = Sync_Log::get( $post_id );
		$this->assertCount( 2, $log['entries'] );
		$this->assertSame( 'unchanged', $log['entries'][0]['outcome'], 'Newest entry first.' );
		$this->assertSame( 'updated', $log['entries'][1]['outcome'] );
		$this->assertSame( 'scheduled', $log['entries'][1]['trigger'] );
		$this->assertSame( 3, $log['entries'][1]['rows'] );
		$this->assertSame( 2, $log['entries'][1]['columns'] );
		$this->assertSame( $log['entries'][0]['time'], $log['last_success'] );
		$this->assertStringNotContainsString( 'fixtures', (string) get_post_meta( $post_id, Sync_Log::META_KEY, true ), 'The history must not contain the source URL.' );
		$this->assertSame( 1, Scheduler::count_events( $post_id ), 'The schedule continues.' );
	}

	public function test_manual_edit_pauses_automatic_sync_until_an_administrator_replaces_it() {
		$url                        = $this->fixture( 's.csv', self::V1 );
		list( $table_id, $post_id ) = $this->configured_table( $url );
		$this->run_scheduled( $post_id );
		$this->edit_in_tablepress( $table_id, 'HUMAN' );
		$this->fixture( 's.csv', self::V2 );
		$before = $this->raw_state( $table_id );

		$this->run_scheduled( $post_id );
		$this->assertSame( 'conflict', Sync_Log::last( $post_id )['outcome'] );
		$this->assertSame( Sync_Config::ATTENTION_CONFLICT, Sync_Config::get( $post_id )['attention'] );
		$this->assertSame( 0, Scheduler::count_events( $post_id ), 'Automatic sync is paused.' );
		$this->assertSame( $before, $this->raw_state( $table_id ), 'The manual edit is kept.' );

		// A cron event that was already due does nothing and is not recorded.
		$this->run_scheduled( $post_id );
		$this->assertCount( 2, Sync_Log::get( $post_id )['entries'] );
		$this->assertSame( 0, Scheduler::count_events( $post_id ) );

		// "Sync now" still refuses to overwrite the edit.
		$this->assertSame( 'conflict', ( new Sync_Runner() )->run( $post_id, 'manual' )['outcome'] );
		$this->assertSame( 'HUMAN', $this->load_table( $table_id )['data'][1][1] );

		// The administrator chooses to replace the edit with the source data.
		$result = ( new Sync_Runner() )->run( $post_id, 'replace' );
		$this->assertSame( 'updated', $result['outcome'], $result['message'] );
		$this->assertSame( '3', $this->load_table( $table_id )['data'][2][1] );
		$this->assertSame( '1', $this->load_table( $table_id )['data'][1][1] );
		$this->assertSame( '', Sync_Config::get( $post_id )['attention'] );
		$this->assertSame( 1, Scheduler::count_events( $post_id ), 'Automatic sync resumes.' );
	}

	public function test_open_editor_is_retried_at_the_next_scheduled_time() {
		$url                        = $this->fixture( 's.csv', self::V1 );
		list( $table_id, $post_id ) = $this->configured_table( $url );
		$this->run_scheduled( $post_id );
		TablePress_Adapter::write_meta( $post_id, Editor_Guard::META_PREFIX . '1', Sync_State::now() ); // admin opened the editor.
		$this->fixture( 's.csv', self::V2 );

		$this->run_scheduled( $post_id );
		$last = Sync_Log::last( $post_id );
		$this->assertSame( 'conflict', $last['outcome'] );
		$this->assertStringContainsString( 'admin', $last['message'], 'Who has the table open.' );
		$this->assertSame( '', Sync_Config::get( $post_id )['attention'], 'An open editor does not pause automatic sync.' );
		$this->assertSame( 0, Sync_Config::get( $post_id )['failures'] );
		$this->assertSame( 1, Scheduler::count_events( $post_id ) );
		$this->assertSame( '2', $this->load_table( $table_id )['data'][2][1] );
	}

	public function test_automatic_sync_pauses_after_repeated_failures() {
		list( $table_id, $post_id ) = $this->configured_table( TABLEREFRESH_TESTS_FIXTURE_URL . '/status?code=404' );
		$before                     = $this->raw_state( $table_id );

		for ( $i = 1; $i < Sync_Runner::MAX_FAILURES; $i++ ) {
			$this->run_scheduled( $post_id );
			$this->assertSame( 1, Scheduler::count_events( $post_id ), "Still scheduled after {$i} failures." );
		}
		$this->run_scheduled( $post_id );
		$config = Sync_Config::get( $post_id );
		$this->assertSame( Sync_Config::ATTENTION_FAILURES, $config['attention'] );
		$this->assertSame( Sync_Runner::MAX_FAILURES, $config['failures'] );
		$this->assertSame( 0, Scheduler::count_events( $post_id ), 'No endless retries.' );
		$this->assertStringContainsString( 'paused', Sync_Log::last( $post_id )['message'] );
		$this->assertStringStartsWith( 'Nothing was found at the source address (HTTP 404).', Sync_Log::last( $post_id )['message'] );
		$this->assertSame( $before, $this->raw_state( $table_id ) );

		// The administrator fixes the source; a successful sync resumes the schedule.
		$config['url'] = $this->fixture( 's.csv', self::V1 );
		Sync_Config::save( $post_id, $config );
		$this->assertSame( 'updated', ( new Sync_Runner() )->run( $post_id, 'manual' )['outcome'] );
		$this->assertSame( 0, Sync_Config::get( $post_id )['failures'] );
		$this->assertSame( 1, Scheduler::count_events( $post_id ) );
	}

	public function test_sync_of_a_busy_table_is_not_recorded() {
		list( $table_id, $post_id ) = $this->configured_table( $this->fixture( 's.csv', self::V1 ) );
		$lock                       = Table_Lock::acquire( $table_id );
		$this->assertInstanceOf( Table_Lock::class, $lock );
		try {
			$this->assertSame( 'busy', ( new Sync_Runner() )->run( $post_id, 'manual' )['outcome'] );
		} finally {
			$lock->release();
		}
		$this->assertSame( array(), Sync_Log::get( $post_id )['entries'] );
		$this->assertSame( 0, Sync_Config::get( $post_id )['failures'] );
	}

	public function test_history_is_bounded() {
		list( , $post_id ) = $this->configured_table( $this->fixture( 's.csv', self::V1 ) );
		for ( $i = 0; $i < Sync_Log::MAX_ENTRIES + 3; $i++ ) {
			( new Sync_Runner() )->run( $post_id, 'manual' );
		}
		$log = Sync_Log::get( $post_id );
		$this->assertCount( Sync_Log::MAX_ENTRIES, $log['entries'] );
		$this->assertSame( 'unchanged', $log['entries'][0]['outcome'] );
		$this->assertSame( 'unchanged', $log['entries'][ Sync_Log::MAX_ENTRIES - 1 ]['outcome'], 'The oldest entries were dropped.' );
	}

	public function test_deleting_the_table_removes_its_configuration_and_event() {
		list( $table_id, $post_id ) = $this->configured_table( $this->fixture( 's.csv', self::V1 ) );
		$this->assertTrue( TablePress::$model_table->delete( $table_id ) );
		$this->assertSame( 0, Scheduler::count_events( $post_id ) );
		$this->assertNull( Sync_Config::get( $post_id ) );
		$this->assertSame( 'missing', ( new Sync_Runner() )->run( $post_id, 'scheduled' )['outcome'] );
	}

	public function test_configuration_follows_a_changed_table_id() {
		list( $table_id, $post_id ) = $this->configured_table( $this->fixture( 's.csv', self::V1 ) );
		$new_id                     = 'tr-' . wp_generate_password( 6, false, false );
		wp_set_current_user( 1 );
		$this->assertTrue( TablePress::$model_table->change_table_id( $table_id, $new_id ) );
		wp_set_current_user( 0 );
		try {
			$this->assertSame( $new_id, TablePress_Adapter::get_table_id( $post_id ) );
			$this->run_scheduled( $post_id );
			$this->assertSame( 'updated', Sync_Log::last( $post_id )['outcome'] );
			$this->assertSame( 'Pear', $this->load_table( $new_id )['data'][2][0] );
			$this->assertSame( 1, Scheduler::count_events( $post_id ) );
		} finally {
			TablePress::$model_table->delete( $new_id );
		}
	}

	public function test_deactivation_keeps_configurations_and_activation_restores_events() {
		list( , $post_id ) = $this->configured_table( $this->fixture( 's.csv', self::V1 ), 'hourly' );
		Plugin::deactivate();
		$this->assertSame( 0, Scheduler::count_events( $post_id ) );
		$this->assertNotNull( Sync_Config::get( $post_id ) );
		Plugin::activate();
		$this->assertSame( 1, Scheduler::count_events( $post_id ) );
		$this->assertSame( 'hourly', wp_get_scheduled_event( Scheduler::HOOK, array( $post_id ) )->schedule );
	}

	public function test_due_event_runs_through_wp_cron() {
		list( $table_id, $post_id ) = $this->configured_table( $this->fixture( 's.csv', self::V1 ), 'hourly' );
		wp_clear_scheduled_hook( Scheduler::HOOK, array( $post_id ) );
		wp_schedule_event( time() - 60, 'hourly', Scheduler::HOOK, array( $post_id ) ); // Overdue.

		$output = shell_exec( 'wp --path=' . escapeshellarg( TABLEREFRESH_TESTS_WP_DIR ) . ' cron event run ' . Scheduler::HOOK . ' 2>&1' );
		$this->assertStringContainsString( 'Executed', (string) $output );
		wp_cache_flush_runtime(); // The other process changed the cron option and post meta.
		$this->assertSame( 'Pear',$this->load_table( $table_id )['data'][2][0] );
		$this->assertSame( 'scheduled', Sync_Log::last( $post_id )['trigger'] );
		$this->assertSame( 1, Scheduler::count_events( $post_id ), 'WP-Cron rescheduled the event once.' );
		$this->assertGreaterThan( time(), Scheduler::next_run( $post_id ) );
	}

	/**
	 * Creates tables with overdue hourly events, as after a quiet night on a low-traffic site.
	 *
	 * @param int $count Number of tables.
	 * @return array<int, array{0: string, 1: int, 2: int}> Table ID, post ID, revisions before.
	 */
	private function overdue_tables( int $count ): array {
		$this->run_wp_cron_php(); // WordPress's own due events first: they must not use up a web request's time budget.
		$url    = $this->fixture( 'many.csv', self::V1 );
		$tables = array();
		for ( $i = 0; $i < $count; $i++ ) {
			list( $table_id, $post_id ) = $this->configured_table( $url, 'hourly' );
			wp_clear_scheduled_hook( Scheduler::HOOK, array( $post_id ) );
			wp_schedule_event( time() - 120 + $i, 'hourly', Scheduler::HOOK, array( $post_id ) );
			$tables[] = array( $table_id, $post_id, $this->revision_count( $table_id ) );
		}
		return $tables;
	}

	private function assertEachSyncedOnce( array $tables ): void {
		wp_cache_flush_runtime();
		foreach ( $tables as list( $table_id, $post_id, $revisions ) ) {
			$this->assertSame( 'Pear', $this->load_table( $table_id )['data'][2][0], "Table {$table_id}" );
			$this->assertSame( $revisions + 1, $this->revision_count( $table_id ), "Table {$table_id} was written once." );
			$this->assertSame( 1, Scheduler::count_events( $post_id ), "Table {$table_id} has one event." );
			$this->assertGreaterThan( time(), Scheduler::next_run( $post_id ) );
			$this->assertContains( Sync_Log::last( $post_id )['outcome'], array( 'updated', 'unchanged' ) );
			$this->assertFalse( Table_Lock::is_locked( $table_id ) );
			$this->assertNull( \ArsenManukyan\TableRefresh\Journal::read( $table_id ) );
		}
	}

	public function test_many_due_tables_with_overlapping_cron_runners() {
		$tables = $this->overdue_tables( 25 );
		$start  = microtime( true );
		// Two runners at once (WP-CLI ignores WP-Cron's own lock): the worst case of overlapping cron requests.
		$command = array( 'wp', '--path=' . TABLEREFRESH_TESTS_WP_DIR, 'cron', 'event', 'run', '--due-now' );
		$runners = array();
		for ( $i = 0; $i < 2; $i++ ) {
			$runners[] = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes[ $i ] );
		}
		foreach ( $runners as $i => $runner ) {
			$output = stream_get_contents( $pipes[ $i ][1] ) . stream_get_contents( $pipes[ $i ][2] );
			proc_close( $runner );
			$this->assertStringNotContainsString( 'Fatal', $output );
		}
		fwrite( STDERR, sprintf( "\n[25 tables, 2 cron runners: %.1f s]\n", microtime( true ) - $start ) );
		$this->assertEachSyncedOnce( $tables );
	}

	/**
	 * The runner that finds the table locked by another runner still leaves exactly one event.
	 *
	 * Two runners that reschedule the same event at the same moment write the same cron array. The
	 * second write changes no row, so update_option() keeps that runner's older cached copy, and its
	 * next write (removing the old event) drops the table's event, or keeps the old one as well.
	 * Every other outcome of a run repairs the event; "busy" must too.
	 */
	public function test_runner_that_finds_the_table_busy_keeps_exactly_one_event() {
		list( $table_id, $post_id ) = $this->configured_table( $this->fixture( 's.csv', self::V1 ), 'hourly' );
		$revisions                  = $this->revision_count( $table_id );
		$log                        = Sync_Log::get( $post_id );
		$lock                       = Table_Lock::acquire( $table_id ); // The other runner is syncing.
		$this->assertInstanceOf( Table_Lock::class, $lock );
		try {
			foreach ( array( 'event lost' => 0, 'old event kept' => 2 ) as $case => $events ) {
				wp_clear_scheduled_hook( Scheduler::HOOK, array( $post_id ) );
				for ( $i = 0; $i < $events; $i++ ) {
					wp_schedule_event( time() + 60 + $i * 600, 'hourly', Scheduler::HOOK, array( $post_id ) );
				}
				$this->assertSame( $events, Scheduler::count_events( $post_id ) );
				$this->run_scheduled( $post_id );
				$this->assertSame( 1, Scheduler::count_events( $post_id ), $case );
				$this->assertGreaterThan( time(), Scheduler::next_run( $post_id ), $case );
			}
		} finally {
			$lock->release();
		}
		$this->assertSame( $revisions, $this->revision_count( $table_id ), 'No sync ran.' );
		$this->assertSame( $log, Sync_Log::get( $post_id ), 'A busy run is not recorded.' );
	}

	public function test_busy_runner_schedules_nothing_for_disabled_paused_or_removed_tables() {
		$url = $this->fixture( 's.csv', self::V1 );
		foreach ( array( 'disabled', 'paused', 'removed' ) as $case ) {
			list( $table_id, $post_id ) = $this->configured_table( $url, 'hourly' );
			$config                     = Sync_Config::get( $post_id );
			if ( 'disabled' === $case ) {
				Sync_Config::save( $post_id, array( 'enabled' => false ) + $config );
			} elseif ( 'paused' === $case ) {
				Sync_Config::save( $post_id, array( 'attention' => Sync_Config::ATTENTION_CONFLICT ) + $config );
			} else {
				Sync_Config::delete( $post_id );
			}
			$this->assertSame( 1, Scheduler::count_events( $post_id ), "{$case}: the stale event is still there" );
			$lock = Table_Lock::acquire( $table_id );
			$this->run_scheduled( $post_id );
			$lock->release();
			$this->assertSame( 0, Scheduler::count_events( $post_id ), $case );
		}
	}

	public function test_many_due_tables_through_wp_cron_php() {
		$tables = $this->overdue_tables( 10 );
		delete_transient( 'doing_cron' ); // Earlier page loads may have left WP-Cron's lock of a spawn that could not connect.
		$this->http( '/wp-cron.php' ); // As a server cron job calls it.
		$this->assertEachSyncedOnce( $tables );
	}

	/**
	 * Starts core's wp-cron.php in a separate process, as a server cron job or WP-Cron's own spawn runs it.
	 *
	 * Clears WP-Cron's lock first: this is the next request after the previous one ended.
	 *
	 * @param array<string, string> $env Extra environment variables.
	 * @return resource
	 */
	private function start_wp_cron_php( array $env = array() ) {
		delete_transient( 'doing_cron' );
		$process = proc_open(
			array( 'php', TABLEREFRESH_TESTS_WP_DIR . '/wp-cron.php' ),
			array(
				1 => array( 'file', '/dev/null', 'w' ),
				2 => array( 'file', '/dev/null', 'w' ),
			),
			$pipes,
			TABLEREFRESH_TESTS_WP_DIR,
			array_merge( getenv(), $env )
		);
		$this->assertIsResource( $process );
		return $process;
	}

	/**
	 * Waits for a wp-cron.php process; kills it (SIGKILL) after a wall-clock limit, as some hosts do.
	 *
	 * @param resource $process    Process from start_wp_cron_php().
	 * @param float    $kill_after Seconds after which the process is killed (0: 120, as a safety net).
	 * @return bool Whether the process was killed.
	 */
	private function wait_wp_cron_php( $process, float $kill_after = 0, bool $flush = true ): bool {
		$deadline = microtime( true ) + ( $kill_after > 0 ? $kill_after : 120 );
		$killed   = false;
		while ( proc_get_status( $process )['running'] ) {
			if ( microtime( true ) >= $deadline ) {
				proc_terminate( $process, 9 );
				$killed = true;
				break;
			}
			usleep( 20000 );
		}
		proc_close( $process );
		if ( $flush ) {
			wp_cache_flush_runtime(); // The other process changed the cron option and post meta.
		}
		return $killed;
	}

	/**
	 * Runs one wp-cron.php request.
	 *
	 * @param array<string, string> $env        Extra environment variables.
	 * @param float                 $kill_after See wait_wp_cron_php().
	 * @return bool Whether the process was killed.
	 */
	private function run_wp_cron_php( array $env = array(), float $kill_after = 0 ): bool {
		return $this->wait_wp_cron_php( $this->start_wp_cron_php( $env ), $kill_after );
	}

	/**
	 * Creates configured hourly tables whose events are due, in the given order (less than one interval overdue).
	 *
	 * Runs the events that are already due first (WordPress's own, for example update checks, which
	 * contact wordpress.org), so that they cannot take time from the requests under test.
	 *
	 * @param string[] $urls Source URL of each table, in queue order.
	 * @return array<int, array{0: string, 1: int, 2: int}> Table ID, post ID, time at which the event is due.
	 */
	private function due_tables( array $urls ): array {
		$this->run_wp_cron_php();
		$tables = array();
		foreach ( array_values( $urls ) as $i => $url ) {
			list( $table_id, $post_id ) = $this->configured_table( $url, 'hourly' );
			$due                        = time() - 600 + $i;
			wp_clear_scheduled_hook( Scheduler::HOOK, array( $post_id ) );
			wp_schedule_event( $due, 'hourly', Scheduler::HOOK, array( $post_id ) );
			$tables[] = array( $table_id, $post_id, $due );
		}
		return $tables;
	}

	/**
	 * Number of recorded syncs of each table.
	 *
	 * @param array<int, array{0: string, 1: int, 2: int}> $tables Tables from due_tables().
	 * @return int[]
	 */
	private function sync_counts( array $tables ): array {
		$counts = array();
		foreach ( $tables as list( , $post_id ) ) {
			$counts[] = count( Sync_Log::get( $post_id )['entries'] );
		}
		return $counts;
	}

	/**
	 * Whether any of the tables still has a due event.
	 *
	 * @param array<int, array{0: string, 1: int, 2: int}> $tables Tables from due_tables().
	 * @return bool
	 */
	private function any_due( array $tables ): bool {
		foreach ( $tables as list( , $post_id ) ) {
			$next = Scheduler::next_run( $post_id );
			if ( null !== $next && $next <= time() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lets one interval pass for the tables: their events become due again, at the same regular times.
	 *
	 * @param array<int, array{0: string, 1: int, 2: int}> $tables Tables from due_tables().
	 * @return array<int, array{0: string, 1: int, 2: int}> The tables with their new due times.
	 */
	private function next_interval( array $tables ): array {
		foreach ( $tables as $i => list( $table_id, $post_id ) ) {
			$event = wp_get_scheduled_event( Scheduler::HOOK, array( $post_id ) );
			$this->assertNotFalse( $event, "Table {$table_id} has an event." );
			wp_clear_scheduled_hook( Scheduler::HOOK, array( $post_id ) );
			wp_schedule_event( $event->timestamp - HOUR_IN_SECONDS, 'hourly', Scheduler::HOOK, array( $post_id ) );
			$tables[ $i ][2] = $event->timestamp - HOUR_IN_SECONDS;
			$turn            = (int) get_post_meta( $post_id, Scheduler::TURN_META_KEY, true ); // A due time as well.
			if ( $turn > 0 ) {
				update_post_meta( $post_id, Scheduler::TURN_META_KEY, $turn - HOUR_IN_SECONDS );
			}
		}
		return $tables;
	}

	/**
	 * A request that has used up its time budget starts no further sync. The next requests take the
	 * remaining tables in their order; each request completes at least one sync, and every table keeps
	 * its regular times.
	 */
	public function test_cron_request_hands_remaining_syncs_to_the_next_request_in_order() {
		$tables = $this->due_tables( array_fill( 0, 4, $this->fixture( 's.csv', self::V1 ) ) );
		$env    = array( 'TABLEREFRESH_TEST_CRON_BUDGET' => '0.001' ); // Used up before the first sync, which still runs.

		foreach ( array( array( 1, 0, 0, 0 ), array( 1, 1, 0, 0 ), array( 1, 1, 1, 0 ), array( 1, 1, 1, 1 ) ) as $request => $expected ) {
			$this->assertFalse( $this->run_wp_cron_php( $env ) );
			$this->assertSame( $expected, $this->sync_counts( $tables ), "Request {$request}: the first due sync runs, the others wait." );
			foreach ( $tables as $i => list( $table_id, $post_id, $due ) ) {
				$this->assertSame( 1, Scheduler::count_events( $post_id ), "Table {$i} has one event." );
				$this->assertSame( $expected[ $i ] ? $due + HOUR_IN_SECONDS : $due, Scheduler::next_run( $post_id ), "Table {$i} keeps its place and its regular time." );
			}
		}
		foreach ( $tables as list( $table_id, $post_id ) ) {
			$this->assertSame( 'Pear', $this->load_table( $table_id )['data'][2][0] );
			$this->assertSame( 'updated', Sync_Log::last( $post_id )['outcome'] );
			$this->assertFalse( Table_Lock::is_locked( $table_id ) );
		}
	}

	/**
	 * Regression: a host that stops long requests cut off the table in progress, and because the order
	 * of the events stays the same, the same table in every interval. That table was never updated,
	 * and nothing was recorded. With the time budget, the request ends before the host's limit and the
	 * remaining tables run with the next request.
	 */
	public function test_slow_sources_ahead_do_not_get_a_table_cut_off_in_every_interval() {
		$slow   = $this->fixture( 'slow.csv', self::V1, 'delay=5' );
		$fast   = $this->fixture( 'fast.csv', self::V1 );
		$tables = $this->due_tables( array( $slow, $slow, $slow, $fast, $fast ) );
		$env    = array( 'TABLEREFRESH_TEST_CRON_BUDGET' => (string) Scheduler::TIME_BUDGET ); // The web default.

		for ( $interval = 1; $interval <= 2; $interval++ ) {
			for ( $request = 1; $request <= 5 && $this->any_due( $tables ); $request++ ) {
				// Without the budget, the request runs into the limit during the third slow sync.
				$this->assertFalse( $this->run_wp_cron_php( $env, 13 ), "Interval {$interval}, request {$request} ended before the host's 13-second limit." );
			}
			$this->assertSame( array_fill( 0, 5, $interval ), $this->sync_counts( $tables ), "Interval {$interval}: every table was synchronized once." );
			foreach ( $tables as list( $table_id, $post_id ) ) {
				$this->assertSame( 1, Scheduler::count_events( $post_id ) );
				$this->assertGreaterThan( time(), Scheduler::next_run( $post_id ) );
				$this->assertFalse( Table_Lock::is_locked( $table_id ) );
				$this->assertNull( \ArsenManukyan\TableRefresh\Journal::read( $table_id ) );
			}
			$tables = $this->next_interval( $tables );
		}
	}

	public function test_web_cron_request_has_a_time_budget_by_default() {
		$slow   = $this->fixture( 'slow.csv', self::V1, 'delay=6' );
		$tables = $this->due_tables( array( $slow, $slow, $this->fixture( 'fast.csv', self::V1 ) ) );

		delete_transient( 'doing_cron' );
		$this->http( '/wp-cron.php' ); // Served by Apache, as WP-Cron's own spawn or a server cron job with curl.
		wp_cache_flush_runtime();
		$this->assertSame( array( 1, 1, 0 ), $this->sync_counts( $tables ), 'The second sync started before 10 seconds, the third after.' );
		$this->assertSame( $tables[2][2], Scheduler::next_run( $tables[2][1] ), 'The third table is still due.' );

		delete_transient( 'doing_cron' );
		$this->http( '/wp-cron.php' );
		wp_cache_flush_runtime();
		$this->assertSame( array( 1, 1, 1 ), $this->sync_counts( $tables ) );
		$this->assertSame( $tables[2][2] + HOUR_IN_SECONDS, Scheduler::next_run( $tables[2][1] ) );
	}

	public function test_command_line_cron_has_no_time_budget_by_default() {
		$slow   = $this->fixture( 'slow.csv', self::V1, 'delay=6' );
		$tables = $this->due_tables( array( $slow, $slow, $this->fixture( 'fast.csv', self::V1 ) ) );
		$this->assertFalse( $this->run_wp_cron_php() );
		$this->assertSame( array( 1, 1, 1 ), $this->sync_counts( $tables ), 'PHP on the command line has no time limit; one request syncs all.' );
	}

	public function test_time_budget_does_not_apply_outside_scheduled_task_requests() {
		$url = $this->fixture( 's.csv', self::V1 );
		list( , $first )  = $this->configured_table( $url );
		list( , $second ) = $this->configured_table( $url );
		$budget           = static function () {
			return 0.001;
		};
		add_filter( 'tablerefresh_cron_time_budget', $budget );
		try {
			$this->run_scheduled( $first );
			$this->run_scheduled( $second );
		} finally {
			remove_filter( 'tablerefresh_cron_time_budget', $budget );
		}
		$this->assertSame( 'updated', Sync_Log::last( $first )['outcome'] );
		$this->assertSame( 'updated', Sync_Log::last( $second )['outcome'] );
	}

	public function test_deferral_removes_events_of_tables_that_are_no_longer_active() {
		$url    = $this->fixture( 's.csv', self::V1 );
		$tables = $this->due_tables( array( $url, $url, $url, $url ) );
		list( , $disabled ) = $tables[1];
		list( , $paused )   = $tables[2];
		list( , $removed )  = $tables[3];
		Sync_Config::save( $disabled, array( 'enabled' => false ) + Sync_Config::get( $disabled ) );
		Sync_Config::save( $paused, array( 'attention' => Sync_Config::ATTENTION_CONFLICT ) + Sync_Config::get( $paused ) );
		Sync_Config::delete( $removed );

		$this->assertFalse( $this->run_wp_cron_php( array( 'TABLEREFRESH_TEST_CRON_BUDGET' => '0.001' ) ) );
		$this->assertSame( array( 1, 0, 0, 0 ), $this->sync_counts( $tables ) );
		$this->assertSame( 1, Scheduler::count_events( $tables[0][1] ) );
		foreach ( array( 'disabled' => $disabled, 'paused' => $paused, 'removed' => $removed ) as $case => $post_id ) {
			$this->assertSame( 0, Scheduler::count_events( $post_id ), $case );
		}
	}

	/**
	 * Overlapping requests that both defer tables still sync each table once and keep exactly one event.
	 */
	public function test_overlapping_cron_requests_with_time_budget_sync_each_table_once() {
		$tables    = $this->due_tables( array_fill( 0, 4, $this->fixture( 'slow.csv', self::V1, 'delay=1' ) ) );
		$revisions = array_map(
			function ( $table ) {
				return $this->revision_count( $table[0] );
			},
			$tables
		);
		$env       = array(
			'TABLEREFRESH_TEST_CRON_BUDGET' => '0.5',
			'TABLEREFRESH_TEST_BARRIER'     => uniqid( 'cronbudget' ) . ':2', // Both pass WP-Cron's lock check together.
		);
		$first     = $this->start_wp_cron_php( $env );
		$second    = $this->start_wp_cron_php( $env );
		$this->assertFalse( $this->wait_wp_cron_php( $first ) );
		$this->assertFalse( $this->wait_wp_cron_php( $second ) );

		unset( $env['TABLEREFRESH_TEST_BARRIER'] );
		for ( $request = 0; $request < 8 && $this->any_due( $tables ); $request++ ) {
			$this->run_wp_cron_php( $env );
		}
		$this->assertSame( array( 1, 1, 1, 1 ), $this->sync_counts( $tables ) );
		foreach ( $tables as $i => list( $table_id, $post_id ) ) {
			$this->assertSame( $revisions[ $i ] + 1, $this->revision_count( $table_id ), "Table {$i} was written once." );
			$this->assertSame( 1, Scheduler::count_events( $post_id ), "Table {$i} has one event." );
			$this->assertGreaterThan( time(), Scheduler::next_run( $post_id ) );
		}
	}

	/**
	 * Offsets within the hour of the 24 tables of the overload reproduction (seconds).
	 */
	const OVERLOAD_PHASES = array( 2676, 3300, 1260, 2772, 2260, 1216, 2736, 3456, 1272, 3556, 1276, 3480, 2188, 1344, 720, 2020, 2916, 980, 3432, 2244, 820, 588, 304, 2472 );

	/**
	 * Real time of the last advance_clock() call.
	 *
	 * @var int
	 */
	private int $clock = 0;

	/**
	 * Seconds by which advance_clock() has moved the events so far.
	 *
	 * @var int
	 */
	private int $advanced = 0;

	/**
	 * Creates configured hourly tables with events at the given offsets from now (negative: due).
	 *
	 * @param int[]  $offsets Seconds from now, in table order.
	 * @param string $url     Source URL.
	 * @return array<int, array{0: string, 1: int, 2: int}> Table ID, post ID, time of the event.
	 */
	private function tables_at( array $offsets, string $url ): array {
		$this->run_wp_cron_php(); // WordPress's own due events first (see due_tables()).
		$now    = time();
		$tables = array();
		foreach ( $offsets as $offset ) {
			list( $table_id, $post_id ) = $this->configured_table( $url, 'hourly' );
			wp_clear_scheduled_hook( Scheduler::HOOK, array( $post_id ) );
			wp_schedule_event( $now + $offset, 'hourly', Scheduler::HOOK, array( $post_id ) );
			$tables[] = array( $table_id, $post_id, $now + $offset );
		}
		$this->clock    = time();
		$this->advanced = 0;
		return $tables;
	}

	/**
	 * Lets simulated time pass: moves every event of the tables earlier, so that exactly $seconds
	 * have passed for them since the last call (real seconds that passed meanwhile count). Asserts
	 * on the way that each table has exactly one event.
	 *
	 * @param array<int, array{0: string, 1: int, 2: int}> $tables  Tables.
	 * @param int                                          $seconds Simulated seconds.
	 */
	private function advance_clock( array $tables, int $seconds ): void {
		global $wpdb;
		$now      = time();
		$shift    = $seconds - ( $now - $this->clock );
		$post_ids = array_column( $tables, 1 );
		foreach ( $post_ids as $i => $post_id ) {
			$this->assertSame( 1, Scheduler::count_events( $post_id ), "Table {$i} has exactly one event." );
		}
		// One write of WP-Cron's list (moving the events one by one would take two writes each).
		$crons = _get_cron_array();
		$moved = array();
		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( isset( $hooks[ Scheduler::HOOK ] ) ? $hooks[ Scheduler::HOOK ] : array() as $key => $event ) {
				if ( in_array( (int) $event['args'][0], $post_ids, true ) ) {
					$moved[] = array( $timestamp - $shift, $key, $event );
					unset( $crons[ $timestamp ][ Scheduler::HOOK ][ $key ] );
				}
			}
			if ( isset( $crons[ $timestamp ][ Scheduler::HOOK ] ) && ! $crons[ $timestamp ][ Scheduler::HOOK ] ) {
				unset( $crons[ $timestamp ][ Scheduler::HOOK ] );
			}
			if ( ! $crons[ $timestamp ] ) {
				unset( $crons[ $timestamp ] );
			}
		}
		foreach ( $moved as list( $timestamp, $key, $event ) ) {
			$crons[ $timestamp ][ Scheduler::HOOK ][ $key ] = $event;
		}
		uksort( $crons, 'strnatcasecmp' );
		_set_cron_array( $crons );
		// The turn markers are due times as well.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) - %d WHERE meta_key = %s AND post_id IN (" . implode( ',', array_map( 'intval', $post_ids ) ) . ')', $shift, Scheduler::TURN_META_KEY ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( $post_ids as $post_id ) {
			wp_cache_delete( $post_id, 'post_meta' );
		}
		$this->clock     = $now;
		$this->advanced += $shift;
	}

	/**
	 * Asserts that each table has one event, in the future, at one of its regular times.
	 *
	 * @param array<int, array{0: string, 1: int, 2: int}> $tables Tables (with their first event time).
	 */
	private function assertRegularTimesKept( array $tables ): void {
		foreach ( $tables as $i => list( , $post_id, $first ) ) {
			$this->assertSame( 1, Scheduler::count_events( $post_id ), "Table {$i} has exactly one event." );
			$next = Scheduler::next_run( $post_id );
			$this->assertSame( 0, ( $next + $this->advanced - $first ) % HOUR_IN_SECONDS, "Table {$i} keeps its regular times." );
		}
	}

	/**
	 * Runs the due TableRefresh events in this process the way wp-cron.php does (move each event
	 * to its next time, remove the old one, then run it), as one scheduled-task request.
	 *
	 * @param float $budget Time budget of the request (the elapsed time of this process is far beyond it).
	 */
	private function run_cron_in_process( float $budget ): void {
		Closure::bind(
			static function () {
				Scheduler::$started   = 0;
				Scheduler::$due_times = array();
			},
			null,
			Scheduler::class
		)();
		$budget_filter = static function () use ( $budget ) {
			return $budget;
		};
		add_filter( 'wp_doing_cron', '__return_true' );
		add_filter( 'tablerefresh_cron_time_budget', $budget_filter );
		try {
			foreach ( wp_get_ready_cron_jobs() as $timestamp => $hooks ) {
				foreach ( $hooks as $hook => $events ) {
					if ( Scheduler::HOOK !== $hook ) {
						continue;
					}
					foreach ( $events as $event ) {
						wp_reschedule_event( $timestamp, $event['schedule'], $hook, $event['args'] );
						wp_unschedule_event( $timestamp, $hook, $event['args'] );
						do_action_ref_array( $hook, $event['args'] );
					}
				}
			}
		} finally {
			remove_filter( 'wp_doing_cron', '__return_true' );
			remove_filter( 'tablerefresh_cron_time_budget', $budget_filter );
		}
	}

	/**
	 * Scheduled syncs of each table recorded at or after a time.
	 *
	 * @param array<int, array{0: string, 1: int, 2: int}> $tables Tables.
	 * @param int                                          $since Unix time.
	 * @return int[]
	 */
	private function syncs_since( array $tables, int $since ): array {
		$counts = array();
		foreach ( $tables as list( , $post_id ) ) {
			$counts[] = count(
				array_filter(
					Sync_Log::get( $post_id )['entries'],
					static function ( $entry ) use ( $since ) {
						return $entry['time'] >= $since && 'scheduled' === $entry['trigger'];
					}
				)
			);
		}
		return $counts;
	}

	/**
	 * Regression (D1): 24 hourly tables, room for one sync per request, a request every 7.5 minutes
	 * (8 syncs per hour for 24 due per hour). When a deferred table went back to its latest regular
	 * time, 10 of the 24 tables were never synchronized again; with the time at which it became due,
	 * every table gets its share (2 or 3 in 8 hours).
	 *
	 * Runs the requests in this process (as wp-cron.php does, see run_cron_in_process()) to keep the
	 * 128 requests fast; test_minimal_overload_through_wp_cron_php...() uses wp-cron.php itself.
	 */
	public function test_sustained_overload_gives_every_table_its_turn() {
		$tables = $this->tables_at(
			array_map(
				static function ( $phase ) {
					return $phase - 450; // The first request is at simulated second 450.
				},
				self::OVERLOAD_PHASES
			),
			$this->fixture( 's.csv', self::V1 )
		);
		$index  = array();
		foreach ( $tables as $i => list( $table_id ) ) {
			$index[ $table_id ] = $i;
		}
		$counts   = array_fill( 0, count( $tables ), 0 );
		$counting = false;
		$listener = static function ( $table_id ) use ( &$counts, &$counting, $index ) {
			if ( $counting && isset( $index[ $table_id ] ) ) {
				++$counts[ $index[ $table_id ] ];
			}
		};
		add_action( 'tablerefresh_sync_completed', $listener );
		try {
			for ( $request = 1; $request <= 128; $request++ ) { // 16 simulated hours.
				$counting = $request > 64; // The last 8 hours.
				$this->run_cron_in_process( 0.001 );
				$this->advance_clock( $tables, 450 );
			}
		} finally {
			remove_action( 'tablerefresh_sync_completed', $listener );
		}
		$this->assertSame( 64, array_sum( $counts ), 'Every request synchronized one table.' );
		$this->assertGreaterThanOrEqual( 2, min( $counts ), 'Every table got its turn: ' . implode( ' ', $counts ) );
		$this->assertLessThanOrEqual( 3, max( $counts ), 'No table got more than its share: ' . implode( ' ', $counts ) );
		$this->assertRegularTimesKept( $tables );
	}

	/**
	 * Regression (D1), smallest case, through wp-cron.php: 4 hourly tables, one sync per request, a
	 * request every 30 minutes. With the latest regular time, the first table was never synchronized.
	 */
	public function test_minimal_overload_through_wp_cron_php_gives_every_table_its_turn() {
		$tables = $this->tables_at( array( 1615 - 1800, 1072 - 1800, 136 - 1800, 1052 - 1800 ), $this->fixture( 's.csv', self::V1 ) );
		$env    = array( 'TABLEREFRESH_TEST_CRON_BUDGET' => '0.001' );
		$since  = PHP_INT_MAX;
		for ( $request = 1; $request <= 48; $request++ ) { // 24 simulated hours.
			if ( 25 === $request ) { // The second 12 hours start in a new second: history times are whole seconds.
				$since = time() + 1;
				while ( time() < $since ) {
					usleep( 50000 );
				}
			}
			$this->assertFalse( $this->run_wp_cron_php( $env ) );
			$this->advance_clock( $tables, 1800 );
		}
		$counts = $this->syncs_since( $tables, $since );
		$this->assertSame( 24, array_sum( $counts ) );
		$this->assertGreaterThanOrEqual( 5, min( $counts ), 'Every table got its turn: ' . implode( ' ', $counts ) );
		$this->assertRegularTimesKept( $tables );
	}

	/**
	 * A table deferred in several intervals keeps the time at which it first became due, so it runs
	 * before a table that became due later, even one that was synchronized in between.
	 */
	public function test_repeatedly_deferred_table_keeps_its_original_due_time() {
		$tables = $this->tables_at( array( -600, -599, -598 ), $this->fixture( 's.csv', self::V1 ) );
		$env    = array( 'TABLEREFRESH_TEST_CRON_BUDGET' => '0.001' );
		$due    = array_column( $tables, 2 );
		$event  = static function ( $i ) use ( $tables ) {
			return Scheduler::next_run( $tables[ $i ][1] );
		};

		$this->run_wp_cron_php( $env );
		$this->assertSame( array( 1, 0, 0 ), $this->sync_counts( $tables ) );
		$this->assertSame( array( $due[1], $due[2] ), array( $event( 1 ), $event( 2 ) ), 'Deferred: back at the time they became due.' );

		$this->advance_clock( $tables, HOUR_IN_SECONDS );
		$this->run_wp_cron_php( $env );
		$this->assertSame( array( 1, 1, 0 ), $this->sync_counts( $tables ), 'The table deferred longest of the due ones ran.' );
		$this->assertSame( $due[2] - $this->advanced, $event( 2 ), 'Deferred again: still at the time it first became due.' );
		$this->assertSame( $due[0] + HOUR_IN_SECONDS - $this->advanced, $event( 0 ), 'Table 0: back at its regular time of this hour.' );

		$this->advance_clock( $tables, HOUR_IN_SECONDS );
		$this->run_wp_cron_php( $env );
		$this->assertSame( array( 1, 1, 1 ), $this->sync_counts( $tables ), 'Table 2, due two hours ago, runs before table 0, due one hour ago.' );
		$this->assertRegularTimesKept( $tables );
	}

	/**
	 * When more syncs are due than one interval can run, the backlog runs first, in the order in
	 * which the tables became due; once there is room again, every table runs at its regular times.
	 */
	public function test_backlog_runs_before_newer_syncs_and_regular_times_resume() {
		$tables = $this->tables_at( array( -600, -599, -598, -597, -596, -595 ), $this->fixture( 's.csv', self::V1 ) );
		$env    = array( 'TABLEREFRESH_TEST_CRON_BUDGET' => '0.001' );
		for ( $request = 0; $request < 3; $request++ ) {
			$this->run_wp_cron_php( $env );
		}
		$this->assertSame( array( 1, 1, 1, 0, 0, 0 ), $this->sync_counts( $tables ), 'Room for three syncs this hour.' );

		$this->advance_clock( $tables, HOUR_IN_SECONDS );
		$order = array();
		for ( $request = 0; $request < 6; $request++ ) {
			$before = $this->sync_counts( $tables );
			$this->run_wp_cron_php( $env );
			$after = $this->sync_counts( $tables );
			$ran   = array_keys( array_diff_assoc( $after, $before ) );
			$this->assertCount( 1, $ran, 'One sync per request.' );
			$order[] = $ran[0];
		}
		$this->assertSame( array( 3, 4, 5, 0, 1, 2 ), $order, 'The tables that waited since the previous hour first.' );

		$this->advance_clock( $tables, HOUR_IN_SECONDS );
		$this->assertFalse( $this->run_wp_cron_php() ); // Enough room again (command line: no budget).
		$this->assertSame( array( 3, 3, 3, 2, 2, 2 ), $this->sync_counts( $tables ), 'Every table once more, at its regular time.' );
		$this->assertRegularTimesKept( $tables );
		foreach ( $tables as list( , $post_id ) ) {
			$this->assertGreaterThan( time(), Scheduler::next_run( $post_id ) );
		}
	}

	public function test_frequency_change_while_a_table_is_deferred() {
		$tables = $this->tables_at( array( -600, -599, -598 ), $this->fixture( 's.csv', self::V1 ) );
		$env    = array( 'TABLEREFRESH_TEST_CRON_BUDGET' => '0.001' );
		$this->run_wp_cron_php( $env );
		list( , $saved )   = $tables[1];
		list( , $changed ) = $tables[2];
		$this->assertLessThanOrEqual( time(), Scheduler::next_run( $saved ), 'Deferred.' );

		// Saved in the admin screen: the schedule restarts with the new frequency.
		Sync_Config::save( $saved, array( 'frequency' => 'daily' ) + Sync_Config::get( $saved ) );
		Scheduler::apply( $saved, Sync_Config::get( $saved ), true );
		// Changed by another process after the cron request loaded the event (the event is still hourly).
		Sync_Config::save( $changed, array( 'frequency' => 'daily' ) + Sync_Config::get( $changed ) );

		$this->advance_clock( array( $tables[0] ), HOUR_IN_SECONDS + 100 ); // Table 0 is due again, before table 2.
		$this->run_wp_cron_php( $env );
		$this->assertSame( array( 2, 0, 0 ), $this->sync_counts( $tables ) );
		$this->assertSame( 1, Scheduler::count_events( $saved ) );
		$this->assertSame( 'daily', wp_get_scheduled_event( Scheduler::HOOK, array( $saved ) )->schedule );
		$this->assertGreaterThan( time() + DAY_IN_SECONDS - 120, Scheduler::next_run( $saved ), 'Not due before its new interval.' );
		$this->assertSame( 1, Scheduler::count_events( $changed ) );
		$this->assertSame( 'daily', wp_get_scheduled_event( Scheduler::HOOK, array( $changed ) )->schedule, 'Deferred with the configured frequency.' );
		$this->assertLessThanOrEqual( time(), Scheduler::next_run( $changed ), 'Still due.' );

		$this->run_wp_cron_php( $env );
		$this->assertSame( array( 2, 0, 1 ), $this->sync_counts( $tables ) );
		$this->assertGreaterThan( time() + DAY_IN_SECONDS - 120, Scheduler::next_run( $changed ) );
	}

	/**
	 * Overlapping requests over several intervals: every table once per interval, one event each.
	 *
	 * (The runner that finds a table busy may restart that table's interval when its copy of WP-Cron's
	 * list is outdated, see run(), so regular times are not compared here.)
	 */
	public function test_overlapping_cron_requests_across_intervals() {
		$tables = $this->tables_at( array( -600, -599, -598, -597 ), $this->fixture( 's.csv', self::V1, 'delay=0.5' ) );
		for ( $interval = 1; $interval <= 2; $interval++ ) {
			$env    = array(
				'TABLEREFRESH_TEST_CRON_BUDGET' => '0.001',
				'TABLEREFRESH_TEST_BARRIER'     => uniqid( 'overlap' ) . ':2',
			);
			$first  = $this->start_wp_cron_php( $env );
			$second = $this->start_wp_cron_php( $env );
			$this->assertFalse( $this->wait_wp_cron_php( $first ) );
			$this->assertFalse( $this->wait_wp_cron_php( $second ) );
			unset( $env['TABLEREFRESH_TEST_BARRIER'] );
			for ( $request = 0; $request < 8 && $this->any_due( $tables ); $request++ ) {
				$this->run_wp_cron_php( $env );
			}
			$this->assertSame( array_fill( 0, 4, $interval ), $this->sync_counts( $tables ), "Interval {$interval}." );
			foreach ( $tables as $i => list( $table_id, $post_id ) ) {
				$this->assertSame( 1, Scheduler::count_events( $post_id ), "Interval {$interval}, table {$i}: one event." );
				$this->assertGreaterThan( time(), Scheduler::next_run( $post_id ) );
				$this->assertFalse( Table_Lock::is_locked( $table_id ) );
			}
			$this->advance_clock( $tables, HOUR_IN_SECONDS + 100 ); // Due again even after a restarted interval.
		}
	}

	/**
	 * A request that ends during a sync (here in the middle of writing the table) uses up that table's
	 * turn: the table runs at its next regular time and is recovered then. The deferred tables keep
	 * their place, and the next request continues with them.
	 */
	public function test_interrupted_request_keeps_the_deferred_tables_and_recovers_the_table() {
		$tables = $this->tables_at( array( -600, -599, -598 ), $this->fixture( 's.csv', self::V1 ) );
		$env    = array(
			'TABLEREFRESH_TEST_CRON_BUDGET' => '0.001',
			'TABLEREFRESH_TEST_LOCK_TTL'    => '3',
		);
		$this->run_wp_cron_php( $env );
		list( $killed_table, $killed ) = $tables[1];

		$this->run_wp_cron_php( $env + array( 'TABLEREFRESH_TEST_FAULT' => 'kill_after_post_update' ) );
		$this->assertSame( array( 1, 0, 0 ), $this->sync_counts( $tables ), 'Nothing recorded for the interrupted sync.' );
		$this->assertSame( 'initial', $this->load_table( $killed_table )['data'][0][0], 'The interrupted write was rolled back.' );
		$this->assertNotNull( \ArsenManukyan\TableRefresh\Journal::read( $killed_table ) );
		$this->assertGreaterThan( time(), Scheduler::next_run( $killed ), 'Its turn is used up: next regular time.' );
		$this->assertSame( $tables[2][2], Scheduler::next_run( $tables[2][1] ), 'The deferred table keeps its place.' );

		$this->run_wp_cron_php( $env );
		$this->assertSame( array( 1, 0, 1 ), $this->sync_counts( $tables ), 'The next request continues with the deferred table.' );

		for ( $wait = 0; $wait < 100 && Table_Lock::is_locked( $killed_table ); $wait++ ) {
			usleep( 100000 ); // The lock of the killed process expires.
		}
		$this->advance_clock( $tables, HOUR_IN_SECONDS );
		for ( $request = 0; $request < 4 && $this->any_due( $tables ); $request++ ) {
			$this->run_wp_cron_php( $env );
		}
		$this->assertSame( array( 2, 1, 2 ), $this->sync_counts( $tables ) );
		$this->assertSame( 'updated', Sync_Log::last( $killed )['outcome'] );
		$this->assertSame( 'Item', $this->load_table( $killed_table )['data'][0][0] );
		$this->assertNull( \ArsenManukyan\TableRefresh\Journal::read( $killed_table ), 'Recovered.' );
		$this->assertRegularTimesKept( $tables );
	}

	/**
	 * Regression: two overlapping requests start from the same list of due events. Request A synchronizes
	 * table 1; request B, over its budget, reaches table 1 afterwards. B must not hand table 1 back to
	 * the next request (it would be synchronized once more), whether A is still running or has finished.
	 */
	public function test_overlapping_request_does_not_hand_back_a_turn_already_taken() {
		$tables = $this->tables_at( array( -600, -599 ), $this->fixture( 's.csv', self::V1 ) );
		list( , $taken ) = $tables[1];
		wp_load_alloptions(); // Request B's copy of WP-Cron's list: both tables due.

		$this->wait_wp_cron_php( $this->start_wp_cron_php(), 0, false ); // Request A runs both tables.
		wp_cache_delete( $tables[0][1], 'post_meta' ); // B has not read the tables' meta yet.
		wp_cache_delete( $taken, 'post_meta' );
		$this->run_cron_in_process( 0.001 ); // Request B, from its outdated list: table 0, then table 1.

		wp_cache_flush_runtime();
		$this->assertSame( 1, count( Sync_Log::get( $taken )['entries'] ), 'Synchronized once, by request A.' );
		$this->assertSame( 1, Scheduler::count_events( $taken ) );
		$this->assertGreaterThan( time(), Scheduler::next_run( $taken ), 'Not handed back: next regular time.' );
		$this->assertSame( $tables[1][2] + HOUR_IN_SECONDS, Scheduler::next_run( $taken ) );
	}

	public function test_deferral_never_leaves_a_table_without_an_event_or_with_two() {
		$tables = $this->tables_at( array( -600, -599, -598 ), $this->fixture( 's.csv', self::V1 ) );
		$env    = array( 'TABLEREFRESH_TEST_CRON_BUDGET' => '0.001' );

		// A duplicate left by an earlier race: the deferral replaces both events with one, due now.
		list( , $duplicate ) = $tables[2];
		wp_schedule_event( time() + 1800, 'hourly', Scheduler::HOOK, array( $duplicate ) );
		$this->assertSame( 2, Scheduler::count_events( $duplicate ) );
		$this->run_wp_cron_php( $env );
		$this->assertSame( 1, Scheduler::count_events( $duplicate ) );
		$this->assertLessThanOrEqual( time(), Scheduler::next_run( $duplicate ) );

		// The process ends in the middle of a deferral (table 1 runs first, table 2 is deferred): the
		// table keeps an event, here two.
		$this->run_wp_cron_php( $env + array( 'TABLEREFRESH_TEST_FAULT' => 'kill_during_deferral' ) );
		$this->assertSame( array( 1, 1, 0 ), $this->sync_counts( $tables ) );
		$this->assertGreaterThanOrEqual( 1, Scheduler::count_events( $duplicate ), 'Never no event.' );
		$this->assertLessThanOrEqual( time(), Scheduler::next_run( $duplicate ), 'Still due.' );

		// The next requests repair it: one event per table, every table synchronized.
		for ( $request = 0; $request < 4 && $this->any_due( $tables ); $request++ ) {
			$this->run_wp_cron_php( $env );
		}
		$this->assertSame( array( 1, 1, 1 ), $this->sync_counts( $tables ) );
		foreach ( $tables as $i => list( , $post_id ) ) {
			$this->assertSame( 1, Scheduler::count_events( $post_id ), "Table {$i}." );
		}
	}

	/**
	 * Regression: when the event is not what WP-Cron leaves behind (here: the frequency was changed
	 * meanwhile), the deferral removed the events before it added the new one. A process that ended in
	 * between left an active table without any event, and nothing scheduled it again.
	 */
	public function test_interrupted_replacement_of_an_unexpected_event_never_leaves_a_table_without_an_event() {
		$tables = $this->tables_at( array( -600, -599, -598 ), $this->fixture( 's.csv', self::V1 ) );
		$env    = array( 'TABLEREFRESH_TEST_CRON_BUDGET' => '0.001' );
		list( , $changed ) = $tables[1];
		Sync_Config::save( $changed, array( 'frequency' => 'daily' ) + Sync_Config::get( $changed ) ); // The event is still hourly.

		// Table 0 runs; the process ends at the first change of WP-Cron's list while table 1 is deferred.
		$this->run_wp_cron_php( $env + array( 'TABLEREFRESH_TEST_FAULT' => 'kill_during_deferral' ) );
		$this->assertSame( array( 1, 0, 0 ), $this->sync_counts( $tables ) );
		$this->assertGreaterThanOrEqual( 1, Scheduler::count_events( $changed ), 'Never no event.' );
		$this->assertLessThanOrEqual( time(), Scheduler::next_run( $changed ), 'Still due.' );

		for ( $request = 0; $request < 4 && $this->any_due( $tables ); $request++ ) {
			$this->run_wp_cron_php( $env );
		}
		$this->assertSame( array( 1, 1, 1 ), $this->sync_counts( $tables ) );
		foreach ( $tables as $i => list( , $post_id ) ) {
			$this->assertSame( 1, Scheduler::count_events( $post_id ), "Table {$i}." );
			$this->assertGreaterThan( time(), Scheduler::next_run( $post_id ) );
		}
		$this->assertSame( 'daily', wp_get_scheduled_event( Scheduler::HOOK, array( $changed ) )->schedule );
	}

	/**
	 * An event that cannot be scheduled (here: another plugin's filter refuses it) must not cost the
	 * table the event it has.
	 */
	public function test_deferral_that_cannot_schedule_keeps_the_existing_event() {
		$tables = $this->tables_at( array( -600, -599 ), $this->fixture( 's.csv', self::V1 ) );
		list( , $post_id ) = $tables[1];
		$refuse            = static function ( $event ) {
			return $event && Scheduler::HOOK === $event->hook && doing_action( Scheduler::HOOK ) ? false : $event;
		};
		add_filter( 'schedule_event', $refuse );
		try {
			$this->run_cron_in_process( 0.001 );
		} finally {
			remove_filter( 'schedule_event', $refuse );
		}
		$this->assertSame( array( 1, 0 ), $this->sync_counts( $tables ) );
		$this->assertSame( 1, Scheduler::count_events( $post_id ), 'The event that WP-Cron left stays.' );
		$this->assertGreaterThan( time(), Scheduler::next_run( $post_id ) );
		wp_cache_flush_runtime();
		$this->assertSame( 1, Scheduler::count_events( $post_id ), 'Also in the database.' );
	}

	/**
	 * Regression: two overlapping requests defer the same table at the same moment. The second request's
	 * write of WP-Cron's list is identical to the first one's and changes no row, so update_option()
	 * leaves the second request's copy without the new event; removing the old event from that copy
	 * then stored a list without any event of the table.
	 */
	public function test_deferral_survives_an_identical_write_by_an_overlapping_request() {
		global $wpdb;
		$tables = $this->tables_at( array( -600, -599 ), $this->fixture( 's.csv', self::V1 ) );
		list( , $post_id, $due ) = $tables[1];
		$writes                  = 0;
		// The other request: stores the same list right before this request's first write of the deferral.
		$other = static function ( $option, $old_value, $value ) use ( &$writes, $wpdb ) {
			if ( 'cron' === $option && doing_action( Scheduler::HOOK ) && 1 === ++$writes ) {
				$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $value ) ), array( 'option_name' => 'cron' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		};
		add_action( 'update_option', $other, 10, 3 );
		try {
			$this->run_cron_in_process( 0.001 );
		} finally {
			remove_action( 'update_option', $other );
		}
		$this->assertGreaterThanOrEqual( 1, $writes, 'The deferral wrote WP-Cron\'s list.' );
		$this->assertSame( 1, Scheduler::count_events( $post_id ), 'In this request\'s copy.' );
		wp_cache_flush_runtime();
		$this->assertSame( array( 1, 0 ), $this->sync_counts( $tables ) );
		$this->assertSame( 1, Scheduler::count_events( $post_id ), 'In the database.' );
		$this->assertSame( $due, Scheduler::next_run( $post_id ), 'Deferred: back at the time it became due.' );
	}

	/**
	 * Regression: a turn marker ahead of the clock (the server clock was set back, or the value comes
	 * from a server whose clock was ahead) made every deferral of the table look like a turn already
	 * taken. With room for one sync per request, the table was never synchronized.
	 */
	public function test_turn_marker_ahead_of_the_clock_does_not_keep_a_table_waiting() {
		// A marker from a clock that was ahead.
		$tables = $this->tables_at( array( -600, -599, -598 ), $this->fixture( 's.csv', self::V1 ) );
		list( , $ahead, $due ) = $tables[2];
		update_post_meta( $ahead, Scheduler::TURN_META_KEY, time() + DAY_IN_SECONDS );
		$this->run_cron_in_process( 0.001 );
		$this->assertSame( array( 1, 0, 0 ), $this->sync_counts( $tables ) );
		$this->assertSame( $due, Scheduler::next_run( $ahead ), 'Handed to the next request.' );
		$this->assertSame( '', get_post_meta( $ahead, Scheduler::TURN_META_KEY, true ), 'The impossible marker is gone.' );
		$this->run_cron_in_process( 0.001 );
		$this->run_cron_in_process( 0.001 );
		$this->assertSame( array( 1, 1, 1 ), $this->sync_counts( $tables ) );
		$this->assertSame( (string) $due, get_post_meta( $ahead, Scheduler::TURN_META_KEY, true ), 'The turn taken.' );

		// The server clock is set back by five hours after every table took its turn; then the schedules
		// are started anew (settings saved again), so the tables are due before their old markers.
		$this->advance_clock( $tables, -5 * HOUR_IN_SECONDS );
		foreach ( $tables as list( , $post_id ) ) {
			$this->assertGreaterThan( time() + 4 * HOUR_IN_SECONDS, (int) get_post_meta( $post_id, Scheduler::TURN_META_KEY, true ) );
			Scheduler::apply( $post_id, Sync_Config::get( $post_id ), true );
		}
		$this->advance_clock( $tables, HOUR_IN_SECONDS + 10 );
		for ( $request = 0; $request < 3; $request++ ) {
			$this->run_cron_in_process( 0.001 );
		}
		$this->assertSame( array( 2, 2, 2 ), $this->sync_counts( $tables ), 'Every table got its turn.' );
		foreach ( $tables as list( , $post_id ) ) {
			$this->assertLessThanOrEqual( time(), (int) get_post_meta( $post_id, Scheduler::TURN_META_KEY, true ) );
			$this->assertSame( 1, Scheduler::count_events( $post_id ) );
		}
	}

	/**
	 * The runner removes the scheduler's meta of a post without a configuration right after the
	 * scheduler started it, so the final state alone cannot show a turn marker written for such a
	 * post: every write of the marker is recorded.
	 */
	public function test_turn_marker_is_removed_with_the_configuration_and_not_written_for_other_posts() {
		global $wpdb;
		$tables = $this->tables_at( array( -600 ), $this->fixture( 's.csv', self::V1 ) );
		list( , $post_id, $due ) = $tables[0];
		$deleted                 = 999999999; // An event that outlived its table; due as well.
		wp_schedule_event( time() - 500, 'hourly', Scheduler::HOOK, array( $deleted ) );
		$ran        = array();
		$written    = array();
		$note_run   = static function ( $id ) use ( &$ran ) {
			$ran[] = (int) $id;
		};
		$note_write = static function ( $meta_id, $object_id, $meta_key ) use ( &$written ) {
			if ( Scheduler::TURN_META_KEY === $meta_key ) {
				$written[] = (int) $object_id;
			}
		};
		add_action( Scheduler::HOOK, $note_run, 0 );
		add_action( 'added_post_meta', $note_write, 10, 3 );
		add_action( 'updated_post_meta', $note_write, 10, 3 );
		try {
			$this->run_cron_in_process( 0 ); // No time budget: both events reach Scheduler::run(), none is deferred.
			$this->assertContains( $post_id, $ran );
			$this->assertContains( $deleted, $ran, 'The event of the post without a configuration ran.' );
			$this->assertContains( $post_id, $written );
			$this->assertNotContains( $deleted, $written, 'No turn marker for a post without a configuration, not even until the runner removes it.' );
			$this->assertSame( (string) $due, get_post_meta( $post_id, Scheduler::TURN_META_KEY, true ) );
			$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $deleted ) ), 'No meta for a post that does not exist.' );
			$this->assertSame( 0, Scheduler::count_events( $deleted ) );
		} finally {
			remove_action( Scheduler::HOOK, $note_run, 0 );
			remove_action( 'added_post_meta', $note_write, 10 );
			remove_action( 'updated_post_meta', $note_write, 10 );
			wp_clear_scheduled_hook( Scheduler::HOOK, array( $deleted ) );
			$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $deleted ) );
		}

		// "Remove sync setup" in the admin screen.
		Sync_Config::delete( $post_id );
		Scheduler::apply( $post_id, null );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, Scheduler::TURN_META_KEY ) ) );
	}

	/**
	 * `wp cron event run` runs an event at once, also one that is not due yet. That is no turn of the
	 * table's schedule: a turn marker would hold a time that has not come yet.
	 */
	public function test_turn_marker_is_not_written_for_an_event_run_before_it_is_due() {
		list( , $post_id ) = $this->configured_table( $this->fixture( 's.csv', self::V1 ), 'hourly' );
		wp_clear_scheduled_hook( Scheduler::HOOK, array( $post_id ) );
		wp_schedule_event( time() + 600, 'hourly', Scheduler::HOOK, array( $post_id ) ); // Not due yet.

		$output = shell_exec( 'wp --path=' . escapeshellarg( TABLEREFRESH_TESTS_WP_DIR ) . ' cron event run ' . Scheduler::HOOK . ' 2>&1' );
		$this->assertStringContainsString( 'Executed', (string) $output );
		wp_cache_flush_runtime(); // The other process changed the cron option and post meta.
		$this->assertSame( 'scheduled', Sync_Log::last( $post_id )['trigger'], 'The sync ran.' );
		$this->assertSame( '', get_post_meta( $post_id, Scheduler::TURN_META_KEY, true ) );
	}

	public function test_uninstall_removes_tablerefresh_data_but_not_the_table() {
		list( $table_id, $post_id ) = $this->configured_table( $this->fixture( 's.csv', self::V1 ) );
		( new Sync_Runner() )->run( $post_id, 'manual' );
		$data = $this->load_table( $table_id )['data'];

		// Rows that can be left in the options table: an admin notice and form state (transients
		// of 5 minutes, in the database without a persistent object cache), a lock and a journal.
		global $wpdb;
		$own   = array( '_transient_tablerefresh_notice_1', '_transient_timeout_tablerefresh_notice_1', '_transient_tablerefresh_form_1', '_transient_timeout_tablerefresh_form_1', 'tablerefresh_lock_uninstalltest', 'tablerefresh_journal_uninstalltest' );
		$other = array( '_transient_tablerefreshx_test', 'tablerefreshx_test' ); // Not TableRefresh's prefix.
		foreach ( array_merge( $own, $other ) as $name ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off')", $name ) );
		}

		$code = "define( 'WP_UNINSTALL_PLUGIN', 'tablerefresh/tablerefresh.php' ); include WP_PLUGIN_DIR . '/tablerefresh/uninstall.php';";
		shell_exec( 'wp --path=' . escapeshellarg( TABLEREFRESH_TESTS_WP_DIR ) . ' eval ' . escapeshellarg( $code ) . ' 2>&1' );

		wp_cache_flush_runtime();
		$remaining = static function ( array $names ) use ( $wpdb ) {
			return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ('" . implode( "','", array_map( 'esc_sql', $names ) ) . "')" );
		};
		$left = $remaining( $other );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name IN ('" . implode( "','", array_map( 'esc_sql', $other ) ) . "')" );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $post_id, $wpdb->esc_like( '_tablerefresh_' ) . '%' ) ) );
		$this->assertSame( array(), $remaining( $own ) );
		$this->assertEqualsCanonicalizing( $other, $left, 'Options of other plugins are kept.' );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK, array( $post_id ) ) );
		$this->assertSame( $data, $this->load_table( $table_id )['data'], 'The TablePress table is kept.' );
	}
}
