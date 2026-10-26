<?php
/**
 * Concurrent editing in the TablePress editor and synchronization.
 *
 * Human edits go through the real TablePress edit screen and its AJAX "Save Changes" request,
 * served by Apache with real login cookies. Concurrent saves run in separate PHP processes.
 *
 * @package TableRefresh
 */

use TableRefresh\Editor_Guard;
use TableRefresh\Journal;
use TableRefresh\Sync_State;
use TableRefresh\Table_Lock;
use TableRefresh\TablePress_Adapter;

/**
 * @covers \TableRefresh\Editor_Guard
 * @covers \TableRefresh\Table_Updater
 */
class EditorConflictTest extends Integration_Test_Case {

	const V1 = "Item,Price\nApple,1\nPear,2\n";
	const V2 = "Item,Price\nApple,1\nPear,3\n";

	/**
	 * Creates a table that is linked to a source (synced once) and returns [table ID, source URL].
	 *
	 * @return array{0: string, 1: string}
	 */
	private function synced_table(): array {
		$table_id = $this->create_table( array( array( 'initial' ) ) );
		$url      = $this->fixture( 'source.csv', self::V1 );
		$this->assertSame( 'updated', $this->sync( $table_id, $url )['status'] );
		return array( $table_id, $url );
	}

	/**
	 * Makes all editor sessions of a table look older than the session lifetime.
	 *
	 * @param string $table_id Table ID.
	 */
	private function expire_editor_sessions( string $table_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = '1' WHERE post_id = %d AND meta_key LIKE %s", TablePress_Adapter::get_post_id( $table_id ), $wpdb->esc_like( Editor_Guard::META_PREFIX ) . '%' ) );
	}

	private function assertConflict( $result, string $code ): void {
		$this->assertInstanceOf( WP_Error::class, $result, 'Expected a conflict.' );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		$this->assertTrue( $result->get_error_data()['conflict'] );
	}

	public function test_manual_edit_after_sync_is_not_overwritten() {
		list( $table_id, $url ) = $this->synced_table();
		$cookie                 = $this->login_cookie();

		// A user changes a cell in the TablePress editor and saves.
		$editor               = $this->open_editor( $table_id, $cookie );
		$editor['data'][1][1] = '99';
		$response             = $this->save_in_editor( $table_id, $editor, $cookie );
		$this->assertTrue( $response['success'], wp_json_encode( $response ) );
		$this->expire_editor_sessions( $table_id ); // The user closed the editor.

		$this->fixture( 'source.csv', self::V2 );
		$before = $this->raw_state( $table_id );
		$result = $this->sync( $table_id, $url );

		$this->assertConflict( $result, 'tablerefresh_conflict_modified' );
		$this->assertSame( 'admin', $result->get_error_data()['last_editor'] );
		$this->assertNotNull( $result->get_error_data()['last_synced'] );
		$this->assertSame( $before, $this->raw_state( $table_id ), 'The manual edit must stay untouched.' );
		$this->assertSame( '99', $this->load_table( $table_id )['data'][1][1] );
		$this->assertNull( Journal::read( $table_id ) );
		$this->assertFalse( Table_Lock::is_locked( $table_id ) );

		// Explicit overwrite replaces the manual edit and establishes a new baseline.
		$this->assertSame( 'updated', $this->sync( $table_id, $url, array( 'on_conflict' => 'overwrite' ) )['status'] );
		$this->assertSame( '3', $this->load_table( $table_id )['data'][2][1] );
		$this->assertSame( 'unchanged', $this->sync( $table_id, $url )['status'] );
	}

	public function test_open_editor_blocks_sync_until_the_session_expires() {
		list( $table_id, $url ) = $this->synced_table();
		$this->open_editor( $table_id, $this->login_cookie() );

		$this->fixture( 'source.csv', self::V2 );
		$result = $this->sync( $table_id, $url );
		$this->assertConflict( $result, 'tablerefresh_conflict_editing' );
		$editors = $result->get_error_data( 'tablerefresh_conflict_editing_users' )['editors'];
		$this->assertSame( 1, $editors[0]['user_id'] );
		$this->assertSame( 'Pear', $this->load_table( $table_id )['data'][2][0] );
		$this->assertSame( '2', $this->load_table( $table_id )['data'][2][1] );

		$this->expire_editor_sessions( $table_id );
		$this->assertSame( 'updated', $this->sync( $table_id, $url )['status'] );
	}

