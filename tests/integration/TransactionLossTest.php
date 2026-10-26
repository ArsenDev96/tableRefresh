<?php
/**
 * The database ends TableRefresh's transaction during the write: deadlocks and lost connections.
 *
 * After a deadlock, InnoDB rolls back the whole transaction (and releases its row locks); after a
 * lost connection, wpdb reconnects and repeats the failed statement in a new session. Statements
 * that follow would run outside any transaction and be committed one by one. The faults are real:
 * a second database connection deadlocks with, or kills, the syncing process's connection
 * (see dev/mu-plugins).
 *
 * @package TableRefresh
 */

use TableRefresh\Journal;
use TableRefresh\Table_Lock;

/**
 * @covers \TableRefresh\Table_Updater
 */
class TransactionLossTest extends Integration_Test_Case {

	const ORIGINAL = array( array( 'h1', 'h2' ), array( 'o1', 'o2' ) );
	const NEW_CSV  = "n1,n2,n3\nv1,v2,v3\nw1,w2,w3\n";

	private function assertUnchanged( string $table_id, array $before, int $revisions ): void {
		$this->assertSame( $before, $this->raw_state( $table_id ), 'Nothing of the interrupted write may remain.' );
		$this->assertSame( self::ORIGINAL, $this->load_table( $table_id )['data'] );
		$this->assertSame( $revisions, $this->revision_count( $table_id ) );
		$this->assertNull( Journal::read( $table_id ) );
		$this->assertFalse( Table_Lock::is_locked( $table_id ) );
	}

	public function test_deadlock_during_the_write_is_rolled_back_and_retried() {
		$table_id  = $this->create_table( self::ORIGINAL );
		$revisions = $this->revision_count( $table_id );

		$run = $this->run_cli( array( 'sync', $table_id, $this->fixture( 'new.csv', self::NEW_CSV ) ), array( 'TABLEREFRESH_TEST_FAULT' => 'deadlock_before_post_update_once' ) );
		$this->assertStringContainsString( 'TEST FAULT: deadlock: Deadlock found', $run['stderr'], 'The fault must cause a real deadlock.' );
		$this->assertSame( 'updated', $run['json']['status'] ?? null, $run['stdout'] . $run['stderr'] );
		$this->assertSame( 2, $run['json']['attempts'] );
		$this->assertSame( 1, substr_count( $run['stderr'], 'TEST OBSERVER: ' ), 'WordPress wrote the post only in the second attempt.' );
		$this->assertStringContainsString( 'TEST OBSERVER: [["h1","h2"],["o1","o2"]]', $run['stderr'], 'Other connections see the old data until the commit.' );
		$this->assertSame( array( array( 'n1', 'n2', 'n3' ), array( 'v1', 'v2', 'v3' ), array( 'w1', 'w2', 'w3' ) ), $this->load_table( $table_id )['data'] );
		$this->assertSame( $revisions + 1, $this->revision_count( $table_id ), 'Only the successful attempt left a revision.' );
		$this->assertNull( Journal::read( $table_id ) );
		$this->assertSame( 'unchanged', $this->sync( $table_id, $this->fixture( 'new.csv', self::NEW_CSV ) )['status'], 'The baseline belongs to the stored data.' );
	}

	public function test_repeated_deadlocks_leave_the_table_unchanged() {
		$table_id  = $this->create_table( self::ORIGINAL );
		$before    = $this->raw_state( $table_id );
		$revisions = $this->revision_count( $table_id );

		$run = $this->run_cli( array( 'sync', $table_id, $this->fixture( 'new.csv', self::NEW_CSV ) ), array( 'TABLEREFRESH_TEST_FAULT' => 'deadlock_before_post_update' ) );
		$this->assertSame( 'tablerefresh_write_interrupted', $run['json']['code'] ?? null, $run['stdout'] . $run['stderr'] );
		$this->assertTrue( $run['json']['data']['tablerefresh_reverted']['reverted'] );
		$this->assertSame( 3, substr_count( $run['stderr'], 'TEST FAULT: deadlock' ), 'Three attempts.' );
		$this->assertStringNotContainsString( 'TEST OBSERVER', $run['stderr'], 'No write may run after the deadlock, not even briefly outside the transaction.' );
		$this->assertUnchanged( $table_id, $before, $revisions );
	}

