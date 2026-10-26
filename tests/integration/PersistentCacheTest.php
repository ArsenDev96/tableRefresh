<?php
/**
 * Synchronization with a persistent object cache (Redis), as seen by visitors.
 *
 * Skipped unless a persistent object cache is active: run `sh dev/redis.sh enable` first.
 *
 * @package TableRefresh
 */

/**
 * @covers \TableRefresh\Table_Updater
 * @covers \TableRefresh\TablePress_Adapter::clear_caches
 */
class PersistentCacheTest extends Integration_Test_Case {

	/**
	 * Page that shows the test table.
	 *
	 * @var int
	 */
	private int $page_id = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'No persistent object cache is active (sh dev/redis.sh enable).' );
		}
	}

	protected function tearDown(): void {
		if ( $this->page_id ) {
			wp_delete_post( $this->page_id, true );
		}
		parent::tearDown();
	}

	/**
	 * Creates a table and a published page with its shortcode, and renders the page once
	 * (logged out), which fills the object cache and TablePress's output cache.
	 *
	 * @return string Table ID.
	 */
	private function cached_table_on_page(): string {
		$table_id      = $this->create_table( array( array( 'Header' ), array( 'ORIGINAL' ) ) );
		$this->page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Cached table',
				'post_content' => '[table id=' . $table_id . ' /]',
			)
		);
		$this->assertStringContainsString( '>ORIGINAL<', $this->page() );
		$this->assertNotFalse( get_transient( 'tablepress_c_' . md5( $table_id ) ), 'TablePress output cache is primed (in Redis).' );
		return $table_id;
	}

	/**
	 * The page as a logged-out visitor sees it.
	 *
	 * @return string
	 */
	private function page(): string {
		return $this->http( '/?page_id=' . $this->page_id );
	}

	/**
	 * Table data as a fresh PHP process sees it (through the object cache).
	 *
	 * @param string $table_id Table ID.
	 * @return string JSON.
	 */
	private function data_in_new_process( string $table_id ): string {
		$process = proc_open( array( 'wp', '--path=' . TABLEREFRESH_TESTS_WP_DIR, 'eval', 'echo wp_json_encode( TablePress::$model_table->load( "' . $table_id . '" )["data"] );' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$out     = stream_get_contents( $pipes[1] );
		proc_close( $process );
		return trim( (string) $out );
	}

	public function test_page_shows_new_data_after_a_sync_in_another_process() {
		$table_id = $this->cached_table_on_page();
		$run      = $this->run_cli( array( 'sync', $table_id, $this->fixture( 'new.csv', "Header\nSYNCED\n" ) ) );
		$this->assertSame( 'updated', $run['json']['status'], $run['stdout'] . $run['stderr'] );

		$html = $this->page();
		$this->assertStringContainsString( '>SYNCED<', $html );
		$this->assertStringNotContainsString( '>ORIGINAL<', $html );
		$this->assertSame( '[["Header"],["SYNCED"]]', $this->data_in_new_process( $table_id ) );
	}

	public function test_failed_write_leaves_no_uncommitted_data_in_the_cache() {
		$table_id = $this->cached_table_on_page();
		$run      = $this->run_cli( array( 'sync', $table_id, $this->fixture( 'new.csv', "Header\nFAILED\n" ) ), array( 'TABLEREFRESH_TEST_FAULT' => 'throw_after_post_update' ) );
		$this->assertSame( 'tablerefresh_table_save_failed', $run['json']['code'] );

		$this->assertStringContainsString( '>ORIGINAL<', $this->page() );
		$this->assertSame( '[["Header"],["ORIGINAL"]]', $this->data_in_new_process( $table_id ) );
	}

	public function test_killed_write_leaves_no_uncommitted_data_in_the_cache() {
		$table_id = $this->cached_table_on_page();
		$run      = $this->run_cli(
			array( 'sync', $table_id, $this->fixture( 'new.csv', "Header\nKILLED\n" ) ),
			array(
				'TABLEREFRESH_TEST_FAULT'    => 'kill_after_post_update',
				'TABLEREFRESH_TEST_LOCK_TTL' => '5',
			)
		);
		$this->assertStringContainsString( 'TEST FAULT: SIGKILL', $run['stderr'] );

		// The database rolled the write back. Before any recovery runs, neither visitors nor
		// other processes may see the data that was never committed.
		$this->assertSame( '[["Header"],["ORIGINAL"]]', $this->data_in_new_process( $table_id ) );
		$this->assertStringContainsString( '>ORIGINAL<', $this->page() );
		$this->assertStringNotContainsString( 'KILLED', $this->page() );
	}

	public function test_restore_of_a_partial_non_atomic_write_reaches_the_cache() {
		$table_id = $this->cached_table_on_page();
		$this->run_cli(
			array( 'sync', $table_id, $this->fixture( 'new.csv', "Header,X\nPARTIAL,Y\n" ) ),
			array(
				'TABLEREFRESH_TEST_FAULT'           => 'kill_after_post_update',
				'TABLEREFRESH_TEST_NO_TRANSACTIONS' => '1',
				'TABLEREFRESH_TEST_LOCK_TTL'        => '5',
			)
		);
		sleep( 6 );
		$recovered = $this->run_cli( array( 'recover', $table_id ) );
		$this->assertSame( 'restored', $recovered['json']['status'], $recovered['stdout'] . $recovered['stderr'] );
		$this->assertSame( '[["Header"],["ORIGINAL"]]', $this->data_in_new_process( $table_id ) );
		$this->assertStringContainsString( '>ORIGINAL<', $this->page() );
	}
}
