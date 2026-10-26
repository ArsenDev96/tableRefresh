<?php
/**
 * Behavior on real non-transactional tables (MyISAM).
 *
 * Converts wp_posts and wp_postmeta to MyISAM for the duration of the class, and back to
 * InnoDB afterwards. Excluded by default; run with: sh dev/run-tests.sh --group engine
 *
 * @package TableRefresh
 * @group engine
 */

use TableRefresh\Table_Updater;

/**
 * @group engine
 */
class NonTransactionalEngineTest extends Integration_Test_Case {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::set_engine( 'MyISAM' );
	}

	public static function tearDownAfterClass(): void {
		self::set_engine( 'InnoDB' );
		parent::tearDownAfterClass();
	}

	private static function set_engine( string $engine ): void {
		global $wpdb;
		foreach ( array( $wpdb->posts, $wpdb->postmeta ) as $table ) {
			$wpdb->query( "ALTER TABLE {$table} ENGINE = {$engine}" ); // phpcs:ignore WordPress.DB
		}
	}

	public function test_engines_are_reported() {
		global $wpdb;
		$engines = Table_Updater::storage_engines();
		$this->assertSame( 'myisam', strtolower( $engines[ $wpdb->posts ] ) );
		$this->assertSame( 'myisam', strtolower( $engines[ $wpdb->postmeta ] ) );
	}

	public function test_sync_is_refused_without_transaction_support() {
		$table_id = $this->create_table( array( array( 'keep' ) ) );
		$before   = $this->raw_state( $table_id );
		$this->assertFailedUnchanged( $this->sync( $table_id, $this->static_url( 'simple.csv' ) ), 'tablerefresh_db_not_transactional', $table_id, $before );
	}

	public function test_opt_in_updates_without_claiming_atomicity_and_undoes_failures() {
		add_filter( 'tablerefresh_allow_non_atomic_updates', '__return_true' );
		try {
			$table_id = $this->create_table( array( array( 'keep' ) ) );
			$result   = $this->sync( $table_id, $this->static_url( 'simple.csv' ) );
			$this->assertSame( 'updated', $result['status'] );
			$this->assertFalse( $result['atomic'] );

			// A failed write is undone from the journal snapshot (no transaction to roll back).
			$before = $this->raw_state( $table_id );
			$run    = $this->run_cli(
				array( 'sync', $table_id, $this->fixture( 'n.csv', "x,y,z\n1,2,3\n" ) ),
				array( 'TABLEREFRESH_TEST_FAULT' => 'fail_visibility_meta', 'TABLEREFRESH_TEST_NO_TRANSACTIONS' => '1' )
			);
			$this->assertSame( 'tablerefresh_table_save_failed', $run['json']['code'], $run['stdout'] . $run['stderr'] );
			$this->assertTrue( $run['json']['data']['tablerefresh_reverted']['reverted'] );
			$this->assertSame( $before, $this->raw_state( $table_id ) );
		} finally {
			remove_filter( 'tablerefresh_allow_non_atomic_updates', '__return_true' );
		}
	}
}
