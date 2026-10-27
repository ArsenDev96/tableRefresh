<?php
/**
 * The Tools > TableRefresh screen and its form handlers, through Apache with real login cookies.
 *
 * Nonces are read from the rendered page, as a browser would submit them.
 *
 * @package TableRefresh
 */

use TableRefresh\Scheduler;
use TableRefresh\Sync_Config;
use TableRefresh\Sync_Log;
use TableRefresh\Table_Lock;
use TableRefresh\TablePress_Adapter;

/**
 * @covers \TableRefresh\Admin
 */
class AdminScreenTest extends Integration_Test_Case {

	const V1 = "Item,Price\nApple,1\nPear,2\n";
	const V2 = "Item,Price\nApple,1\nPear,3\n";

	/**
	 * Users created by the current test.
	 *
	 * @var int[]
	 */
	private array $users = array();

	protected function tearDown(): void {
		foreach ( Sync_Config::all() as $post_id => $config ) {
			if ( '' === TablePress_Adapter::get_table_id( $post_id ) || false !== strpos( $config['url'], 'fixtures' ) ) {
				Sync_Config::delete( $post_id );
				wp_clear_scheduled_hook( Scheduler::HOOK, array( $post_id ) );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->users as $user_id ) {
			wp_delete_user( $user_id );
		}
		parent::tearDown();
	}

	/**
	 * Sends a request to Apache without following redirects.
	 *
	 * @param string                    $path   Path.
	 * @param string                    $cookie Cookie header.
	 * @param array<string, mixed>|null $post   Form fields.
	 * @return array{status: int, location: string, body: string}
	 */
	private function request( string $path, string $cookie, ?array $post = null ): array {
		$options = array(
			'method'          => null === $post ? 'GET' : 'POST',
			'header'          => "Host: localhost:8089\r\nCookie: {$cookie}\r\n" . ( null === $post ? '' : "Content-Type: application/x-www-form-urlencoded\r\n" ),
			'timeout'         => 60,
			'ignore_errors'   => true,
			'follow_location' => 0,
		);
		if ( null !== $post ) {
			$options['content'] = http_build_query( $post );
		}
		$body    = (string) file_get_contents( 'http://wordpress' . $path, false, stream_context_create( array( 'http' => $options ) ) );
		$headers = isset( $http_response_header ) ? $http_response_header : array();
		preg_match( '#^HTTP/\S+ (\d+)#', (string) ( $headers[0] ?? '' ), $status );
		$location = '';
		foreach ( $headers as $header ) {
			if ( 0 === stripos( $header, 'Location:' ) ) {
				$location = trim( substr( $header, 9 ) );
			}
		}
		return array(
			'status'   => (int) ( $status[1] ?? 0 ),
			'location' => $location,
			'body'     => $body,
		);
	}

	private function page( string $cookie, string $query = '' ): string {
		$response = $this->request( '/wp-admin/tools.php?page=tablerefresh' . $query, $cookie );
		$this->assertSame( 200, $response['status'], substr( wp_strip_all_tags( $response['body'] ), 0, 300 ) );
		return $response['body'];
	}

	/**
	 * Fields of the form whose hidden "action" is $action (and "table" is $post_id).
	 *
	 * @param string   $html    Page.
	 * @param string   $action  admin-post action.
	 * @param int|null $post_id Table post ID.
	 * @return array<string, string>
	 */
	private function form_fields( string $html, string $action, ?int $post_id = null ): array {
		$this->assertSame( 1, preg_match_all( '#<form method="post"[^>]*>(.*?)</form>#s', $html, $forms ) > 0 ? 1 : 0, 'No forms on the page.' );
		foreach ( $forms[1] as $form ) {
			if ( false === strpos( $form, 'name="action" value="' . $action . '"' ) || ( null !== $post_id && false === strpos( $form, 'name="table" value="' . $post_id . '"' ) ) ) {
				continue;
			}
			preg_match_all( '#<input type="hidden"[^>]*name="([^"]+)" value="([^"]*)"#', $form, $inputs, PREG_SET_ORDER );
			$fields = array();
			foreach ( $inputs as $input ) {
				$fields[ $input[1] ] = html_entity_decode( $input[2] );
			}
			return $fields;
		}
		$this->fail( "Form {$action} not found." );
	}

	private function submit( string $cookie, array $fields ): array {
		$response = $this->request( '/wp-admin/admin-post.php', $cookie, $fields );
		wp_cache_flush_runtime(); // Apache changed the database; drop only this process's copies.
		return $response;
	}

	/**
	 * A valid nonce of another user's session, as that user's own browser would hold.
	 *
	 * @param int    $user_id User ID.
	 * @param string $cookie  Cookie header from login_cookie() for that user.
	 * @param string $action  Nonce action.
	 * @return string
	 */
	private function nonce_for( int $user_id, string $cookie, string $action ): string {
		preg_match( '/' . LOGGED_IN_COOKIE . '=([^;]+)/', $cookie, $match );
		$_COOKIE[ LOGGED_IN_COOKIE ] = rawurldecode( $match[1] );
		wp_set_current_user( $user_id );
		$nonce = wp_create_nonce( $action );
		wp_set_current_user( 0 );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		return $nonce;
	}

	private function user( string $role ): int {
		$user_id       = wp_insert_user(
			array(
				'user_login' => 'tr_' . $role . '_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => $role,
			)
		);
		$this->users[] = $user_id;
		return $user_id;
	}

	/**
	 * Sets up a table through the screen (the "Save and sync now" form).
	 *
	 * @return array{0: string, 1: int, 2: string, 3: string} Table ID, post ID, URL, cookie.
	 */
	private function set_up_through_screen( string $csv = self::V1 ): array {
		$table_id = $this->create_table( array( array( 'initial' ) ), array( 'name' => 'Prices' ) );
		$url      = $this->fixture( 'source.csv', $csv );
		$cookie   = $this->login_cookie();
		$fields   = $this->form_fields( $this->page( $cookie, '&action=add' ), 'tablerefresh_save' );

		$response = $this->submit(
			$cookie,
			$fields + array(
				'table_id'        => $table_id,
				'url'             => $url,
				'frequency'       => 'daily',
				'enabled'         => '1',
				'confirm_replace' => '1',
			)
		);
		$this->assertSame( 302, $response['status'], $response['body'] );
		return array( $table_id, TablePress_Adapter::get_post_id( $table_id ), $url, $cookie );
	}

	public function test_setup_saves_schedules_and_runs_the_first_sync() {
		list( $table_id, $post_id, $url, $cookie ) = $this->set_up_through_screen();

		$config = Sync_Config::get( $post_id );
		$this->assertSame( $url, $config['url'] );
		$this->assertSame( 'daily', $config['frequency'] );
		$this->assertSame( 1, Scheduler::count_events( $post_id ) );
		$this->assertSame( 'Pear', $this->load_table( $table_id )['data'][2][0] );
		$this->assertSame( 'setup', Sync_Log::last( $post_id )['trigger'] );

		$html = $this->page( $cookie );
		$this->assertStringContainsString( 'Prices: The table was updated: 3 rows, 2 columns.', $html, 'Result notice.' );
		$this->assertStringContainsString( 'Once a day', $html );
		$this->assertStringContainsString( '>fixtures<', $html, 'The list shows the host of the source.' );
		$this->assertStringNotContainsString( $url, $html, 'The list does not show the full source URL.' );
		$this->assertStringNotContainsString( 'Prices: The table was updated', $this->page( $cookie ), 'The notice is shown once.' );
	}

	public function test_setup_requires_confirmation_and_a_valid_url() {
		$table_id = $this->create_table( array( array( 'keep me' ) ) );
		$before   = $this->raw_state( $table_id );
		$post_id  = TablePress_Adapter::get_post_id( $table_id );
		$cookie   = $this->login_cookie();
		$fields   = $this->form_fields( $this->page( $cookie, '&action=add' ), 'tablerefresh_save' );
		$values   = array(
			'table_id'  => $table_id,
			'url'       => $this->fixture( 'source.csv', self::V1 ),
			'frequency' => 'daily',
			'enabled'   => '1',
		);

		$this->submit( $cookie, $fields + $values ); // Without confirm_replace.
		$html = $this->page( $cookie, '&action=add' );
		$this->assertStringContainsString( 'Confirm that the current content of the table may be replaced', $html );
		$this->assertStringContainsString( '<h1>Set up table sync</h1>', $html );
		$this->submit( $cookie, $fields + array_merge( $values, array( 'url' => 'ftp://example.com/x.csv', 'confirm_replace' => '1' ) ) );
		$html = $this->page( $cookie, '&action=add' );
		$this->assertStringContainsString( 'Enter a complete web address', $html );
		$this->assertStringContainsString( 'value="ftp://example.com/x.csv"', $html, 'The form keeps the entered value.' );

		$this->assertNull( Sync_Config::get( $post_id ) );
		$this->assertSame( $before, $this->raw_state( $table_id ), 'Nothing was written.' );
	}

	public function test_sync_now_shows_the_result_and_refuses_a_busy_table() {
		list( $table_id, $post_id, , $cookie ) = $this->set_up_through_screen();
		$this->fixture( 'source.csv', self::V2 );

		$this->submit( $cookie, $this->form_fields( $this->page( $cookie ), 'tablerefresh_sync', $post_id ) );
		$this->assertSame( '3', $this->load_table( $table_id )['data'][2][1] );
		$this->assertSame( 'manual', Sync_Log::last( $post_id )['trigger'] );
		$this->assertStringContainsString( 'The table was updated', $this->page( $cookie ) );

		$this->submit( $cookie, $this->form_fields( $this->page( $cookie ), 'tablerefresh_sync', $post_id ) );
		$this->assertStringContainsString( 'The source has not changed', $this->page( $cookie ) );

		$lock = Table_Lock::acquire( $table_id );
		try {
			$this->submit( $cookie, $this->form_fields( $this->page( $cookie ), 'tablerefresh_sync', $post_id ) );
		} finally {
			$lock->release();
		}
		$this->assertStringContainsString( 'being synchronized right now', $this->page( $cookie ) );
		$this->assertCount( 3, Sync_Log::get( $post_id )['entries'], 'The refused attempt is not recorded.' );
	}

	public function test_requests_without_valid_nonce_or_permission_are_rejected() {
		list( $table_id, $post_id, , $cookie ) = $this->set_up_through_screen();
		$this->fixture( 'source.csv', self::V2 );
		$fields = $this->form_fields( $this->page( $cookie ), 'tablerefresh_sync', $post_id );
		$before = $this->raw_state( $table_id );

		// Forged request (CSRF): no or wrong nonce.
		foreach ( array( '', 'deadbeef00' ) as $nonce ) {
			$response = $this->submit( $cookie, array_merge( $fields, array( '_wpnonce' => $nonce ) ) );
			$this->assertSame( 403, $response['status'] );
		}
		// A nonce for another table does not work for this one.
		$other_id   = $this->create_table( array( array( 'x' ) ) );
		$other_post = TablePress_Adapter::get_post_id( $other_id );
		$response   = $this->submit( $cookie, array_merge( $fields, array( 'table' => (string) $other_post ) ) );
		$this->assertSame( 403, $response['status'] );

		// An editor can edit TablePress tables but cannot use TableRefresh, even with a valid nonce
		// of their own session. The same holds for an administrator who may not edit TablePress tables.
		$editor_id = $this->user( 'editor' );
		$admin_id  = $this->user( 'administrator' );
		( new WP_User( $admin_id ) )->add_cap( 'tablepress_edit_tables', false );
		foreach ( array( $editor_id, $admin_id ) as $user_id ) {
			$user_cookie = $this->login_cookie( $user_id );
			if ( $user_id === $editor_id ) {
				$this->assertNotSame( 200, $this->request( '/wp-admin/tools.php?page=tablerefresh', $user_cookie )['status'] );
			} else {
				$this->assertStringNotContainsString( 'name="table" value="' . $post_id . '"', $this->page( $user_cookie ), 'The table is not listed.' );
				$this->assertStringNotContainsString( 'Prices', $this->page( $user_cookie, '&action=add' ) );
			}
			foreach ( array( 'sync', 'remove', 'replace', 'keep' ) as $action ) {
				$response = $this->submit(
					$user_cookie,
					array(
						'action'          => 'tablerefresh_' . $action,
						'table'           => (string) $post_id,
						'confirm_replace' => '1',
						'confirm_remove'  => '1',
						'_wpnonce'        => $this->nonce_for( $user_id, $user_cookie, 'tablerefresh_' . $action . '_' . $post_id ),
					)
				);
				$this->assertSame( 403, $response['status'], "{$action} by user {$user_id}" );
				$this->assertStringContainsString( 'not allowed to manage the automatic sync', $response['body'], 'Rejected by the permission check, not the nonce.' );
			}
		}

		$this->assertSame( $before, $this->raw_state( $table_id ), 'Nothing was synchronized.' );
		$this->assertNotNull( Sync_Config::get( $post_id ), 'Nothing was removed.' );
	}

	public function test_conflict_is_shown_and_resolved_only_on_explicit_request() {
		list( $table_id, $post_id, , $cookie ) = $this->set_up_through_screen();
		$table               = $this->load_table( $table_id );
		$table['data'][1][1] = 'HUMAN';
		wp_set_current_user( 1 );
		TablePress::$model_table->save( $table );
		wp_set_current_user( 0 );
		$this->fixture( 'source.csv', self::V2 );

		do_action( Scheduler::HOOK, $post_id );
		$this->assertSame( Sync_Config::ATTENTION_CONFLICT, Sync_Config::get( $post_id )['attention'] );
		$this->assertStringContainsString( 'automatic sync is paused for 1 table', $this->request( '/wp-admin/index.php', $cookie )['body'], 'Dashboard notice.' );
		$list = $this->page( $cookie );
		$this->assertStringContainsString( 'Review changes', $list );
		$this->assertStringNotContainsString( 'name="action" value="tablerefresh_sync"><input type="hidden" name="table" value="' . $post_id . '"', $list, 'No "Sync now" while a decision is pending.' );
		$edit = $this->page( $cookie, '&action=edit&table=' . $post_id );
		$this->assertStringContainsString( 'This table was changed in TablePress', $edit );
		$this->assertStringContainsString( '<h1>Settings and history: Prices (ID ' . $table_id . ')</h1>', $edit );

		// Replace without the confirmation checkbox: refused.
		$replace = $this->form_fields( $edit, 'tablerefresh_replace', $post_id );
		$this->submit( $cookie, $replace );
		$this->assertSame( 'HUMAN', $this->load_table( $table_id )['data'][1][1] );

		// Keep the changes: automatic sync is turned off, the edit stays.
		$this->submit( $cookie, $this->form_fields( $edit, 'tablerefresh_keep', $post_id ) );
		$config = Sync_Config::get( $post_id );
		$this->assertFalse( $config['enabled'] );
		$this->assertSame( '', $config['attention'] );
		$this->assertSame( 0, Scheduler::count_events( $post_id ) );
		$this->assertSame( 'HUMAN', $this->load_table( $table_id )['data'][1][1] );

		// "Sync now" still refuses; replacing with confirmation applies the source.
		$this->submit( $cookie, $this->form_fields( $this->page( $cookie ), 'tablerefresh_sync', $post_id ) );
		$this->assertSame( 'HUMAN', $this->load_table( $table_id )['data'][1][1] );
		$this->submit( $cookie, $this->form_fields( $this->page( $cookie, '&action=edit&table=' . $post_id ), 'tablerefresh_replace', $post_id ) + array( 'confirm_replace' => '1' ) );
		$this->assertSame( '1', $this->load_table( $table_id )['data'][1][1] );
		$this->assertSame( '3', $this->load_table( $table_id )['data'][2][1] );
		$this->assertSame( 'replace', Sync_Log::last( $post_id )['trigger'] );
	}

	public function test_edit_changes_the_schedule_and_remove_keeps_the_table() {
		list( $table_id, $post_id, $url, $cookie ) = $this->set_up_through_screen();
		$edit                                      = $this->page( $cookie, '&action=edit&table=' . $post_id );
		$this->assertStringNotContainsString( 'name="confirm_replace"', $edit, 'Already synced: no replacement confirmation.' );
		$this->assertStringContainsString( 'Recent syncs', $edit );

		$this->submit(
			$cookie,
			$this->form_fields( $edit, 'tablerefresh_save', $post_id ) + array(
				'url'       => $url,
				'frequency' => 'hourly',
				'enabled'   => '1',
			)
		);
		$this->assertSame( 'hourly', wp_get_scheduled_event( Scheduler::HOOK, array( $post_id ) )->schedule );
		$this->assertSame( 1, Scheduler::count_events( $post_id ) );

		// A lost event is restored when an administrator opens the list.
		wp_clear_scheduled_hook( Scheduler::HOOK, array( $post_id ) );
		$this->page( $cookie );
		wp_cache_flush_runtime();
		$this->assertSame( 1, Scheduler::count_events( $post_id ) );

		$this->submit( $cookie, $this->form_fields( $edit, 'tablerefresh_save', $post_id ) + array( 'url' => $url, 'frequency' => 'hourly' ) ); // Unchecked.
		$this->assertFalse( Sync_Config::get( $post_id )['enabled'] );
		$this->assertSame( 0, Scheduler::count_events( $post_id ) );

		$data = $this->load_table( $table_id )['data'];
		$edit = $this->page( $cookie, '&action=edit&table=' . $post_id );
		$this->assertStringContainsString( 'name="confirm_remove" value="1" required', $edit );
		$this->assertStringContainsString( '>Remove sync setup</button>', $edit );

		// Without the confirmation checkbox: refused, nothing deleted.
		$this->submit( $cookie, $this->form_fields( $edit, 'tablerefresh_remove', $post_id ) );
		$this->assertNotNull( Sync_Config::get( $post_id ) );
		$this->assertNotSame( array(), Sync_Log::get( $post_id )['entries'] );
		$this->assertStringContainsString( 'Nothing was removed.', $this->page( $cookie, '&action=edit&table=' . $post_id ) );

		$this->submit( $cookie, $this->form_fields( $edit, 'tablerefresh_remove', $post_id ) + array( 'confirm_remove' => '1' ) );
		$this->assertNull( Sync_Config::get( $post_id ) );
		$this->assertSame( array(), Sync_Log::get( $post_id )['entries'] );
		$this->assertSame( 0, Scheduler::count_events( $post_id ) );
		$this->assertSame( $data, $this->load_table( $table_id )['data'], 'The table is not changed.' );
		$this->assertStringContainsString( 'The sync setup was removed. The TablePress table and its data were not changed.', $this->page( $cookie ) );
	}

	public function test_table_names_and_messages_are_escaped() {
		list( , $post_id, , $cookie ) = $this->set_up_through_screen();
		Sync_Log::add(
			$post_id,
			array(
				'outcome' => 'failed',
				'message' => '<img src=x onerror=alert(1)>',
			)
		);
		$table_id = $this->create_table( array( array( 'x' ) ), array( 'name' => '<script>alert(1)</script>' ) );

		$list = $this->page( $cookie );
		$this->assertStringNotContainsString( '<img src=x', $list );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $list );
		$add = $this->page( $cookie, '&action=add' );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $add );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; (ID ' . $table_id . ')', $add );
	}
}
