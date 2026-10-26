<?php
/**
 * Database growth from repeated synchronization, and the revision retention policy.
 *
 * @package TableRefresh
 */

require_once __DIR__ . '/RevisionGrowthMeasurement.php';

/**
 * @covers \TableRefresh\Revision_Policy
 */
class RevisionPolicyTest extends Integration_Test_Case {
	use Revision_Growth_Measurement;

	const ROWS    = 400;
	const COLUMNS = 8;
	const SYNCS   = 25;

	/**
	 * Runs changed and unchanged syncs and returns the growth.
	 *
	 * @param int|null $keep Value for tablerefresh_revisions_to_keep, or null for the default.
	 * @return array<string, int>
	 */
	private function measure( ?int $keep ): array {
		$filter = static fn() => $keep;
		if ( null !== $keep ) {
			add_filter( 'tablerefresh_revisions_to_keep', $filter );
		}
		try {
			$table_id = $this->create_table( array( array( 'a' ) ) );
			$start    = $this->growth( $table_id );
			for ( $i = 1; $i <= self::SYNCS; $i++ ) {
				$url = $this->fixture( 'grow.csv', $this->generated_csv( self::ROWS, self::COLUMNS, $i ) );
				$this->assertSame( 'updated', $this->sync( $table_id, $url )['status'] );
				$this->assertSame( 'unchanged', $this->sync( $table_id, $url )['status'] );
			}
			$end = $this->growth( $table_id );
		} finally {
			remove_filter( 'tablerefresh_revisions_to_keep', $filter );
		}
		$result = array(
			'table_bytes'    => strlen( $this->raw_state( $table_id )['post_content'] ),
			'revisions'      => $end['revisions'] - $start['revisions'],
			'sync_revisions' => $end['sync_revisions'],
			'revision_bytes' => $end['revision_bytes'] - $start['revision_bytes'],
			'meta_rows'      => $end['meta_rows'] - $start['meta_rows'],
		);
		fwrite( STDERR, sprintf( "\n[measurement] keep=%s: %d changed + %d unchanged syncs of a %dx%d table (%d bytes): +%d revisions (%d by sync), +%d bytes in revisions, +%d meta rows\n", null === $keep ? 'default' : (string) $keep, self::SYNCS, self::SYNCS, self::ROWS, self::COLUMNS, $result['table_bytes'], $result['revisions'], $result['sync_revisions'], $result['revision_bytes'], $result['meta_rows'] ) );
		return $result;
	}

	public function test_growth_without_retention_is_one_full_copy_per_changed_sync() {
		$growth = $this->measure( -1 );
		$this->assertSame( self::SYNCS, $growth['revisions'], 'Unchanged syncs must not create revisions.' );
		$this->assertGreaterThanOrEqual( self::SYNCS * $growth['table_bytes'] * 0.95, $growth['revision_bytes'] );
	}

	public function test_default_retention_bounds_growth() {
		$growth = $this->measure( null );
		$this->assertSame( 5, $growth['revisions'] );
		$this->assertSame( 5, $growth['sync_revisions'] );
		$this->assertLessThanOrEqual( 6 * $growth['table_bytes'], $growth['revision_bytes'] );
	}

	/**
	 * Saves the table as the administrator, like "Save Changes" in TablePress, with a new A1 value.
	 *
	 * @param string $table_id Table ID.
	 * @param string $value    New A1 value.
	 */
	private function manual_save( string $table_id, string $value ): void {
		$table               = $this->load_table( $table_id );
		$table['data'][0][0] = $value;
		wp_set_current_user( 1 );
		$this->assertSame( $table_id, TablePress::$model_table->save( $table ) );
		wp_set_current_user( 0 );
	}

	/**
	 * IDs of revisions not created by TableRefresh.
	 *
	 * @param string $table_id Table ID.
	 * @return int[]
	 */
	private function manual_revision_ids( string $table_id ): array {
		global $wpdb;
		$post_id = TableRefresh\TablePress_Adapter::get_post_id( $table_id );
		$all     = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision' ORDER BY ID", $post_id ) ) );
		return array_values( array_diff( $all, TableRefresh\Revision_Policy::sync_revision_ids( $post_id ) ) );
	}

	public function test_manual_revisions_survive_a_site_wide_revision_limit() {
		$site_limit = static fn() => 3; // Like define( 'WP_POST_REVISIONS', 3 ).
		$keep_two   = static fn() => 2;
		add_filter( 'wp_revisions_to_keep', $site_limit, 10 );
		add_filter( 'tablerefresh_revisions_to_keep', $keep_two );
		try {
			$table_id = $this->create_table( array( array( 'start' ) ) );
			$post_id  = TableRefresh\TablePress_Adapter::get_post_id( $table_id );
			foreach ( array( 'm1', 'm2', 'm3' ) as $value ) {
				$this->manual_save( $table_id, $value );
			}
			$manual = $this->manual_revision_ids( $table_id );
			$this->assertCount( 3, $manual );

			// The table is linked to a source now; overwrite the manual data once, then sync often.
			for ( $i = 1; $i <= 6; $i++ ) {
				$result = $this->sync( $table_id, $this->fixture( 'limit.csv', "sync{$i}\n" ), array( 'on_conflict' => 'overwrite' ) );
				$this->assertSame( 'updated', $result['status'] );
			}
			$this->assertSame( $manual, $this->manual_revision_ids( $table_id ), 'Syncs must not delete manual revisions.' );
			$this->assertCount( 2, TableRefresh\Revision_Policy::sync_revision_ids( $post_id ) );

			// A later manual save is pruned by WordPress to the site limit for manual revisions only.
			$this->manual_save( $table_id, 'm4' );
			$this->assertCount( 3, $this->manual_revision_ids( $table_id ) );
			$this->assertCount( 2, TableRefresh\Revision_Policy::sync_revision_ids( $post_id ), 'Sync revisions do not take manual slots.' );
		} finally {
			remove_filter( 'wp_revisions_to_keep', $site_limit, 10 );
			remove_filter( 'tablerefresh_revisions_to_keep', $keep_two );
		}
	}

