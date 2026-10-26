<?php
/**
 * Downloads through the WordPress HTTP API against the fixture server.
 *
 * @package TableRefresh
 */

use TableRefresh\Http_Fetcher;

/**
 * @covers \TableRefresh\Http_Fetcher
 */
class HttpFetcherTest extends Integration_Test_Case {

	private function fetch( string $url, array $args = array() ) {
		return ( new Http_Fetcher() )->fetch( $url, $args );
	}

	private function assertFetchError( string $code, $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() . ' / ' . wp_json_encode( $result->get_error_data() ) );
	}

	public function test_downloads_csv() {
		$result = $this->fetch( $this->static_url( 'simple.csv' ) );
		$this->assertIsArray( $result );
		$this->assertSame( "Name,Age,City\r\nAlice,30,Berlin\r\nBob,25,Paris\r\n", $result['body'] );
		$this->assertSame( 'text/csv', $result['content_type'] );
		$this->assertSame( 0, $result['redirects'] );
	}

	public function test_follows_relative_redirects_and_chains_within_limit() {
		$result = $this->fetch( 'http://fixtures/redirect?to=/static/simple.csv&code=307' );
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['redirects'] );
		$this->assertSame( 'http://fixtures/static/simple.csv', $result['final_url'] );

		$result = $this->fetch( 'http://fixtures/redirect-chain?hops=5' );
		$this->assertIsArray( $result );
		$this->assertSame( 5, $result['redirects'] );
	}

	public function test_too_many_redirects() {
		$this->assertFetchError( 'tablerefresh_http_redirect', $this->fetch( 'http://fixtures/redirect-chain?hops=7' ) );
	}

	public function test_redirect_targets_are_validated_against_ssrf() {
		foreach ( array( 'http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/', 'http://db/', 'http://2130706433/' ) as $target ) {
			$result = $this->fetch( 'http://fixtures/redirect?to=' . rawurlencode( $target ) );
			$this->assertInstanceOf( WP_Error::class, $result, $target );
			$this->assertContains( $result->get_error_code(), array( 'tablerefresh_url_blocked', 'tablerefresh_url_host' ), $target );
		}
		$this->assertFetchError( 'tablerefresh_url_scheme', $this->fetch( 'http://fixtures/redirect?to=' . rawurlencode( 'ftp://example.com/x.csv' ) ) );
		$this->assertFetchError( 'tablerefresh_url_port', $this->fetch( 'http://fixtures/redirect?to=' . rawurlencode( 'http://fixtures:8080/x.csv' ) ) );
	}

	public function test_redirect_to_google_sign_in_is_reported_as_not_public() {
		$this->assertFetchError( 'tablerefresh_google_sheet_not_public', $this->fetch( 'http://fixtures/redirect?to=' . rawurlencode( 'https://accounts.google.com/ServiceLogin?continue=x' ) ) );
	}

	public function test_http_error_status() {
		$result = $this->fetch( 'http://fixtures/status?code=404' );
		$this->assertFetchError( 'tablerefresh_http_status', $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertFetchError( 'tablerefresh_http_status', $this->fetch( 'http://fixtures/status?code=500' ) );
		$this->assertFetchError( 'tablerefresh_http_redirect', $this->fetch( 'http://fixtures/status?code=302' ) ); // Redirect without Location.
	}

	public function test_html_responses_are_rejected() {
		$this->assertFetchError( 'tablerefresh_http_html', $this->fetch( 'http://fixtures/html' ) );
		$this->assertFetchError( 'tablerefresh_http_html', $this->fetch( 'http://fixtures/html-as-csv' ) ); // HTML body with text/csv header.
		$this->assertFetchError( 'tablerefresh_http_html', $this->fetch( $this->static_url( 'simple.csv?type=application/xhtml%2Bxml' ) ) );
	}

	public function test_unexpected_content_types_are_rejected() {
		$this->assertFetchError( 'tablerefresh_http_content_type', $this->fetch( $this->static_url( 'simple.csv?type=application/json' ) ) );
		$this->assertFetchError( 'tablerefresh_http_content_type', $this->fetch( $this->static_url( 'simple.csv?type=image/png' ) ) );
		$this->assertIsArray( $this->fetch( $this->static_url( 'simple.csv?type=text/plain' ) ) );
		$this->assertIsArray( $this->fetch( $this->static_url( 'simple.csv?type=application/octet-stream' ) ) );
	}

	public function test_oversized_responses_are_rejected_with_and_without_content_length() {
		$args = array( 'max_bytes' => 100000 );
		$this->assertFetchError( 'tablerefresh_http_too_large', $this->fetch( 'http://fixtures/big?bytes=100001&cl=1', $args ) );

		$start = microtime( true );
		$this->assertFetchError( 'tablerefresh_http_too_large', $this->fetch( 'http://fixtures/big?bytes=200000000&cl=0', $args ) );
		$this->assertLessThan( 5, microtime( true ) - $start, 'The transfer must be aborted on the wire, not downloaded completely.' );

		$this->assertIsArray( $this->fetch( 'http://fixtures/big?bytes=100000&cl=0', $args ) ); // Exactly at the limit.
	}

	public function test_compressed_response_cannot_bypass_size_limit() {
		$result = $this->fetch( 'http://fixtures/gzip?bytes=50000000', array( 'max_bytes' => 1000000 ) );
		$this->assertFetchError( 'tablerefresh_http_too_large', $result );
	}

	public function test_timeout() {
		$start = microtime( true );
		$this->assertFetchError( 'tablerefresh_http_timeout', $this->fetch( 'http://fixtures/hang?seconds=10', array( 'timeout' => 2 ) ) );
		$this->assertLessThan( 4, microtime( true ) - $start );
	}

	public function test_unreachable_hosts() {
		$this->assertFetchError( 'tablerefresh_url_dns', $this->fetch( 'https://does-not-exist.invalid/a.csv' ) );

		// Connection refused: the database container has nothing listening on port 80.
		$allow_db = static fn( $allowed, $host ) => 'db' === $host ? true : $allowed;
		add_filter( 'tablerefresh_allow_private_host', $allow_db, 10, 2 );
		try {
			$this->assertFetchError( 'tablerefresh_http_unreachable', $this->fetch( 'http://db/a.csv' ) );
		} finally {
			remove_filter( 'tablerefresh_allow_private_host', $allow_db, 10 );
		}
	}

	public function test_other_transports_or_intercepted_requests_are_rejected() {
		$fake = static fn() => array(
			'headers'  => array( 'content-type' => 'text/csv' ),
			'body'     => "a,b\n1,2\n",
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
		);
		add_filter( 'pre_http_request', $fake );
		try {
			$this->assertFetchError( 'tablerefresh_http_transport', $this->fetch( $this->static_url( 'simple.csv' ) ) );
		} finally {
			remove_filter( 'pre_http_request', $fake );
		}
	}

	/**
	 * Simulates DNS rebinding: the host resolves to a different address when cURL connects
	 * than when URL_Guard checked it. The rogue address is the WordPress container, which
	 * serves an HTML page, not the CSV.
	 */
	public function test_connection_is_pinned_to_the_checked_address() {
		$rogue  = gethostbyname( 'wordpress' );
		$rebind = static function ( $handle ) use ( $rogue ) {
			curl_setopt( $handle, CURLOPT_RESOLVE, array( "fixtures:80:{$rogue}" ) );
		};
		add_action( 'http_api_curl', $rebind, 10 ); // Before TableRefresh's pin (PHP_INT_MAX).
		try {
			$result = $this->fetch( $this->static_url( 'simple.csv' ) );
		} finally {
			remove_action( 'http_api_curl', $rebind, 10 );
		}
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertStringStartsWith( 'Name,Age,City', $result['body'], 'The pinned, checked address must be used.' );
	}

	public function test_connection_to_an_unchecked_address_is_discarded() {
		$rogue  = gethostbyname( 'wordpress' );
		$rebind = static function ( $handle ) use ( $rogue ) {
			curl_setopt( $handle, CURLOPT_RESOLVE, array( "fixtures:80:{$rogue}" ) );
		};
		// Fires after `http_api_curl`, i.e. after TableRefresh's pin, and overrides it.
		add_action( 'requests-curl.before_send', $rebind );
		try {
			$result = $this->fetch( $this->static_url( 'simple.csv' ) );
		} finally {
			remove_action( 'requests-curl.before_send', $rebind );
		}
		$this->assertFetchError( 'tablerefresh_url_blocked', $result );
	}

	public function test_private_fixture_host_is_blocked_by_default() {
		self::$block_fixture_host = true;
		$this->assertFetchError( 'tablerefresh_url_blocked', $this->fetch( $this->static_url( 'simple.csv' ) ) );
	}
}
