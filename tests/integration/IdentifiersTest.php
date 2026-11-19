<?php
/**
 * Names that TableRefresh declares in PHP and stores in the database.
 *
 * @package TableRefresh
 */

use ArsenManukyan\TableRefresh\Editor_Guard;
use ArsenManukyan\TableRefresh\Journal;
use ArsenManukyan\TableRefresh\Plugin;
use ArsenManukyan\TableRefresh\Revision_Policy;
use ArsenManukyan\TableRefresh\Scheduler;
use ArsenManukyan\TableRefresh\Sync_Config;
use ArsenManukyan\TableRefresh\Sync_Log;
use ArsenManukyan\TableRefresh\Sync_State;
use ArsenManukyan\TableRefresh\Table_Lock;

/**
 * @covers \ArsenManukyan\TableRefresh\Plugin
 * @covers \ArsenManukyan\TableRefresh\Scheduler
 */
class IdentifiersTest extends Integration_Test_Case {

	const PHP_NAMESPACE = 'ArsenManukyan\TableRefresh';

	public function test_classes_are_declared_in_the_vendor_namespace() {
		$files = glob( TABLEREFRESH_DIR . 'includes/*.php' );
		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			require_once $file; // The admin and WP-CLI classes are loaded only where they are used.
			$source = (string) file_get_contents( $file );
			preg_match_all( '/^namespace\s+([^;]+);/m', $source, $namespaces );
			$this->assertSame( array( self::PHP_NAMESPACE ), $namespaces[1], basename( $file ) );
			preg_match_all( '/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $classes );
			$this->assertCount( 1, $classes[1], basename( $file ) );
			$this->assertTrue( class_exists( self::PHP_NAMESPACE . '\\' . $classes[1][0], false ), basename( $file ) );
			$this->assertSame( 0, preg_match( '/^function\s/m', $source ), basename( $file ) . ' declares a function outside a class.' );
		}
		$this->assertFalse( class_exists( 'TableRefresh\Plugin', false ), 'Nothing is left in the former namespace.' );

		// The files that run outside the namespace declare only prefixed constants and variables.
		foreach ( array( 'tablerefresh.php', 'uninstall.php' ) as $name ) {
			$source = (string) file_get_contents( TABLEREFRESH_DIR . $name );
			$this->assertSame( 0, preg_match( '/^(?:namespace|function|(?:final\s+|abstract\s+)?class)\s/m', $source ), $name );
			preg_match_all( "/define\(\s*'([^']+)'/", $source, $constants );
			foreach ( $constants[1] as $constant ) {
				$this->assertStringStartsWith( 'TABLEREFRESH_', $constant, $name );
			}
			preg_match_all( '/(?:^|\bas\s+)\$(\w+)/m', $source, $variables );
			foreach ( array_diff( $variables[1], array( 'wpdb' ) ) as $variable ) {
				$this->assertStringStartsWith( 'tablerefresh_', $variable, $name );
			}
		}
	}

	public function test_plugin_callbacks_resolve_to_the_namespaced_classes() {
		global $wp_filter;
		$basename = plugin_basename( TABLEREFRESH_FILE );
		$this->assertSame( 'tablerefresh/tablerefresh.php', $basename );
		foreach ( array( 'activate', 'deactivate' ) as $event ) {
			$hook = $event . '_' . $basename;
			$this->assertArrayHasKey( $hook, $wp_filter, $hook );
			$callbacks = array_merge( ...array_values( $wp_filter[ $hook ]->callbacks ) );
			$this->assertCount( 1, $callbacks, $hook );
			$callback = array_values( $callbacks )[0]['function'];
			$this->assertSame( array( Plugin::class, $event ), $callback, $hook );
			$this->assertTrue( is_callable( $callback ), $hook );
		}
		$this->assertSame( 10, has_action( Scheduler::HOOK, array( Scheduler::class, 'run' ) ) );
	}

	/**
	 * Earlier versions stored data and scheduled events under these names. Renaming one would
	 * orphan that data (and uninstall.php deletes by these prefixes), so they are fixed.
	 */
	public function test_stored_identifiers_are_unchanged() {
		$this->assertSame( 'tablerefresh_scheduled_sync', Scheduler::HOOK );
		$this->assertSame( '_tablerefresh_source', Sync_Config::META_KEY );
		$this->assertSame( '_tablerefresh_log', Sync_Log::META_KEY );
		$this->assertSame( '_tablerefresh_sync_state', Sync_State::META_KEY );
		$this->assertSame( '_tablerefresh_sync_revision', Revision_Policy::META_KEY );
		$this->assertSame( '_tablerefresh_editor_', Editor_Guard::META_PREFIX );
		$this->assertSame( 'tablerefresh_lock_', Table_Lock::PREFIX );
		$this->assertSame( 'tablerefresh_journal_', Journal::PREFIX );
	}
}
