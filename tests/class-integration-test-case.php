<?php
/**
 * Base class for integration tests against the real WordPress + TablePress install.
 *
 * @package TableRefresh
 */

use PHPUnit\Framework\TestCase;
use TableRefresh\Journal;
use TableRefresh\Sync_Service;
use TableRefresh\Table_Lock;
use TableRefresh\TablePress_Adapter;

/**
 * Helpers for creating tables, fixtures, and CLI subprocesses.
 */
abstract class Integration_Test_Case extends TestCase {

	/**
	 * Tables created by the current test.
	 *
	 * @var string[]
	 */
	private array $created_tables = array();

	/**
	 * Fixture files written by the current test.
	 *
	 * @var string[]
	 */
	private array $fixture_files = array();

	/**
	 * When true, the fixture host is treated like any other private host (blocked).
	 */
	protected static bool $block_fixture_host = false;

	public static function setUpBeforeClass(): void {
		add_filter(
			'tablerefresh_allow_private_host',
			static function ( $allowed ) {
				return self::$block_fixture_host ? false : $allowed;
			},
			99
		);
	}

	protected function setUp(): void {
		parent::setUp();
		self::$block_fixture_host = false;
		wp_set_current_user( 0 ); // Like WP-CLI and cron: no user, no unfiltered_html.
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		foreach ( $this->created_tables as $table_id ) {
			if ( TablePress::$model_table->table_exists( $table_id ) ) {
				TablePress::$model_table->delete( $table_id );
			}
			Journal::delete( $table_id );
			$this->delete_lock( $table_id );
		}
		foreach ( $this->sessions as $session ) {
			WP_Session_Tokens::get_instance( $session[0] )->destroy( $session[1] );
		}
		foreach ( $this->fixture_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		parent::tearDown();
	}

	/**
	 * Creates a TablePress table through TablePress's own model, as an administrator.
	 *
	 * @param string[][]           $data      Data.
	 * @param array<string, mixed> $overrides Table fields to override (e.g. name, options).
	 * @return string Table ID.
	 */
	protected function create_table( array $data, array $overrides = array() ): string {
		wp_set_current_user( 1 );
		$table               = TablePress::$model_table->get_table_template();
		$table['name']       = 'Test table';
		$table['description'] = 'Created by the integration tests';
		$table['data']       = $data;
		$table['visibility'] = array(
			'rows'    => array_fill( 0, count( $data ), 1 ),
			'columns' => array_fill( 0, count( $data[0] ), 1 ),
		);
		foreach ( $overrides as $key => $value ) {
			$table[ $key ] = is_array( $value ) && isset( $table[ $key ] ) && is_array( $table[ $key ] ) ? array_merge( $table[ $key ], $value ) : $value;
		}
		$table_id = TablePress::$model_table->add( $table );
		wp_set_current_user( 0 );
		$this->assertIsString( $table_id, 'TablePress could not create the test table.' );
		$this->created_tables[] = $table_id;
		return $table_id;
	}

	/**
	 * Writes a CSV file served by the fixture server and returns its URL.
	 *
	 * @param string $name    File name.
	 * @param string $content Content.
	 * @param string $query   Optional query string (e.g. "delay=3").
	 * @return string URL.
	 */
	protected function fixture( string $name, string $content, string $query = '' ): string {
		$name = $this->getName( false ) . '-' . $name;
		$path = TABLEREFRESH_TESTS_FIXTURE_DIR . '/' . $name;
		file_put_contents( $path, $content );
		$this->fixture_files[] = $path;
		return TABLEREFRESH_TESTS_FIXTURE_URL . '/data/' . rawurlencode( $name ) . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * URL of a static fixture file.
	 *
	 * @param string $name File name in dev/fixtures/static.
	 * @return string
	 */
	protected function static_url( string $name ): string {
		return TABLEREFRESH_TESTS_FIXTURE_URL . '/static/' . $name;
	}

	/**
	 * Runs a sync in this process.
	 *
	 * @param string               $table_id Table ID.
	 * @param string               $url      URL.
	 * @param array<string, mixed> $args     Arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function sync( string $table_id, string $url, array $args = array() ) {
		return ( new Sync_Service() )->sync( $table_id, $url, $args );
	}

	/**
	 * Raw stored state of a table (post fields and meta), read from the database.
	 *
	 * @param string $table_id Table ID.
	 * @return array<string, string|null>
	 */
	protected function raw_state( string $table_id ): array {
		$post_id = TablePress_Adapter::get_post_id( $table_id );
		$this->assertGreaterThan( 0, $post_id );
		$state = TablePress_Adapter::read_snapshot( $post_id );
		$this->assertIsArray( $state );
		return $state;
	}

	/**
	 * Loads a table freshly through TablePress (after clearing caches).
	 *
	 * @param string $table_id Table ID.
	 * @return array<string, mixed>
	 */
	protected function load_table( string $table_id ): array {
		TablePress_Adapter::clear_caches( $table_id, TablePress_Adapter::get_post_id( $table_id ) );
		$table = TablePress::$model_table->load( $table_id, true, true );
		$this->assertIsArray( $table );
		return $table;
	}

	/**
	 * Number of revisions stored for a table.
	 *
	 * @param string $table_id Table ID.
	 * @return int
	 */
	protected function revision_count( string $table_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision'", TablePress_Adapter::get_post_id( $table_id ) ) );
	}

	/**
	 * Asserts that a sync failed with a given error code and left the table untouched.
	 *
	 * @param mixed                      $result   Sync result.
	 * @param string                     $code     Expected error code.
	 * @param string                     $table_id Table ID.
	 * @param array<string, string|null> $before   raw_state() before the sync.
	 */
	protected function assertFailedUnchanged( $result, string $code, string $table_id, array $before ): void {
		$this->assertInstanceOf( WP_Error::class, $result, 'Expected the sync to fail.' );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		$this->assertSame( $before, $this->raw_state( $table_id ), 'The table must be byte-for-byte unchanged, including post_modified.' );
		$this->assertNull( Journal::read( $table_id ), 'No journal may be left behind.' );
		$this->assertFalse( Table_Lock::is_locked( $table_id ), 'The lock must be released.' );
	}

	/**
	 * Starts `wp tablerefresh sync` as a separate PHP process.
	 *
	 * @param string[]              $args Command arguments after "wp tablerefresh" (--format=json unless a format is given).
	 * @param array<string, string> $env  Extra environment variables.
	 * @return array{0: resource, 1: array<int, resource>}
	 */
	protected function start_cli( array $args, array $env = array() ): array {
		$format  = preg_grep( '/^--format=/', $args ) ? array() : array( '--format=json' );
		$command = array_merge( array( 'wp', '--path=' . TABLEREFRESH_TESTS_WP_DIR, 'tablerefresh' ), $args, $format );
		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, array_merge( getenv(), $env ) );
		$this->assertIsResource( $process );
		return array( $process, $pipes );
	}

	/**
	 * Waits for a process started with start_cli().
	 *
	 * @param array{0: resource, 1: array<int, resource>} $handle Handle.
	 * @return array{exit: int, stdout: string, stderr: string, json: array<string, mixed>|null}
	 */
	protected function wait_cli( array $handle ): array {
		list( $process, $pipes ) = $handle;
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );
		$json = null;
		foreach ( array_reverse( explode( "\n", trim( (string) $stdout ) ) ) as $line ) {
			$decoded = json_decode( $line, true );
			if ( is_array( $decoded ) ) {
				$json = $decoded;
				break;
			}
		}
		return array(
			'exit'   => $exit,
			'stdout' => (string) $stdout,
			'stderr' => (string) $stderr,
			'json'   => $json,
		);
	}

