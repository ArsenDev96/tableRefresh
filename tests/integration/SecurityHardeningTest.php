<?php
/**
 * Proxies, decompression limits, and interrupted downloads.
 *
 * @package TableRefresh
 */

use TableRefresh\Http_Fetcher;

/**
 * @covers \TableRefresh\Http_Fetcher
 */
class SecurityHardeningTest extends Integration_Test_Case {

	private function fetch( string $url, array $args = array() ) {
		return ( new Http_Fetcher() )->fetch( $url, $args );
	}

	private function assertFetchError( string $code, $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) );
	}

	public function test_decompression_bomb_is_aborted_early_on_any_libcurl() {
		// 1 MB on the wire, 1 GB decompressed. Without the decoded-size abort, libcurl < 8.4
		// decompressed all of it (measured: 2.6 s with libcurl 7.83) before the size check.
		$start  = microtime( true );
		$result = $this->fetch( 'http://fixtures/gzip-huge', array( 'max_bytes' => 5 * MB_IN_BYTES, 'timeout' => 60 ) );
		$time   = microtime( true ) - $start;
		$this->assertFetchError( 'tablerefresh_http_too_large', $result );
		$this->assertLessThan( 1.0, $time, sprintf( 'Aborted after %.2f s (libcurl %s).', $time, curl_version()['version'] ) );
	}

	public function test_compressed_size_is_limited_separately() {
		// 50 MB decompressed is allowed here, but the compressed body exceeds the wire limit.
		$args = array( 'max_bytes' => 100 * MB_IN_BYTES, 'max_compressed_bytes' => 10000 );
		$this->assertFetchError( 'tablerefresh_http_too_large', $this->fetch( 'http://fixtures/gzip?bytes=50000000', $args ) );
		$this->assertIsArray( $this->fetch( 'http://fixtures/gzip?bytes=4000', $args ) );
	}

	/**
	 * @dataProvider truncations
	 */
	public function test_interrupted_downloads_are_rejected( string $mode ) {
		$this->assertFetchError( 'tablerefresh_http_incomplete', $this->fetch( 'http://fixtures/truncated?mode=' . $mode ) );

		$table_id = $this->create_table( array( array( 'keep' ) ) );
		$before   = $this->raw_state( $table_id );
		$this->assertFailedUnchanged( $this->sync( $table_id, 'http://fixtures/truncated?mode=' . $mode ), 'tablerefresh_http_incomplete', $table_id, $before );
	}

	public function truncations(): array {
		return array(
			'short body with Content-Length' => array( 'cl' ),
			'unterminated chunked body'      => array( 'chunked' ),
		);
	}

	public function test_proxy_environment_variables_are_ignored() {
		// libcurl would silently send the request to this (dead) proxy, bypassing the pinning.
		$saved = array( getenv( 'http_proxy' ), getenv( 'HTTP_PROXY' ) );
		putenv( 'http_proxy=http://fixtures:9' );
		putenv( 'HTTP_PROXY=http://fixtures:9' );
		try {
			$result = $this->fetch( $this->static_url( 'simple.csv' ) );
		} finally {
			putenv( false === $saved[0] ? 'http_proxy' : 'http_proxy=' . $saved[0] );
			putenv( false === $saved[1] ? 'HTTP_PROXY' : 'HTTP_PROXY=' . $saved[1] );
		}
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertFalse( $result['via_proxy'] );
		$this->assertStringStartsWith( 'Name,Age,City', $result['body'] );
	}

	public function test_wordpress_proxy_is_refused_by_default() {
		$table_id = $this->create_table( array( array( 'keep' ) ) );
		$before   = $this->raw_state( $table_id );
		$run      = $this->run_cli( array( 'sync', $table_id, $this->static_url( 'simple.csv' ) ), array( 'TABLEREFRESH_TEST_WP_PROXY' => 'fixtures:3128' ) );
		$this->assertSame( 'tablerefresh_http_proxy_unsupported', $run['json']['code'], $run['stdout'] . $run['stderr'] );
		$this->assertSame( $before, $this->raw_state( $table_id ) );
	}

	public function test_proxy_bypass_hosts_connect_directly_and_pinned() {
		$table_id = $this->create_table( array( array( 'keep' ) ) );
		$run      = $this->run_cli(
			array( 'sync', $table_id, $this->static_url( 'simple.csv' ) ),
			array(
				'TABLEREFRESH_TEST_WP_PROXY'        => 'fixtures:3128',
				'TABLEREFRESH_TEST_WP_PROXY_BYPASS' => 'fixtures',
			)
		);
		$this->assertSame( 'updated', $run['json']['status'], $run['stdout'] . $run['stderr'] );
		$this->assertSame( 'Name', $this->load_table( $table_id )['data'][0][0], 'Not through the proxy.' );
	}

	public function test_opted_in_proxy_is_used_and_url_validation_still_applies() {
		$env      = array(
			'TABLEREFRESH_TEST_WP_PROXY'    => 'fixtures:3128',
			'TABLEREFRESH_TEST_ALLOW_PROXY' => '1',
		);
		$table_id = $this->create_table( array( array( 'keep' ) ) );

		$run = $this->run_cli( array( 'sync', $table_id, $this->static_url( 'simple.csv' ) ), $env );
		$this->assertSame( 'updated', $run['json']['status'], $run['stdout'] . $run['stderr'] );
		$this->assertSame( 'via-proxy', $this->load_table( $table_id )['data'][0][0], 'The fixture proxy marks relayed data.' );

		// Private targets and redirects to them are still rejected before anything is sent.
		$this->assertSame( 'tablerefresh_url_blocked', $this->run_cli( array( 'sync', $table_id, 'http://127.0.0.1/a.csv' ), $env )['json']['code'] );
		$this->assertSame( 'tablerefresh_url_blocked', $this->run_cli( array( 'sync', $table_id, 'http://fixtures/redirect?to=' . rawurlencode( 'http://169.254.169.254/' ) ), $env )['json']['code'] );

		// A dead proxy makes the download fail: requests really go through the proxy.
		$env['TABLEREFRESH_TEST_WP_PROXY'] = 'fixtures:9';
		$this->assertSame( 'tablerefresh_http_unreachable', $this->run_cli( array( 'sync', $table_id, $this->static_url( 'simple.csv' ) ), $env )['json']['code'] );
	}
}
