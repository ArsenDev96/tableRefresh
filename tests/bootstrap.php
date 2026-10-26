<?php
/**
 * Boots the real WordPress install of the Docker test environment.
 *
 * Tests run against the live database with TablePress and TableRefresh active. They
 * intentionally do not use WP_UnitTestCase, which wraps each test in a transaction and
 * would hide the transaction behavior that is under test. Each test cleans up after itself.
 *
 * @package TableRefresh
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$tablerefresh_loader_vars = array_keys( get_defined_vars() ); // Variables of PHPUnit's loader; keep them.

$_SERVER['HTTP_HOST']   = 'localhost:8089';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

define( 'TABLEREFRESH_TESTS_WP_DIR', getenv( 'WP_DIR' ) ? getenv( 'WP_DIR' ) : '/var/www/html' );
define( 'TABLEREFRESH_TESTS_FIXTURE_DIR', '/fixtures-data' );
define( 'TABLEREFRESH_TESTS_FIXTURE_URL', 'http://fixtures' );

require TABLEREFRESH_TESTS_WP_DIR . '/wp-load.php';

if ( ! class_exists( 'TableRefresh\Sync_Service' ) || ! TableRefresh\TablePress_Adapter::is_available() ) {
	fwrite( STDERR, "TableRefresh and TablePress must be active in the test site.\n" );
	exit( 1 );
}

require __DIR__ . '/class-integration-test-case.php';

// PHPUnit copies every variable defined in this file's scope into $GLOBALS after loading it.
// WordPress files included above define locals such as `$shortcode_tags = array()`, which
// would overwrite the real, populated globals. Drop them all.
foreach ( array_diff( array_keys( get_defined_vars() ), $tablerefresh_loader_vars, array( 'tablerefresh_loader_vars' ) ) as $tablerefresh_var ) {
	unset( $$tablerefresh_var );
}
unset( $tablerefresh_var, $tablerefresh_loader_vars );
