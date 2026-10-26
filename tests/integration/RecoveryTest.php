<?php
/**
 * Failed and interrupted writes: rollback, restore, and recovery.
 *
 * Fault injection happens in separate `wp` processes (see dev/mu-plugins), at the most
 * dangerous point: after TablePress wrote the table post, before it wrote the post meta.
 *
 * @package TableRefresh
 */

use TableRefresh\Journal;
use TableRefresh\Sync_Service;
use TableRefresh\Table_Lock;
use TableRefresh\TablePress_Adapter;

/**
 * @covers \TableRefresh\Table_Updater
 * @covers \TableRefresh\Journal
 */
class RecoveryTest extends Integration_Test_Case {

	const ORIGINAL = array( array( 'h1', 'h2' ), array( 'o1', 'o2' ) );
	const NEW_CSV  = "n1,n2,n3\nv1,v2,v3\nw1,w2,w3\n"; // Different size, so visibility meta must change too.

	private function prepare_case(): array {
		$table_id = $this->create_table( self::ORIGINAL );
		return array( $table_id, $this->fixture( 'new.csv', self::NEW_CSV ), $this->raw_state( $table_id ), $this->revision_count( $table_id ) );
	}

	private function assertOriginalState( string $table_id, array $before, int $revisions, bool $journal_expected = false ): void {
		$this->assertSame( $before, $this->raw_state( $table_id ), 'Table must be byte-for-byte unchanged.' );
		$this->assertSame( self::ORIGINAL, $this->load_table( $table_id )['data'] );
		$this->assertSame( $revisions, $this->revision_count( $table_id ), 'No revision of the failed content may remain.' );
		$this->assertSame( $journal_expected, null !== Journal::read( $table_id ) );
	}

	public function test_revert_that_cannot_be_confirmed_is_reported_and_keeps_the_backup() {
		list( $table_id, $url, $before, $revisions ) = $this->prepare_case();
		$run = $this->run_cli( array( 'sync', $table_id, $url ), array( 'TABLEREFRESH_TEST_FAULT' => 'fail_visibility_meta_then_unreadable' ) );
		$this->assertStringContainsString( 'TEST FAULT: unreadable', $run['stderr'] );
		$this->assertSame( 'tablerefresh_table_save_failed', $run['json']['code'] ?? null, $run['stdout'] . $run['stderr'] );
		$this->assertFalse( $run['json']['data']['tablerefresh_reverted']['reverted'], 'The table could not be read, so no revert may be claimed.' );
		$this->assertContains( 'tablerefresh_revert_failed', $run['json']['codes'] );
		$this->assertNotNull( Journal::read( $table_id ), 'The backup is kept.' );
		$this->assertFalse( Table_Lock::is_locked( $table_id ) );

		$recovered = $this->run_cli( array( 'recover', $table_id ) );
		$this->assertSame( 'rolled_back', $recovered['json']['status'] ?? null, $recovered['stdout'] . $recovered['stderr'] );
		$this->assertOriginalState( $table_id, $before, $revisions );
	}

	public function test_unconfirmed_revert_without_transactions_is_restored_by_recovery() {
		list( $table_id, $url, $before, $revisions ) = $this->prepare_case();
		$run = $this->run_cli(
			array( 'sync', $table_id, $url ),
			array(
				'TABLEREFRESH_TEST_FAULT'           => 'fail_visibility_meta_then_unreadable',
				'TABLEREFRESH_TEST_NO_TRANSACTIONS' => '1',
			)
		);
		$this->assertFalse( $run['json']['data']['tablerefresh_reverted']['reverted'] ?? null, $run['stdout'] . $run['stderr'] );
		$this->assertNotSame( $before['post_content'], $this->raw_state( $table_id )['post_content'], 'The partial write is still there.' );
		$this->assertNotNull( Journal::read( $table_id ) );

		$recovered = $this->run_cli( array( 'recover', $table_id ) );
		$this->assertSame( 'restored', $recovered['json']['status'] ?? null, $recovered['stdout'] . $recovered['stderr'] );
		$this->assertOriginalState( $table_id, $before, $revisions );
	}

	/**
	 * @dataProvider unreadable_parts
	 */
	public function test_recovery_keeps_a_backup_it_could_not_check( string $fault ) {
		list( $table_id, $url, $before, $revisions ) = $this->prepare_case();
		$this->run_cli(
			array( 'sync', $table_id, $url ),
			array(
				'TABLEREFRESH_TEST_FAULT'           => 'kill_after_post_update',
				'TABLEREFRESH_TEST_NO_TRANSACTIONS' => '1',
				'TABLEREFRESH_TEST_LOCK_TTL'        => '2',
			)
		);
		$partial = $this->raw_state( $table_id );
		$this->assertNotSame( $before['post_content'], $partial['post_content'], 'A partial write of the killed process.' );
		$this->assertNotNull( Journal::read( $table_id ) );
		sleep( 3 ); // Lock lifetime of the killed process.

		$run = $this->run_cli( array( 'recover', $table_id ), array( 'TABLEREFRESH_TEST_FAULT' => $fault ) );
		$this->assertStringContainsString( 'TEST FAULT: unreadable', $run['stderr'] );
		$this->assertSame( 'tablerefresh_recovery_failed', $run['json']['code'] ?? null, $run['stdout'] . $run['stderr'] );
		$this->assertNotNull( Journal::read( $table_id ), 'A backup that could not be checked must not be discarded.' );
		$this->assertSame( $partial, $this->raw_state( $table_id ) );

		$recovered = $this->run_cli( array( 'recover', $table_id ) );
		$this->assertSame( 'restored', $recovered['json']['status'] ?? null, $recovered['stdout'] . $recovered['stderr'] );
		$this->assertOriginalState( $table_id, $before, $revisions );
	}