	public function test_journal_of_a_killed_retry_describes_that_retry_and_recovers() {
		global $wpdb;
		$table_id  = $this->create_table( self::ORIGINAL );
		$before    = $this->raw_state( $table_id );
		$revisions = $this->revision_count( $table_id );

		// Attempt 1 deadlocks and is undone; attempt 2 is killed in the middle of its write.
		$run = $this->run_cli(
			array( 'sync', $table_id, $this->fixture( 'new.csv', self::NEW_CSV ) ),
			array(
				'TABLEREFRESH_TEST_FAULT'    => 'deadlock_before_post_update_then_kill',
				'TABLEREFRESH_TEST_LOCK_TTL' => '5',
			)
		);
		$this->assertStringContainsString( 'TEST FAULT: deadlock: Deadlock found', $run['stderr'] );
		$this->assertStringContainsString( 'TEST FAULT: SIGKILL', $run['stderr'], 'The retry was killed.' );
		$this->assertNull( $run['json'] );

		// InnoDB discarded the retry's transaction. Exactly one journal remains: the retry's,
		// owned by the killed process's lock, with the original table as its snapshot.
		$this->assertSame( $before, $this->raw_state( $table_id ) );
		$journal = Journal::read( $table_id );
		$this->assertNotNull( $journal );
		$journals = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( Journal::PREFIX ) . '%' ) );
		$this->assertCount( 1, array_filter( $journals, static fn( $value ) => ( json_decode( $value, true )['table_id'] ?? null ) === $table_id ) );
		$lock = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Table_Lock::option_name( $table_id ) ) );
		$this->assertSame( $lock, $journal['owner'], 'The journal belongs to the lock of the killed retry.' );
		$this->assertSame( $before['post_content'], $journal['snapshot']['post_content'] );
		$this->assertSame( md5( wp_json_encode( array( array( 'n1', 'n2', 'n3' ), array( 'v1', 'v2', 'v3' ), array( 'w1', 'w2', 'w3' ) ) ) ), $journal['target']['data_md5'] );

		sleep( 6 ); // Lock lifetime of the killed process is 5 s.
		$recovered = $this->run_cli( array( 'recover', $table_id ) );
		$this->assertSame( 'rolled_back', $recovered['json']['status'] ?? null, $recovered['stdout'] . $recovered['stderr'] );
		$this->assertUnchanged( $table_id, $before, $revisions );

		$this->assertSame( 'updated', $this->sync( $table_id, $this->fixture( 'new.csv', self::NEW_CSV ) )['status'] );
	}

	public function test_lost_connection_during_the_write_is_undone() {
		$table_id  = $this->create_table( self::ORIGINAL );
		$before    = $this->raw_state( $table_id );
		$revisions = $this->revision_count( $table_id );

		// wpdb reconnects and repeats WordPress's post UPDATE in autocommit mode, so the new table
		// data is committed on its own; TableRefresh must stop all further writes and restore.
		$run = $this->run_cli( array( 'sync', $table_id, $this->fixture( 'new.csv', self::NEW_CSV ) ), array( 'TABLEREFRESH_TEST_FAULT' => 'connection_lost_before_post_update' ) );
		$this->assertStringContainsString( 'TEST FAULT: connection', $run['stderr'] );
		$this->assertSame( 'tablerefresh_write_interrupted', $run['json']['code'] ?? null, $run['stdout'] . $run['stderr'] );
		$this->assertTrue( $run['json']['data']['tablerefresh_reverted']['reverted'] );
		$this->assertUnchanged( $table_id, $before, $revisions );
	}

	public function test_write_committed_outside_the_transaction_by_a_killed_process_is_recovered() {
		$table_id  = $this->create_table( self::ORIGINAL );
		$before    = $this->raw_state( $table_id );
		$revisions = $this->revision_count( $table_id );

		$run = $this->run_cli(
			array( 'sync', $table_id, $this->fixture( 'new.csv', self::NEW_CSV ) ),
			array(
				'TABLEREFRESH_TEST_FAULT'    => 'connection_lost_before_post_update_then_kill',
				'TABLEREFRESH_TEST_LOCK_TTL' => '5',
			)
		);
		$this->assertStringContainsString( 'TEST FAULT: SIGKILL', $run['stderr'] );

		// The repeated post UPDATE was committed on its own, before this update's baseline (which
		// the server rolled back with the transaction): new data, old visibility.
		$partial = $this->raw_state( $table_id );
		$this->assertNotSame( $before['post_content'], $partial['post_content'] );
		$this->assertSame( $before['_tablerefresh_sync_state'] ?? null, $partial['_tablerefresh_sync_state'] ?? null );
		$this->assertNotNull( Journal::read( $table_id ) );

		sleep( 6 ); // Lock lifetime of the killed process is 5 s.
		$recovered = $this->run_cli( array( 'recover', $table_id ) );
		$this->assertSame( 'restored', $recovered['json']['status'] ?? null, $recovered['stdout'] . $recovered['stderr'] );
		$this->assertUnchanged( $table_id, $before, $revisions );
	}

	public function test_lost_connection_without_transactions_is_undone() {
		$table_id  = $this->create_table( self::ORIGINAL );
		$before    = $this->raw_state( $table_id );
		$revisions = $this->revision_count( $table_id );

		$run = $this->run_cli(
			array( 'sync', $table_id, $this->fixture( 'new.csv', self::NEW_CSV ) ),
			array(
				'TABLEREFRESH_TEST_FAULT'           => 'connection_lost_before_post_update',
				'TABLEREFRESH_TEST_NO_TRANSACTIONS' => '1',
			)
		);
		$this->assertSame( 'tablerefresh_write_interrupted', $run['json']['code'] ?? null, $run['stdout'] . $run['stderr'] );
		$this->assertTrue( $run['json']['data']['tablerefresh_reverted']['reverted'] );
		$this->assertUnchanged( $table_id, $before, $revisions );
	}
}
