<?php
/**
 * Plugin Name: TableRefresh Test Environment (DEV ONLY)
 * Description: Test-only hooks for the isolated Docker environment. Never install on a real site.
 *
 * Behaviour is controlled per PHP process through environment variables, so
 * that one CLI process can be crashed on purpose without affecting others:
 *
 * - TABLEREFRESH_TEST_FAULT=kill_after_post_update  SIGKILL the process after TablePress
 *   wrote the table post but before its meta (options/visibility) is written.
 * - TABLEREFRESH_TEST_FAULT=exit_after_post_update  Call exit() at the same point.
 * - TABLEREFRESH_TEST_FAULT=throw_after_post_update Throw an exception at the same point.
 * - TABLEREFRESH_TEST_FAULT=kill_after_revision     SIGKILL right after the revision of the new data
 *   was stored (WordPress 6.4+ stores it at the end of wp_insert_post()).
 * - TABLEREFRESH_TEST_FAULT=fail_visibility_meta    Make the visibility meta update fail.
 * - TABLEREFRESH_TEST_FAULT=human_save_before_write Another process saves the table through TablePress
 *   after TableRefresh loaded it, right before TableRefresh's transaction starts.
 * - TABLEREFRESH_TEST_FAULT=human_save_during_write Another process starts saving the table while
 *   TableRefresh's transaction holds the row lock (after TablePress wrote the post).
 * - TABLEREFRESH_TEST_FAULT=deadlock_before_post_update[_once] A real InnoDB deadlock with another
 *   connection, right before WordPress writes the table post (on every write, or only the first).
 * - TABLEREFRESH_TEST_FAULT=deadlock_before_post_update_then_kill The first attempt deadlocks; the
 *   retry is killed (SIGKILL) after WordPress wrote the table post.
 * - TABLEREFRESH_TEST_FAULT=connection_lost_before_post_update Another connection kills this one at
 *   the same point; wpdb reconnects and repeats the next statement outside the transaction.
 * - TABLEREFRESH_TEST_FAULT=connection_lost_before_post_update_then_kill The same, then SIGKILL once
 *   that repeated post UPDATE was committed.
 * - TABLEREFRESH_TEST_FAULT=connection_lost_before_commit[_once] Another connection kills this one
 *   right before TableRefresh's COMMIT (on every attempt, or only the first): the server rolls the
 *   transaction back, and wpdb repeats the COMMIT in a new session, where it commits nothing.
 * - TABLEREFRESH_TEST_FAULT=connection_lost_after_commit The server commits, then the connection is
 *   killed before the client learns it; wpdb repeats the COMMIT in a new session.
 * - TABLEREFRESH_TEST_FAULT=fail_visibility_meta_then_unreadable Like fail_visibility_meta; after that
 *   failure, reading the table post for a snapshot fails with a database error.
 * - TABLEREFRESH_TEST_FAULT=unreadable_table / unreadable_table_meta Reading the table post / its
 *   meta for a snapshot fails with a database error (e.g. during `wp tablerefresh recover`).
 * - TABLEREFRESH_TEST_EDITOR_TTL=<seconds>          Lifetime of editor sessions (0: ignore them).
 * - TABLEREFRESH_TEST_NO_TRANSACTIONS=1             Simulate a database without transactions and opt in
 *                                                    to non-atomic updates (refused by default).
 * - TABLEREFRESH_TEST_LOCK_TTL=<seconds>            Shorter lock lifetime for stale-lock tests.
 * - TABLEREFRESH_TEST_BLOCK_FIXTURES=1              Do not allowlist the private fixture host.
 * - TABLEREFRESH_TEST_BARRIER=<name>:<count>        Once WordPress is loaded, wait (at most 60 s) until
 *   <count> processes reached the barrier <name>, so that parallel processes start their command
 *   together however long each one took to boot.
 *
 * @package TableRefresh
 */

defined( 'ABSPATH' ) || exit;

// The fixture server lives on the private Docker network; allow exactly that host.
add_filter(
	'tablerefresh_allow_private_host',
	static function ( $allowed, $host ) {
		if ( getenv( 'TABLEREFRESH_TEST_BLOCK_FIXTURES' ) ) {
			return $allowed;
		}
		return 'fixtures' === $host ? true : $allowed;
	},
	10,
	2
);

if ( getenv( 'TABLEREFRESH_TEST_LOCK_TTL' ) ) {
	add_filter(
		'tablerefresh_lock_ttl',
		static function () {
			return (int) getenv( 'TABLEREFRESH_TEST_LOCK_TTL' );
		}
	);
}