	public function unreadable_parts(): array {
		return array(
			'table post' => array( 'unreadable_table' ),
			'table meta' => array( 'unreadable_table_meta' ),
		);
	}

	public function test_exception_during_save_is_rolled_back() {
		list( $table_id, $url, $before, $revisions ) = $this->prepare_case();
		$run = $this->run_cli( array( 'sync', $table_id, $url ), array( 'TABLEREFRESH_TEST_FAULT' => 'throw_after_post_update' ) );
		$this->assertSame( 1, $run['exit'], $run['stdout'] . $run['stderr'] );
		$this->assertSame( 'tablerefresh_table_save_failed', $run['json']['code'] );
		$this->assertOriginalState( $table_id, $before, $revisions );
		$this->assertFalse( Table_Lock::is_locked( $table_id ) );
	}

	public function test_failed_meta_write_is_rolled_back_with_transactions() {
		list( $table_id, $url, $before, $revisions ) = $this->prepare_case();
		$run = $this->run_cli( array( 'sync', $table_id, $url ), array( 'TABLEREFRESH_TEST_FAULT' => 'fail_visibility_meta' ) );
		$this->assertSame( 'tablerefresh_table_save_failed', $run['json']['code'], $run['stdout'] . $run['stderr'] );
		$this->assertContains( 'table_save_update_table_visibility_failed', $run['json']['data']['tablerefresh_table_save_failed'] ?? array(), 'TablePress itself reported the failure.' );
		$this->assertOriginalState( $table_id, $before, $revisions );
	}

	public function test_failed_meta_write_is_restored_without_transactions() {
		list( $table_id, $url, $before, $revisions ) = $this->prepare_case();
		$run = $this->run_cli(
			array( 'sync', $table_id, $url ),
			array(
				'TABLEREFRESH_TEST_FAULT'           => 'fail_visibility_meta',
				'TABLEREFRESH_TEST_NO_TRANSACTIONS' => '1',
			)
		);
		$this->assertSame( 'tablerefresh_table_save_failed', $run['json']['code'], $run['stdout'] . $run['stderr'] );
		$this->assertTrue( $run['json']['data']['tablerefresh_reverted']['reverted'] );
		$this->assertOriginalState( $table_id, $before, $revisions );
	}

	public function test_exit_during_save_is_undone_on_shutdown() {
		list( $table_id, $url, $before, $revisions ) = $this->prepare_case();
		$run = $this->run_cli( array( 'sync', $table_id, $url ), array( 'TABLEREFRESH_TEST_FAULT' => 'exit_after_post_update' ) );
		$this->assertSame( 3, $run['exit'], $run['stdout'] . $run['stderr'] );
		$this->assertStringContainsString( 'TEST FAULT: exit', $run['stderr'] );
		$this->assertOriginalState( $table_id, $before, $revisions );
		$this->assertFalse( Table_Lock::is_locked( $table_id ), 'Shutdown handler must release the lock.' );
	}

	public function test_exit_during_save_without_transactions_is_undone_on_shutdown() {
		list( $table_id, $url, $before, $revisions ) = $this->prepare_case();
		$run = $this->run_cli(
			array( 'sync', $table_id, $url ),
			array(
				'TABLEREFRESH_TEST_FAULT'           => 'exit_after_post_update',
				'TABLEREFRESH_TEST_NO_TRANSACTIONS' => '1',
			)
		);
		$this->assertSame( 3, $run['exit'], $run['stdout'] . $run['stderr'] );
		$this->assertOriginalState( $table_id, $before, $revisions );
	}

