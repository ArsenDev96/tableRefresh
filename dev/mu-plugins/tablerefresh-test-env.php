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
 * - TABLEREFRESH_TEST_EDITOR_TTL=<seconds>          Lifetime of editor sessions (0: ignore them).
 * - TABLEREFRESH_TEST_NO_TRANSACTIONS=1             Simulate a database without transactions and opt in
 *                                                    to non-atomic updates (refused by default).
 * - TABLEREFRESH_TEST_LOCK_TTL=<seconds>            Shorter lock lifetime for stale-lock tests.
 * - TABLEREFRESH_TEST_BLOCK_FIXTURES=1              Do not allowlist the private fixture host.
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

	if ( 'fail_visibility_meta' === $tablerefresh_test_fault ) {
		add_filter(
			'update_post_metadata',
			static function ( $check, $object_id, $meta_key ) {
				if ( '_tablepress_table_visibility' === $meta_key && tablerefresh_test_is_writing() ) {
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
 * Whether TableRefresh is currently inside its guarded TablePress write.
 *
 * @return bool
 */
function tablerefresh_test_is_writing() {
	return class_exists( 'TableRefresh\Table_Updater', false ) && TableRefresh\Table_Updater::is_writing();
}
