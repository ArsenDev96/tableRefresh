<?php
/**
 * Guards that refuse to write when integrity cannot be guaranteed.
 *
 * @package TableRefresh
 */

use TableRefresh\Journal;
use TableRefresh\Sync_Service;
use TableRefresh\Sync_State;
use TableRefresh\Table_Updater;
use TableRefresh\TablePress_Adapter;

/**
 * @covers \TableRefresh\TablePress_Adapter::verify_post
 * @covers \TableRefresh\Table_Updater
 * @covers \TableRefresh\Journal
 */
class IntegrityGuardsTest extends Integration_Test_Case {

	public function test_table_mapped_to_a_foreign_post_is_refused() {
		$table_id = $this->create_table( array( array( 'a', 'b' ) ) );
		$foreign  = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => 'Not a table',
				'post_content' => '[["a","b"]]',
			)
		);
		$tables   = TablePress::$model_table->_debug_get_tables();
		$original = $tables['table_post'][ $table_id ];
		$tables['table_post'][ $table_id ] = $foreign;
		TablePress::$model_table->_debug_update_tables( $tables );
		try {
			$result = $this->sync( $table_id, $this->static_url( 'simple.csv' ) );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'tablerefresh_table_mapping_invalid', $result->get_error_code() );
			$this->assertSame( '[["a","b"]]', get_post( $foreign )->post_content );
		} finally {
			$tables['table_post'][ $table_id ] = $original;
			TablePress::$model_table->_debug_update_tables( $tables );
			wp_delete_post( $foreign, true );
		}
	}

	public function test_storage_engines_are_detected() {
		global $wpdb;
		$engines = Table_Updater::storage_engines();
		$this->assertSame( array( $wpdb->options, $wpdb->postmeta, $wpdb->posts ), $this->sorted_keys( $engines ) );
		foreach ( $engines as $engine ) {
			$this->assertSame( 'innodb', strtolower( $engine ) );
		}
	}

	public function test_non_atomic_updates_are_refused_unless_explicitly_allowed() {
		$table_id = $this->create_table( array( array( 'a' ) ) );
		$before   = $this->raw_state( $table_id );
		add_filter( 'tablerefresh_use_transactions', '__return_false' );
		try {
			$this->assertFailedUnchanged( $this->sync( $table_id, $this->static_url( 'simple.csv' ) ), 'tablerefresh_db_not_transactional', $table_id, $before );

			add_filter( 'tablerefresh_allow_non_atomic_updates', '__return_true' );
			$result = $this->sync( $table_id, $this->static_url( 'simple.csv' ) );
			$this->assertSame( 'updated', $result['status'] );
			$this->assertFalse( $result['atomic'], 'Must not claim atomicity.' );
		} finally {
			remove_filter( 'tablerefresh_use_transactions', '__return_false' );
			remove_filter( 'tablerefresh_allow_non_atomic_updates', '__return_true' );
		}
	}

	public function test_journal_of_another_owner_is_never_replaced_or_deleted() {
		$table_id = 'journal-test-' . wp_generate_password( 6, false );
		$this->assertTrue( Journal::write( $table_id, array( 'owner' => 'process-a', 'n' => 1 ) ) );
		$this->assertFalse( Journal::write( $table_id, array( 'owner' => 'process-b', 'n' => 2 ) ) );
		$this->assertSame( 'process-a', Journal::read( $table_id )['owner'] );

		Journal::delete( $table_id, 'process-b' );
		$this->assertNotNull( Journal::read( $table_id ), 'Only the owner may delete its journal.' );
		Journal::delete( $table_id, 'process-a' );
		$this->assertNull( Journal::read( $table_id ) );
		$this->assertFalse( Journal::write( $table_id, array( 'n' => 3 ) ), 'Entries need an owner.' );
	}

	public function test_journal_without_snapshot_is_discarded_before_sync() {
		$table_id = $this->create_table( array( array( 'a' ) ) );
		$this->assertTrue( Journal::write( $table_id, array( 'owner' => 'dead-process', 'post_id' => TablePress_Adapter::get_post_id( $table_id ) ) ) );
		$result = $this->sync( $table_id, $this->static_url( 'simple.csv' ) );
		$this->assertSame( 'discarded', $result['recovery'] );
		$this->assertSame( 'updated', $result['status'] );
	}

	public function test_outdated_journal_never_reverts_later_changes() {
		$table_id = $this->create_table( array( array( 'original' ) ) );
		$s0       = $this->raw_state( $table_id );
		$this->assertSame( 'updated', $this->sync( $table_id, $this->fixture( 'v1.csv', "v1\n" ) )['status'] );
		$s1 = $this->raw_state( $table_id );

		// Journal of an update that died before writing anything; the table changed afterwards.
		Journal::write(
			$table_id,
			array(
				'version'  => 2,
				'owner'    => 'dead-process',
				'post_id'  => TablePress_Adapter::get_post_id( $table_id ),
				'snapshot' => $s0,
				'target'   => array(
					'data_md5'   => Sync_State::data_hash( array( array( 'never written' ) ) ),
					'visibility' => array( 'rows' => array( 1 ), 'columns' => array( 1 ) ),
					'state'      => '{"data_md5":"x","synced_at":"1.000000"}',
				),
			)
		);
		$this->assertSame( 'discarded', ( new Sync_Service() )->recover( $table_id )['status'] );
		$this->assertSame( $s1, $this->raw_state( $table_id ), 'Later data must not be reverted.' );
	}

	public function test_partial_write_overwritten_by_someone_else_is_not_reverted() {
		$table_id    = $this->create_table( array( array( 'original' ) ) );
		$post_id     = TablePress_Adapter::get_post_id( $table_id );
		$s0          = $this->raw_state( $table_id );
		$fingerprint = Sync_State::encode( Sync_State::data_hash( array( array( 'synced' ) ) ) );

		// A non-atomic update died after writing its baseline; then a human saved other data.
		TablePress_Adapter::write_meta( $post_id, Sync_State::META_KEY, $fingerprint );
		$table         = $this->load_table( $table_id );
		$table['data'] = array( array( 'human' ) );
		wp_set_current_user( 1 );
		TablePress::$model_table->save( $table );
		wp_set_current_user( 0 );
		$changed = $this->raw_state( $table_id );

		Journal::write(
			$table_id,
			array(
				'version'  => 2,
				'owner'    => 'dead-process',
				'post_id'  => $post_id,
				'snapshot' => $s0,
				'target'   => array(
					'data_md5'   => Sync_State::data_hash( array( array( 'synced' ) ) ),
					'visibility' => array( 'rows' => array( 1 ), 'columns' => array( 1 ) ),
					'state'      => $fingerprint,
				),
			)
		);
		$this->assertSame( 'discarded', ( new Sync_Service() )->recover( $table_id )['status'] );
		$this->assertSame( $changed, $this->raw_state( $table_id ) );
		$this->assertSame( array( array( 'human' ) ), $this->load_table( $table_id )['data'] );
	}

	/**
	 * Sorted keys of an array.
	 *
	 * @param array<string, mixed> $values Values.
	 * @return string[]
	 */
	private function sorted_keys( array $values ): array {
		$keys = array_keys( $values );
		sort( $keys );
		return $keys;
	}
}