	public function test_killed_process_is_rolled_back_by_database_and_recovered_after_lock_expiry() {
		list( $table_id, $url, $before, $revisions ) = $this->prepare_case();
		$run = $this->run_cli(
			array( 'sync', $table_id, $url ),
			array(
				'TABLEREFRESH_TEST_FAULT'    => 'kill_after_post_update',
				'TABLEREFRESH_TEST_LOCK_TTL' => '5',
			)
		);
		$this->assertStringContainsString( 'TEST FAULT: SIGKILL', $run['stderr'] );
		$this->assertNotSame( 0, $run['exit'] );
		$this->assertNull( $run['json'], 'The process must not have printed a result.' );

		// SIGKILL: no PHP code ran afterwards. InnoDB discarded the open transaction.
		$this->assertOriginalState( $table_id, $before, $revisions, true );
		$this->assertTrue( Table_Lock::is_locked( $table_id ), 'The dead process still holds the lock.' );

		$blocked = $this->sync( $table_id, $url );
		$this->assertInstanceOf( WP_Error::class, $blocked );
		$this->assertSame( 'tablerefresh_locked', $blocked->get_error_code() );

		sleep( 6 ); // Lock lifetime of the killed process is 5 s.
		$result = $this->sync( $table_id, $url );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['took_over'] );
		$this->assertSame( 'rolled_back', $result['recovery'] );
		$this->assertSame( 'updated', $result['status'] );
		$this->assertSame( array( array( 'n1', 'n2', 'n3' ), array( 'v1', 'v2', 'v3' ), array( 'w1', 'w2', 'w3' ) ), $this->load_table( $table_id )['data'] );
		$this->assertNull( Journal::read( $table_id ) );
	}

	public function test_killed_process_without_transactions_leaves_partial_write_that_is_restored() {
		list( $table_id, $url, $before ) = $this->prepare_case();
		$run = $this->run_cli(
			array( 'sync', $table_id, $url ),
			array(
				'TABLEREFRESH_TEST_FAULT'           => 'kill_after_post_update',
				'TABLEREFRESH_TEST_NO_TRANSACTIONS' => '1',
				'TABLEREFRESH_TEST_LOCK_TTL'        => '5',
			)
		);
		$this->assertStringContainsString( 'TEST FAULT: SIGKILL', $run['stderr'] );

		// This is the hazard the journal exists for: data written, visibility meta not.
		$partial = $this->raw_state( $table_id );
		$this->assertNotSame( $before['post_content'], $partial['post_content'] );
		$this->assertSame( $before['_tablepress_table_visibility'], $partial['_tablepress_table_visibility'] );
		$table = $this->load_table( $table_id );
		$this->assertNotSame( count( $table['data'] ), count( $table['visibility']['rows'] ), 'Table is inconsistent before recovery.' );
		$this->assertNotNull( Journal::read( $table_id ) );

		$locked = $this->run_cli( array( 'recover', $table_id ) );
		$this->assertSame( 'tablerefresh_locked', $locked['json']['code'] );

		sleep( 6 ); // Lock lifetime of the killed process is 5 s.
		$recovered = $this->run_cli( array( 'recover', $table_id ) );
		$this->assertSame( 0, $recovered['exit'], $recovered['stdout'] . $recovered['stderr'] );
		$this->assertSame( 'restored', $recovered['json']['status'] );
		$this->assertSame( $before, $this->raw_state( $table_id ), 'Restored byte-for-byte, including post_modified.' );
		$this->assertNull( Journal::read( $table_id ) );

		$this->assertSame( 'none', ( new Sync_Service() )->recover( $table_id )['status'] );
		$final = $this->sync( $table_id, $url );
		$this->assertIsArray( $final, is_wp_error( $final ) ? $final->get_error_code() . ': ' . $final->get_error_message() : '' );
		$this->assertSame( 'updated', $final['status'] );
	}

	public function test_journal_of_a_completed_write_is_confirmed_not_reverted() {
		list( $table_id, $url, $before ) = $this->prepare_case();
		$this->assertSame( 'updated', $this->sync( $table_id, $url )['status'] );
		$table = $this->load_table( $table_id );

		// Simulate a crash after COMMIT but before the journal was deleted.
		Journal::write(
			$table_id,
			array(
				'version'  => 1,
				'owner'    => 'crashed-process',
				'table_id' => $table_id,
				'post_id'  => TablePress_Adapter::get_post_id( $table_id ),
				'snapshot' => $before,
				'target'   => array(
					'data_md5'   => md5( wp_json_encode( $table['data'] ) ),
					'visibility' => $table['visibility'],
				),
			)
		);
		$this->assertSame( 'completed', ( new Sync_Service() )->recover( $table_id )['status'] );
		$this->assertSame( $table['data'], $this->load_table( $table_id )['data'] );
		$this->assertNull( Journal::read( $table_id ) );
	}

	public function test_journal_of_deleted_table_is_discarded() {
		list( $table_id, , $before ) = $this->prepare_case();
		Journal::write( $table_id, array( 'owner' => 'crashed-process', 'post_id' => TablePress_Adapter::get_post_id( $table_id ), 'snapshot' => $before ) );
		TablePress::$model_table->delete( $table_id );
		$this->assertSame( 'discarded', ( new Sync_Service() )->recover( $table_id )['status'] );
		$this->assertNull( Journal::read( $table_id ) );
	}

	public function test_verification_failure_is_rolled_back() {
		list( $table_id, $url, $before, $revisions ) = $this->prepare_case();
		$tamper = static fn( $content ) => str_replace( 'v1', 'TAMPERED', $content );
		add_filter( 'content_save_pre', $tamper );
		try {
			$result = $this->sync( $table_id, $url );
		} finally {
			remove_filter( 'content_save_pre', $tamper );
		}
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'tablerefresh_verify_failed', $result->get_error_code() );
		$this->assertOriginalState( $table_id, $before, $revisions );
	}
}