if ( getenv( 'TABLEREFRESH_TEST_BARRIER' ) ) {
	add_action(
		'wp_loaded',
		static function () {
			list( $name, $count ) = explode( ':', (string) getenv( 'TABLEREFRESH_TEST_BARRIER' ) );
			$dir                  = sys_get_temp_dir() . '/tablerefresh-barrier-' . preg_replace( '/[^A-Za-z0-9]/', '', $name );
			if ( ! is_dir( $dir ) ) {
				@mkdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Another process may create it first.
			}
			touch( $dir . '/' . getmypid() );
			$deadline = microtime( true ) + 60;
			while ( count( (array) glob( $dir . '/*' ) ) < (int) $count && microtime( true ) < $deadline ) {
				usleep( 10000 );
			}
		},
		PHP_INT_MAX
	);
}

if ( getenv( 'TABLEREFRESH_TEST_NO_TRANSACTIONS' ) ) {
	add_filter( 'tablerefresh_use_transactions', '__return_false' );
	add_filter( 'tablerefresh_allow_non_atomic_updates', '__return_true' );
}

// WordPress HTTP proxy settings, as a site would define them in wp-config.php.
if ( getenv( 'TABLEREFRESH_TEST_WP_PROXY' ) ) {
	list( $tablerefresh_proxy_host, $tablerefresh_proxy_port ) = explode( ':', (string) getenv( 'TABLEREFRESH_TEST_WP_PROXY' ) );
	define( 'WP_PROXY_HOST', $tablerefresh_proxy_host );
	define( 'WP_PROXY_PORT', $tablerefresh_proxy_port );
	if ( getenv( 'TABLEREFRESH_TEST_WP_PROXY_BYPASS' ) ) {
		define( 'WP_PROXY_BYPASS_HOSTS', (string) getenv( 'TABLEREFRESH_TEST_WP_PROXY_BYPASS' ) );
	}
}
if ( getenv( 'TABLEREFRESH_TEST_ALLOW_PROXY' ) ) {
	add_filter( 'tablerefresh_allow_proxy', '__return_true' );
}

if ( false !== getenv( 'TABLEREFRESH_TEST_EDITOR_TTL' ) ) {
	add_filter(
		'tablerefresh_editor_session_ttl',
		static function () {
			return (int) getenv( 'TABLEREFRESH_TEST_EDITOR_TTL' );
		}
	);
}

/**
 * Saves the table through TablePress as the administrator, from a separate PHP process and
 * database connection, like a concurrent "Save Changes" in the editor. Sets cell A1 to "HUMAN-EDIT".
 *
 * @param string $table_id   Table ID.
 * @param bool   $background Do not wait for the save to finish.
 */
function tablerefresh_test_human_save( $table_id, $background ) {
	$code = 'wp_set_current_user( 1 ); $t = TablePress::$model_table->load( ' . var_export( (string) $table_id, true ) . ' ); $t["data"][0][0] = "HUMAN-EDIT"; TablePress::$model_table->save( $t );';
	$cmd  = 'TABLEREFRESH_TEST_FAULT= wp --path=' . escapeshellarg( ABSPATH ) . ' eval ' . escapeshellarg( $code );
	exec( $background ? $cmd . ' > /dev/null 2>&1 &' : $cmd . ' 2>&1' );
}

