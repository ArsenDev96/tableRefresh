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
 * - TABLEREFRESH_TEST_FAULT=fail_visibility_meta    Make the visibility meta update fail.
 * - TABLEREFRESH_TEST_NO_TRANSACTIONS=1             Simulate a database without transactions.
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
}

$tablerefresh_test_fault = (string) getenv( 'TABLEREFRESH_TEST_FAULT' );
if ( '' !== $tablerefresh_test_fault ) {
	// Fires inside TablePress_Table_Model::save(), after wp_update_post() wrote the new
	// table data, but before the options and visibility post meta are updated.
	add_action(
		'save_post_tablepress_table',
		static function () use ( $tablerefresh_test_fault ) {
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
