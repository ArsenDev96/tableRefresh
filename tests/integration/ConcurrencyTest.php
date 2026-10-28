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

		// All five request the lock together, while the first one holds it for the slow download.
		$barrier = array( 'TABLEREFRESH_TEST_BARRIER' => uniqid( 'same' ) . ':5' );
		$handles = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$handles[] = $this->start_cli( array( 'sync', $table_id, $url ), $barrier );
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
		$url    = $this->fixture( 'slow.csv', "p,q\n", 'delay=3' );

		$barrier = array( 'TABLEREFRESH_TEST_BARRIER' => uniqid( 'different' ) . ':2' );
		$a       = $this->start_cli( array( 'sync', $first, $url ), $barrier );
		$b       = $this->start_cli( array( 'sync', $second, $url ), $barrier );

		// Both locks are held at the same time during the slow downloads. Observed state instead
		// of a wall-clock limit, which slow WordPress boots under full-suite load exceeded.
		$overlapped = $this->poll_while_running(
			array( $a, $b ),
			static function () use ( $first, $second ) {
				return Table_Lock::is_locked( $first ) && Table_Lock::is_locked( $second );
			}
		);
		foreach ( array( $a, $b ) as $handle ) {
			$run = $this->wait_cli( $handle );
			$this->assertSame( 'updated', $run['json']['status'] ?? null, $run['stdout'] . $run['stderr'] );
		}
		$this->assertTrue( $overlapped, 'Different tables must not block each other.' );
	}

	public function test_process_that_outlived_its_lock_does_not_write() {
		$table_id = $this->create_table( array( array( 'a' ) ) );
		$slow     = $this->fixture( 'slow.csv', "slow\n", 'delay=6' );
		$fast     = $this->fixture( 'fast.csv', "fast\n" );

		$a = $this->start_cli( array( 'sync', $table_id, $slow ), array( 'TABLEREFRESH_TEST_LOCK_TTL' => '2' ) );

		// Wait until A holds its lock (however long its boot takes), then until that lock expired.
		$expires = 0;
		$this->poll_while_running(
			array( $a ),
			function () use ( $table_id, &$expires ) {
				$expires = $this->lock_expiry( $table_id );
				return $expires > 0;
			}
		);
		$this->assertGreaterThan( 0, $expires, 'A must acquire the lock.' );
		while ( time() < $expires ) {
			usleep( 50000 );
		}
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

	public function test_lock_check_in_a_transaction_blocks_a_takeover_until_the_commit() {
		global $wpdb;
		$table_id = 'lock-row-' . wp_generate_password( 6, false );
		$name     = Table_Lock::option_name( $table_id );
		$lock     = Table_Lock::acquire( $table_id );
		$this->assertInstanceOf( Table_Lock::class, $lock );

		// Another process that tries to take the lock over, on its own connection.
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->suppress_errors( true );
		$other->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$takeover = $other->prepare( "UPDATE {$wpdb->options} SET option_value = 'other' WHERE option_name = %s", $name );

		$wpdb->query( 'START TRANSACTION' );
		try {
			$this->assertTrue( $lock->is_held( true ) );
			$this->assertFalse( $other->query( $takeover ), 'The lock row must stay locked until the commit.' );
			$this->assertStringContainsString( 'Lock wait timeout', $other->last_error );
		} finally {
			$wpdb->query( 'COMMIT' );
		}

		$this->assertSame( 1, $other->query( $takeover ), 'After the commit the row is free again.' );
		$this->assertFalse( $lock->is_held() );
		$other->close();
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	}

	/**
	 * Polls a condition every 20 ms until it holds or one of the processes has exited.
	 *
	 * @param array<int, array{0: resource, 1: array<int, resource>}> $handles   Handles from start_cli().
	 * @param callable                                               $condition Returns true when met.
	 * @return bool Whether the condition was met.
	 */
	private function poll_while_running( array $handles, callable $condition ): bool {
		$deadline = microtime( true ) + 120;
		while ( microtime( true ) < $deadline ) {
			if ( $condition() ) {
				return true;
			}
			foreach ( $handles as $handle ) {
				if ( ! proc_get_status( $handle[0] )['running'] ) {
					return (bool) $condition();
				}
			}
			usleep( 20000 );
		}
		return false;
	}

	/**
	 * Expiry time of a table's lock row, read directly from the database.
	 *
	 * @param string $table_id Table ID.
	 * @return int Unix time, or 0 if there is no lock row.
	 */
	private function lock_expiry( string $table_id ): int {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Table_Lock::option_name( $table_id ) ) );
		return null === $value ? 0 : (int) strtok( (string) $value, '|' );
	}
}