$tablerefresh_test_fault = (string) getenv( 'TABLEREFRESH_TEST_FAULT' );
if ( 'kill_after_revision' === $tablerefresh_test_fault ) {
	// Right after WordPress stored the revision of the new data (and TableRefresh marked it),
	// before TablePress wrote the table options and visibility.
	add_action(
		'_wp_put_post_revision',
		static function () {
			if ( tablerefresh_test_is_writing() ) {
				fwrite( STDERR, "TEST FAULT: SIGKILL\n" );
				posix_kill( getmypid(), 9 );
			}
		},
		20
	);
}
if ( 0 === strpos( $tablerefresh_test_fault, 'deadlock_before_post_update' ) || 0 === strpos( $tablerefresh_test_fault, 'connection_lost_before_post_update' ) ) {
	if ( 0 !== strpos( $tablerefresh_test_fault, 'connection_lost_before_post_update' ) ) {
		tablerefresh_test_prepare_deadlock(); // Before any transaction: DDL would end it.
		// What another connection (a visitor) sees once WordPress wrote the post, during the write.
		add_action(
			'save_post_tablepress_table',
			static function ( $post_id ) {
				global $wpdb;
				if ( tablerefresh_test_is_writing() ) {
					$other = tablerefresh_test_connect();
					$row   = mysqli_fetch_row( mysqli_query( $other, "SELECT post_content FROM {$wpdb->posts} WHERE ID = " . (int) $post_id ) );
					mysqli_close( $other );
					fwrite( STDERR, 'TEST OBSERVER: ' . $row[0] . "\n" );
				}
			}
		);
	}
	// Inside TableRefresh's transaction (the table rows are locked), before the post UPDATE.
	add_action(
		'pre_post_update',
		static function ( $post_id ) use ( $tablerefresh_test_fault ) {
			static $fired = false;
			if ( ! tablerefresh_test_is_writing() || ( $fired && in_array( $tablerefresh_test_fault, array( 'deadlock_before_post_update_once', 'deadlock_before_post_update_then_kill' ), true ) ) ) {
				return;
			}
			$fired = true;
			if ( 0 === strpos( $tablerefresh_test_fault, 'connection_lost_before_post_update' ) ) {
				tablerefresh_test_kill_connection();
			} else {
				tablerefresh_test_deadlock( (int) $post_id );
			}
		}
	);
}
if ( in_array( $tablerefresh_test_fault, array( 'connection_lost_before_commit', 'connection_lost_before_commit_once', 'connection_lost_after_commit' ), true ) ) {
	$tablerefresh_test_written = false;
	add_action(
		'save_post_tablepress_table',
		static function () use ( &$tablerefresh_test_written ) {
			if ( tablerefresh_test_is_writing() ) {
				$tablerefresh_test_written = true;
			}
		}
	);
	// The COMMIT of TableRefresh's transaction, once TablePress wrote the table in it.
	add_filter(
		'query',
		static function ( $query ) use ( &$tablerefresh_test_written, $tablerefresh_test_fault ) {
			static $fired = false;
			if ( ! $tablerefresh_test_written || 'COMMIT' !== trim( (string) $query ) || ( $fired && 'connection_lost_before_commit_once' === $tablerefresh_test_fault ) ) {
				return $query;
			}
			global $wpdb;
			$tablerefresh_test_written = false;
			$fired                     = true;
			if ( 'connection_lost_after_commit' === $tablerefresh_test_fault ) {
				mysqli_query( $wpdb->dbh, 'COMMIT' ); // The server commits; wpdb never sees the result.
				fwrite( STDERR, "TEST FAULT: committed\n" );
			}
			tablerefresh_test_kill_connection();
			return $query;
		}
	);
}
if ( in_array( $tablerefresh_test_fault, array( 'fail_visibility_meta_then_unreadable', 'unreadable_table', 'unreadable_table_meta' ), true ) ) {
	// The snapshot queries of TablePress_Adapter::read_snapshot() fail with a real database error.
	add_filter(
		'query',
		static function ( $query ) use ( $tablerefresh_test_fault ) {
			global $wpdb;
			$pattern = 'unreadable_table_meta' === $tablerefresh_test_fault
				? '/^SELECT meta_key, meta_value FROM \S+ WHERE post_id = \d+ AND meta_key IN \(/'
				: '/^SELECT post_type, post_title, post_excerpt, post_content, post_modified, post_modified_gmt FROM /';
			if ( ( 'fail_visibility_meta_then_unreadable' !== $tablerefresh_test_fault || ! empty( $GLOBALS['tablerefresh_test_meta_failed'] ) ) && preg_match( $pattern, (string) $query ) ) {
				fwrite( STDERR, "TEST FAULT: unreadable\n" );
				return "SELECT tablerefresh_no_such_column FROM {$wpdb->posts} WHERE 0";
			}
			return $query;
		}
	);
}
if ( 'human_save_before_write' === $tablerefresh_test_fault ) {
	// After TableRefresh loaded and journaled the table, before its transaction starts.
	add_action(
		'tablerefresh_before_write',
		static function ( $table_id ) {
			tablerefresh_test_human_save( $table_id, false );
		}
	);
}
if ( '' !== $tablerefresh_test_fault ) {
	// Fires inside TablePress_Table_Model::save(), after wp_update_post() wrote the new
	// table data, but before the options and visibility post meta are updated.
	add_action(
		'save_post_tablepress_table',
		static function ( $post_id ) use ( $tablerefresh_test_fault ) {
			if ( ! tablerefresh_test_is_writing() ) {
				return;
			}
			switch ( $tablerefresh_test_fault ) {
				case 'kill_after_post_update':
				case 'deadlock_before_post_update_then_kill': // Only the retry reaches this point.
				case 'connection_lost_before_post_update_then_kill':
					fwrite( STDERR, "TEST FAULT: SIGKILL\n" );
					posix_kill( getmypid(), 9 );
					sleep( 5 ); // Never reached.
					break;
				case 'exit_after_post_update':
					fwrite( STDERR, "TEST FAULT: exit\n" );
					exit( 3 );
				case 'throw_after_post_update':
					throw new RuntimeException( 'TEST FAULT: exception' );
				case 'human_save_during_write':
					// A concurrent save starts while TableRefresh's transaction holds the row lock.
					$tables = TablePress::$model_table->_debug_get_tables();
					tablerefresh_test_human_save( (string) array_search( (int) $post_id, $tables['table_post'], true ), true );
					sleep( 2 );
					break;
			}
		}
	);

	if ( in_array( $tablerefresh_test_fault, array( 'fail_visibility_meta', 'fail_visibility_meta_then_unreadable' ), true ) ) {
		add_filter(
			'update_post_metadata',
			static function ( $check, $object_id, $meta_key ) {
				if ( '_tablepress_table_visibility' === $meta_key && tablerefresh_test_is_writing() ) {
					$GLOBALS['tablerefresh_test_meta_failed'] = true;
					return false;
				}
				return $check;
			},
			10,
			3
		);
	}
}