	public function test_own_editor_session_does_not_block_a_sync_started_by_that_user() {
		list( $table_id, $url ) = $this->synced_table();
		$cookie                 = $this->login_cookie();
		$editor                 = $this->open_editor( $table_id, $cookie ); // admin, e.g. right after creating the table.
		$this->fixture( 'source.csv', self::V2 );

		// Started by admin (Sync now): not blocked by admin's own session.
		wp_set_current_user( 1 );
		$result = $this->sync( $table_id, $url );
		wp_set_current_user( 0 );
		$this->assertSame( 'updated', $result['status'] ?? null, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$this->assertSame( '3', $this->load_table( $table_id )['data'][2][1] );

		// admin's older editor tab cannot overwrite the synchronized data.
		$editor['data'][1][1] = 'STALE';
		$response             = $this->save_in_editor( $table_id, $editor, $cookie );
		$this->assertFalse( $response['success'] );
		$this->assertSame( '3', $this->load_table( $table_id )['data'][2][1] );
		$this->assertSame( '1', $this->load_table( $table_id )['data'][1][1] );

		// Another user's open editor still blocks a sync started by admin.
		$editor_id = wp_insert_user(
			array(
				'user_login' => 'tr_editor_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'editor',
			)
		);
		try {
			$this->open_editor( $table_id, $this->login_cookie( $editor_id ) );
			$this->fixture( 'source.csv', self::V1 );
			wp_set_current_user( 1 );
			$result = $this->sync( $table_id, $url );
			wp_set_current_user( 0 );
			$this->assertConflict( $result, 'tablerefresh_conflict_editing' );
		} finally {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $editor_id );
		}
	}

	public function test_identical_manual_edit_is_adopted_without_conflict() {
		list( $table_id, $url ) = $this->synced_table();
		$cookie                 = $this->login_cookie();
		$editor                 = $this->open_editor( $table_id, $cookie );
		$editor['data'][2][1]   = '3'; // The user makes the same change that the source will make.
		$this->assertTrue( $this->save_in_editor( $table_id, $editor, $cookie )['success'] );

		$this->fixture( 'source.csv', self::V2 );
		$this->assertSame( 'unchanged', $this->sync( $table_id, $url )['status'] );
		$post_id = TablePress_Adapter::get_post_id( $table_id );
		$this->assertSame( Sync_State::data_hash( $this->load_table( $table_id )['data'] ), Sync_State::read( $post_id )['data_md5'], 'Baseline adopted.' );
	}

	public function test_stale_editor_cannot_overwrite_a_newer_sync() {
		list( $table_id, $url ) = $this->synced_table();
		$cookie                 = $this->login_cookie();
		$stale                  = $this->open_editor( $table_id, $cookie );

		// The source changes and is synchronized while the editor is still open.
		$this->fixture( 'source.csv', self::V2 );
		$this->assertSame( 'updated', $this->sync( $table_id, $url, array( 'on_conflict' => 'overwrite' ) )['status'] );
		$synced = $this->raw_state( $table_id );

		// The user edits the outdated copy in the browser and clicks "Save Changes".
		$stale['data'][1][0] = 'Apricot';
		$response            = $this->save_in_editor( $table_id, $stale, $cookie );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'synchronized this table after you opened it', $response['error_details'] );
		$this->assertSame( $synced, $this->raw_state( $table_id ), 'The stale save must not write anything.' );