	public function test_keep_zero_creates_no_sync_revisions() {
		$none = static fn() => 0;
		add_filter( 'tablerefresh_revisions_to_keep', $none );
		try {
			$table_id = $this->create_table( array( array( 'a' ) ) );
			$before   = $this->revision_count( $table_id );
			$this->assertSame( 'updated', $this->sync( $table_id, $this->fixture( 'zero.csv', "z\n" ) )['status'] );
			$this->assertSame( $before, $this->revision_count( $table_id ) );
		} finally {
			remove_filter( 'tablerefresh_revisions_to_keep', $none );
		}
	}

	public function test_failed_sync_prunes_nothing() {
		$table_id = $this->create_table( array( array( 'a' ) ) );
		for ( $i = 1; $i <= 7; $i++ ) {
			$this->sync( $table_id, $this->fixture( 'p.csv', "p{$i}\n" ) );
		}
		$post_id = TableRefresh\TablePress_Adapter::get_post_id( $table_id );
		$ids     = TableRefresh\Revision_Policy::sync_revision_ids( $post_id );
		$this->assertCount( 5, $ids );

		$run = $this->run_cli( array( 'sync', $table_id, $this->fixture( 'p.csv', "failing\n" ) ), array( 'TABLEREFRESH_TEST_FAULT' => 'throw_after_post_update' ) );
		$this->assertSame( 'tablerefresh_table_save_failed', $run['json']['code'] );
		$this->assertSame( $ids, TableRefresh\Revision_Policy::sync_revision_ids( $post_id ) );
	}

	/**
	 * @dataProvider atomicity
	 */
	public function test_recovery_before_and_after_retention_cleanup( bool $atomic ) {
		$table_id = $this->create_table( array( array( 'start' ) ) );
		$post_id  = TableRefresh\TablePress_Adapter::get_post_id( $table_id );
		$this->manual_save( $table_id, 'manual' );
		$manual = $this->manual_revision_ids( $table_id );
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->sync( $table_id, $this->fixture( 'r.csv', "r{$i}\n" ), array( 'on_conflict' => 'overwrite' ) );
		}
		$kept   = TableRefresh\Revision_Policy::sync_revision_ids( $post_id );
		$before = $this->raw_state( $table_id );
		$this->assertCount( 5, $kept, 'Retention ran before the crash.' );

		// A sync is killed in the middle of its write.
		$env = array(
			'TABLEREFRESH_TEST_FAULT'    => 'kill_after_revision',
			'TABLEREFRESH_TEST_LOCK_TTL' => '5',
		);
		if ( ! $atomic ) {
			$env['TABLEREFRESH_TEST_NO_TRANSACTIONS'] = '1';
		}
		// The new data has another size, so the visibility meta is still pending at the crash.
		$run = $this->run_cli( array( 'sync', $table_id, $this->fixture( 'r.csv', "crash,x\n" ) ), $env );
		$this->assertStringContainsString( 'TEST FAULT: SIGKILL', $run['stderr'] );
		$this->assertNotNull( TableRefresh\Journal::read( $table_id ) );
		// With a transaction, the database discarded the revision with the rest of the write.
		$this->assertCount( $atomic ? 5 : 6, TableRefresh\Revision_Policy::sync_revision_ids( $post_id ) );

		sleep( 6 );
		$recovered = $this->run_cli( array( 'recover', $table_id ) );
		$this->assertSame( $atomic ? 'rolled_back' : 'restored', $recovered['json']['status'], $recovered['stdout'] . $recovered['stderr'] );
		$this->assertSame( $before, $this->raw_state( $table_id ) );
		$this->assertSame( $kept, TableRefresh\Revision_Policy::sync_revision_ids( $post_id ), 'Only the crashed write\'s revision was removed.' );
		$this->assertSame( $manual, $this->manual_revision_ids( $table_id ) );

		// The next successful sync applies retention again; manual revisions and the journal are unaffected.
		$this->assertSame( 'updated', $this->sync( $table_id, $this->fixture( 'r.csv', "after\n" ) )['status'] );
		$this->assertCount( 5, TableRefresh\Revision_Policy::sync_revision_ids( $post_id ) );
		$this->assertSame( $manual, $this->manual_revision_ids( $table_id ) );
		$this->assertNull( TableRefresh\Journal::read( $table_id ) );
	}

	public function atomicity(): array {
		return array(
			'InnoDB transaction' => array( true ),
			'non-atomic opt-in'  => array( false ),
		);
	}
}
