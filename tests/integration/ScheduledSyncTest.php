<?php
/**
 * Configured automatic synchronization: configuration, WP-Cron events, history, pausing.
 *
 * @package TableRefresh
 */

use TableRefresh\Editor_Guard;
use TableRefresh\Plugin;
use TableRefresh\Scheduler;
use TableRefresh\Sync_Config;
use TableRefresh\Sync_Log;
use TableRefresh\Sync_Runner;
use TableRefresh\Sync_State;
use TableRefresh\Table_Lock;
use TableRefresh\TablePress_Adapter;

/**
 * @covers \TableRefresh\Sync_Config
 * @covers \TableRefresh\Sync_Log
 * @covers \TableRefresh\Sync_Runner
 * @covers \TableRefresh\Scheduler
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
			$this->assertNull( \TableRefresh\Journal::read( $table_id ) );
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

	public function test_uninstall_removes_tablerefresh_data_but_not_the_table() {
		list( $table_id, $post_id ) = $this->configured_table( $this->fixture( 's.csv', self::V1 ) );
		( new Sync_Runner() )->run( $post_id, 'manual' );
		$data = $this->load_table( $table_id )['data'];

		$code = "define( 'WP_UNINSTALL_PLUGIN', 'tablerefresh/tablerefresh.php' ); include WP_PLUGIN_DIR . '/tablerefresh/uninstall.php';";
		shell_exec( 'wp --path=' . escapeshellarg( TABLEREFRESH_TESTS_WP_DIR ) . ' eval ' . escapeshellarg( $code ) . ' 2>&1' );

		wp_cache_flush_runtime();
		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $post_id, $wpdb->esc_like( '_tablerefresh_' ) . '%' ) ) );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK, array( $post_id ) ) );
		$this->assertSame( $data, $this->load_table( $table_id )['data'], 'The TablePress table is kept.' );
	}
}