	/**
	 * Runs `wp tablerefresh ...` in a separate process and waits for it.
	 *
	 * @param string[]              $args Arguments.
	 * @param array<string, string> $env  Environment.
	 * @return array{exit: int, stdout: string, stderr: string, json: array<string, mixed>|null}
	 */
	protected function run_cli( array $args, array $env = array() ): array {
		return $this->wait_cli( $this->start_cli( $args, $env ) );
	}

	/**
	 * Session tokens created by the current test.
	 *
	 * @var array<int, array{0: int, 1: string}>
	 */
	private array $sessions = array();

	/**
	 * Logs a user in for HTTP requests to the Apache container (real auth cookies).
	 *
	 * @param int $user_id User ID.
	 * @return string Cookie header value.
	 */
	protected function login_cookie( int $user_id = 1 ): string {
		$expiration       = time() + HOUR_IN_SECONDS;
		$token            = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
		$this->sessions[] = array( $user_id, $token );
		return AUTH_COOKIE . '=' . rawurlencode( wp_generate_auth_cookie( $user_id, $expiration, 'auth', $token ) )
			. '; ' . LOGGED_IN_COOKIE . '=' . rawurlencode( wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ) );
	}

	/**
	 * Sends an HTTP request to the WordPress site served by Apache.
	 *
	 * @param string                    $path   Path, e.g. "/wp-admin/admin-ajax.php".
	 * @param string                    $cookie Cookie header.
	 * @param array<string, mixed>|null $post   Form fields for a POST request.
	 * @return string Response body.
	 */
	protected function http( string $path, string $cookie = '', ?array $post = null ): string {
		$options = array(
			'method'        => null === $post ? 'GET' : 'POST',
			'header'        => "Host: localhost:8089\r\nCookie: {$cookie}\r\n" . ( null === $post ? '' : "Content-Type: application/x-www-form-urlencoded\r\n" ),
			'timeout'       => 30,
			'ignore_errors' => true,
		);
		if ( null !== $post ) {
			$options['content'] = http_build_query( $post );
		}
		return (string) file_get_contents( 'http://wordpress' . $path, false, stream_context_create( array( 'http' => $options ) ) );
	}

	/**
	 * Opens a table in the real TablePress editor and returns what the browser receives.
	 *
	 * @param string $table_id Table ID.
	 * @param string $cookie   Cookie header from login_cookie().
	 * @return array{nonce: string, data: array<int, array<int, string>>, options: array<string, mixed>, visibility: array<string, mixed>, meta: array<string, mixed>}
	 */
	protected function open_editor( string $table_id, string $cookie ): array {
		$html = $this->http( '/wp-admin/admin.php?page=tablepress&action=edit&table_id=' . rawurlencode( $table_id ), $cookie );
		// TablePress 3.1+ prints "edit_table: '…'," and "\tdata: JSON.parse( '…' ),"; 3.0.x prints
		// "tp.nonces.edit_table = '…';" and "tp.table.data = JSON.parse( '…' );".
		$this->assertSame( 1, preg_match( "/edit_table(?:: | = )'([0-9a-f]+)'/", $html, $nonce ), 'TablePress editor did not load: ' . substr( wp_strip_all_tags( $html ), 0, 300 ) );
		$editor = array( 'nonce' => $nonce[1] );
		foreach ( array( 'meta', 'data', 'options', 'visibility' ) as $item ) {
			$this->assertSame( 1, preg_match( "/(?:\\t|tp\\.table\\.){$item}(?:: | = )JSON\\.parse\\( '(.*)' \\)[,;]\\n/", $html, $match ), "Editor variable {$item} not found." );
			$editor[ $item ] = json_decode( str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $match[1] ), true );
		}
		return $editor;
	}

	/**
	 * Clicks "Save Changes" in the TablePress editor: the same AJAX request that edit.js sends.
	 *
	 * @param string               $table_id Table ID.
	 * @param array<string, mixed> $editor   Result of open_editor(), with modified data.
	 * @param string               $cookie   Cookie header.
	 * @return array<string, mixed> Decoded JSON response.
	 */
	protected function save_in_editor( string $table_id, array $editor, string $cookie ): array {
		$body = $this->http(
			'/wp-admin/admin-ajax.php',
			$cookie,
			array(
				'action'      => 'tablepress_save_table',
				'_ajax_nonce' => $editor['nonce'],
				'tablepress'  => array(
					'id'          => $table_id,
					'new_id'      => $table_id,
					'name'        => $editor['meta']['name'],
					'description' => $editor['meta']['description'],
					'data'        => wp_json_encode( $editor['data'] ),
					'options'     => wp_json_encode( $editor['options'] ),
					'visibility'  => wp_json_encode( $editor['visibility'] ),
					'number'      => array(
						'rows'    => count( $editor['data'] ),
						'columns' => count( $editor['data'][0] ),
					),
				),
			)
		);
		$json = json_decode( $body, true );
		$this->assertIsArray( $json, 'Unexpected AJAX response: ' . substr( $body, 0, 300 ) );
		return $json;
	}

	/**
	 * Removes a lock row regardless of owner.
	 *
	 * @param string $table_id Table ID.
	 */
	protected function delete_lock( string $table_id ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => Table_Lock::option_name( $table_id ) ) );
	}
}