/**
 * Opens a second database connection.
 *
 * @return mysqli
 */
function tablerefresh_test_connect() {
	global $wpdb;
	list( $host, $port ) = $wpdb->parse_db_host( DB_HOST );
	$other               = mysqli_init();
	mysqli_real_connect( $other, $host, DB_USER, DB_PASSWORD, DB_NAME, $port ? (int) $port : 3306 );
	return $other;
}

/**
 * Creates the table that the other connection changes in tablerefresh_test_deadlock().
 */
function tablerefresh_test_prepare_deadlock() {
	global $wpdb;
	$heavy = $wpdb->prefix . 'tablerefresh_test_heavy';
	$other = tablerefresh_test_connect();
	mysqli_query( $other, "CREATE TABLE IF NOT EXISTS {$heavy} (id INT PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB" );
	mysqli_query( $other, "INSERT IGNORE INTO {$heavy} (id, v) VALUES (" . implode( ',0),(', range( 1, 2000 ) ) . ',0)' );
	mysqli_close( $other );
}

/**
 * Causes a real deadlock between this connection's open transaction (which holds the row lock
 * of the table post) and another connection. The other transaction changed many more rows, so
 * InnoDB rolls back this one, as it would for a sync that collides with a bigger write.
 *
 * @param int $post_id Table post ID, locked by this connection.
 */
function tablerefresh_test_deadlock( $post_id ) {
	global $wpdb;
	$heavy = $wpdb->prefix . 'tablerefresh_test_heavy';
	$other = tablerefresh_test_connect();
	mysqli_query( $other, 'START TRANSACTION' );
	mysqli_query( $other, "UPDATE {$heavy} SET v = v + 1" );
	mysqli_query( $other, "UPDATE {$wpdb->posts} SET post_status = post_status WHERE ID = {$post_id}", MYSQLI_ASYNC ); // Waits for this connection.
	usleep( 300000 );
	$wpdb->query( "UPDATE {$heavy} SET v = v + 1 WHERE id = 1" ); // Waits for the other connection: deadlock.
	fwrite( STDERR, 'TEST FAULT: deadlock: ' . $wpdb->last_error . "\n" );
	mysqli_reap_async_query( $other );
	mysqli_query( $other, 'ROLLBACK' );
	mysqli_close( $other );
}

/**
 * Kills this process's database connection from another connection, like a server restart or
 * a connection timeout. The server rolls back the open transaction.
 */
function tablerefresh_test_kill_connection() {
	global $wpdb;
	$id    = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
	$other = tablerefresh_test_connect();
	mysqli_query( $other, "KILL {$id}" );
	mysqli_close( $other );
	fwrite( STDERR, "TEST FAULT: connection {$id} killed\n" );
}

/**
 * Whether TableRefresh is currently inside its guarded TablePress write.
 *
 * @return bool
 */
function tablerefresh_test_is_writing() {
	return class_exists( 'TableRefresh\Table_Updater', false ) && TableRefresh\Table_Updater::is_writing();
}
