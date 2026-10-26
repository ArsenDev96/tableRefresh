<?php
/**
 * Concurrent synchronization attempts, using real parallel PHP processes.
 *
 * @package TableRefresh
 */

use TableRefresh\Table_Lock;

/**
 * @covers \TableRefresh\Table_Lock
 */
class ConcurrencyTest extends Integration_Test_Case {

	public function test_only_one_of_several_parallel_processes_writes() {
		$table_id  = $this->create_table( array( array( 'a' ) ) );
		$url       = $this->fixture( 'slow.csv', "x,y\n1,2\n", 'delay=3' );
		$revisions = $this->revision_count( $table_id );

		$handles = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$handles[] = $this->start_cli( array( 'sync', $table_id, $url ) );
		}
		$statuses = array();
		foreach ( $handles as $handle ) {
			$run        = $this->wait_cli( $handle );
			$this->assertIsArray( $run['json'], $run['stdout'] . $run['stderr'] );
			$statuses[] = 'error' === $run['json']['status'] ? $run['json']['code'] : $run['json']['status'];
		}
		sort( $statuses );

		$this->assertSame( array( 'tablerefresh_locked', 'tablerefresh_locked', 'tablerefresh_locked', 'tablerefresh_locked', 'updated' ), $statuses );
		$this->assertSame( $revisions + 1, $this->revision_count( $table_id ), 'Exactly one write.' );
		$this->assertSame( array( array( 'x', 'y' ), array( '1', '2' ) ), $this->load_table( $table_id )['data'] );
		$this->assertFalse( Table_Lock::is_locked( $table_id ) );
	}

	public function test_different_tables_sync_in_parallel() {
		$first  = $this->create_table( array( array( 'a' ) ) );
		$second = $this->create_table( array( array( 'b' ) ) );
		$url    = $this->fixture( 'slow.csv', "p,q\n", 'delay=2' );

		$start = microtime( true );
		$a     = $this->start_cli( array( 'sync', $first, $url ) );
		$b     = $this->start_cli( array( 'sync', $second, $url ) );
		foreach ( array( $a, $b ) as $handle ) {
			$run = $this->wait_cli( $handle );
			$this->assertSame( 'updated', $run['json']['status'] ?? null, $run['stdout'] . $run['stderr'] );
		}
		$this->assertLessThan( 7, microtime( true ) - $start, 'Different tables must not block each other.' );
	}

	public function test_process_that_outlived_its_lock_does_not_write() {
		$table_id = $this->create_table( array( array( 'a' ) ) );
		$slow     = $this->fixture( 'slow.csv', "slow\n", 'delay=6' );
		$fast     = $this->fixture( 'fast.csv', "fast\n" );

		$a = $this->start_cli( array( 'sync', $table_id, $slow ), array( 'TABLEREFRESH_TEST_LOCK_TTL' => '2' ) );
		usleep( 3500000 );
		$b = $this->sync( $table_id, $fast ); // Takes over the expired lock while A is still downloading.
		$this->assertIsArray( $b, is_wp_error( $b ) ? $b->get_error_message() : '' );
		$this->assertTrue( $b['took_over'] );
		$this->assertSame( 'updated', $b['status'] );

		$run = $this->wait_cli( $a );
		$this->assertSame( 'tablerefresh_lock_lost', $run['json']['code'], $run['stdout'] . $run['stderr'] );
		$this->assertSame( array( array( 'fast' ) ), $this->load_table( $table_id )['data'] );
		$this->assertFalse( Table_Lock::is_locked( $table_id ) );
	}

	public function test_lock_semantics() {
		$table_id = 'lock-test-' . wp_generate_password( 6, false );
		$first    = Table_Lock::acquire( $table_id );
		$this->assertInstanceOf( Table_Lock::class, $first );
		$this->assertTrue( $first->is_held() );

		$second = Table_Lock::acquire( $table_id );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'tablerefresh_locked', $second->get_error_code() );

		// Let the first lock expire, as if its process had died.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CONCAT('1|', option_value) WHERE option_name = %s", Table_Lock::option_name( $table_id ) ) );

		$third = Table_Lock::acquire( $table_id );
		$this->assertInstanceOf( Table_Lock::class, $third );
		$this->assertTrue( $third->took_over() );
		$this->assertFalse( $first->is_held(), 'The old owner must notice that it lost the lock.' );
		$this->assertFalse( $first->release(), 'The old owner must not release the new owner\'s lock.' );
		$this->assertTrue( $third->is_held() );
		$this->assertTrue( $third->release() );
		$this->assertFalse( Table_Lock::is_locked( $table_id ) );

		$fourth = Table_Lock::acquire( $table_id );
		$this->assertInstanceOf( Table_Lock::class, $fourth );
		$this->assertFalse( $fourth->took_over() );
		$this->assertTrue( $fourth->release() );
	}
}
