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