		// After reloading the editor, saving works again.
		$fresh               = $this->open_editor( $table_id, $cookie );
		$fresh['data'][1][0] = 'Apricot';
		$this->assertTrue( $this->save_in_editor( $table_id, $fresh, $cookie )['success'] );
		$this->assertSame( 'Apricot', $this->load_table( $table_id )['data'][1][0] );
		$this->assertSame( '3', $this->load_table( $table_id )['data'][2][1], 'The fresh copy contained the synced data.' );
	}

	public function test_forged_save_request_cannot_refresh_the_editor_session() {
		list( $table_id, $url ) = $this->synced_table();
		$cookie                 = $this->login_cookie();
		$stale                  = $this->open_editor( $table_id, $cookie );
		$this->fixture( 'source.csv', self::V2 );
		$this->assertSame( 'updated', $this->sync( $table_id, $url, array( 'on_conflict' => 'overwrite' ) )['status'] );

		// A cross-site request in the user's browser, without a valid nonce.
		$forged = $this->http(
			'/wp-admin/admin-ajax.php',
			$cookie,
			array(
				'action'      => 'tablepress_save_table',
				'_ajax_nonce' => 'invalid',
				'tablepress'  => array( 'id' => $table_id ),
			)
		);
		$this->assertSame( '-1', trim( $forged ), 'TablePress rejects the forged request.' );

		$stale['data'][1][0] = 'Apricot';
		$this->assertFalse( $this->save_in_editor( $table_id, $stale, $cookie )['success'], 'The stale editor is still recognized.' );
	}

	public function test_editor_saved_while_the_source_was_downloading() {
		list( $table_id ) = $this->synced_table();
		$slow             = $this->fixture( 'slow.csv', self::V2, 'delay=4' );
		$cookie           = $this->login_cookie();

		$sync = $this->start_cli( array( 'sync', $table_id, $slow ), array( 'TABLEREFRESH_TEST_EDITOR_TTL' => '0' ) );
		sleep( 1 );
		$editor               = $this->open_editor( $table_id, $cookie );
		$editor['data'][1][1] = '42';
		$this->assertTrue( $this->save_in_editor( $table_id, $editor, $cookie )['success'] );

		$run = $this->wait_cli( $sync );
		$this->assertSame( 'tablerefresh_conflict_modified', $run['json']['code'], $run['stdout'] . $run['stderr'] );
		$this->assertSame( '42', $this->load_table( $table_id )['data'][1][1], 'The human edit survives.' );
	}

	public function test_save_between_load_and_write_aborts_the_sync() {
		list( $table_id, $url ) = $this->synced_table();
		$this->fixture( 'source.csv', self::V2 );

		$run = $this->run_cli( array( 'sync', $table_id, $url ), array( 'TABLEREFRESH_TEST_FAULT' => 'human_save_before_write' ) );
		$this->assertSame( 'tablerefresh_conflict_concurrent', $run['json']['code'], $run['stdout'] . $run['stderr'] );
		$this->assertArrayNotHasKey( 'tablerefresh_reverted', $run['json']['data'] ?? array(), 'Nothing was written, so no revert may be reported.' );

		$data = $this->load_table( $table_id )['data'];
		$this->assertSame( 'HUMAN-EDIT', $data[0][0], 'The concurrent save survives; nothing was restored over it.' );
		$this->assertSame( '2', $data[2][1], 'The sync wrote nothing.' );
		$this->assertNull( Journal::read( $table_id ) );
		$this->assertFalse( Table_Lock::is_locked( $table_id ) );
	}

	public function test_save_during_the_write_waits_and_is_reported_by_the_next_sync() {
		list( $table_id, $url ) = $this->synced_table();
		$this->fixture( 'source.csv', self::V2 );

		$run = $this->run_cli( array( 'sync', $table_id, $url ), array( 'TABLEREFRESH_TEST_FAULT' => 'human_save_during_write' ) );
		$this->assertSame( 'updated', $run['json']['status'], $run['stdout'] . $run['stderr'] );

		// The concurrent save waited for TableRefresh's row lock and was applied after the commit.
		$deadline = microtime( true ) + 15;
		do {
			usleep( 250000 );
			$data = $this->load_table( $table_id )['data'];
		} while ( 'HUMAN-EDIT' !== $data[0][0] && microtime( true ) < $deadline );
		$this->assertSame( 'HUMAN-EDIT', $data[0][0], 'The concurrent save was applied after the sync committed.' );

		// That save was based on the pre-sync copy, which TablePress allows. The next sync must
		// report it instead of silently overwriting it.
		$result = $this->sync( $table_id, $url );
		$this->assertConflict( $result, 'tablerefresh_conflict_modified' );
		$this->assertSame( 'HUMAN-EDIT', $this->load_table( $table_id )['data'][0][0] );
	}

	public function test_editor_save_without_tablerefresh_session_data_is_allowed() {
		// A user who opened the editor before TableRefresh was active has no recorded session.
		list( $table_id ) = $this->synced_table();
		$cookie           = $this->login_cookie();
		$editor           = $this->open_editor( $table_id, $cookie );
		global $wpdb;
		$wpdb->delete( $wpdb->postmeta, array( 'post_id' => TablePress_Adapter::get_post_id( $table_id ), 'meta_key' => Editor_Guard::META_PREFIX . '1' ) );
		$editor['data'][1][0] = 'Kiwi';
		$this->assertTrue( $this->save_in_editor( $table_id, $editor, $cookie )['success'] );
	}
}
