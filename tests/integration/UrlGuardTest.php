<?php
/**
 * SSRF protection: URL syntax, host forms, and address ranges.
 *
 * @package TableRefresh
 */

use TableRefresh\Google_Sheets;
use TableRefresh\URL_Guard;

/**
 * @covers \TableRefresh\URL_Guard
 * @covers \TableRefresh\Google_Sheets
 */
class UrlGuardTest extends Integration_Test_Case {

	/**
	 * @dataProvider blocked_ips
	 */
	public function test_blocked_ip_ranges( string $ip ) {
		$this->assertTrue( URL_Guard::is_blocked_ip( $ip ), $ip );
	}

	public function blocked_ips(): array {
		$ips = array(
			'0.0.0.0', '10.1.2.3', '100.64.0.1', '127.0.0.1', '127.255.255.254', '169.254.169.254', '169.254.170.2',
			'172.16.0.1', '172.31.255.255', '192.0.0.192', '192.0.2.1', '192.168.1.1', '198.18.0.1', '198.51.100.7',
			'203.0.113.9', '224.0.0.1', '255.255.255.255', '100.100.100.200',
			'::', '::1', 'fc00::1', 'fd12:3456::1', 'fe80::1', 'ff02::1', '2001:db8::1',
			'::ffff:127.0.0.1', '::ffff:7f00:1', '::ffff:169.254.169.254', '64:ff9b::a9fe:a9fe', '2002:7f00:1::1',
			'2001:0:4136:e378:8000:63bf:3fff:fdd2', 'not-an-ip',
		);
		return array_combine( $ips, array_map( static fn( $ip ) => array( $ip ), $ips ) );
	}

	public function test_public_ips_are_allowed() {
		foreach ( array( '8.8.8.8', '142.250.185.78', '172.32.0.1', '2606:4700:4700::1111', '::ffff:8.8.8.8', '2a00:1450:4001:80b::200e' ) as $ip ) {
			$this->assertFalse( URL_Guard::is_blocked_ip( $ip ), $ip );
		}
	}

	/**
	 * @dataProvider rejected_urls
	 */
	public function test_rejected_urls( string $url, string $code ) {
		$result = URL_Guard::check( $url );
		$this->assertInstanceOf( WP_Error::class, $result, $url );
		$this->assertSame( $code, $result->get_error_code(), $url );
	}

	public function rejected_urls(): array {
		return array(
			'ftp'               => array( 'ftp://example.com/a.csv', 'tablerefresh_url_scheme' ),
			'file'              => array( 'file:///etc/passwd', 'tablerefresh_url_invalid' ),
			'gopher'            => array( 'gopher://example.com/', 'tablerefresh_url_scheme' ),
			'javascript'        => array( 'javascript:alert(1)', 'tablerefresh_url_invalid' ),
			'credentials'       => array( 'https://user:pass@example.com/a.csv', 'tablerefresh_url_credentials' ),
			'port 8080'         => array( 'http://example.com:8080/a.csv', 'tablerefresh_url_port' ),
			'port 22'           => array( 'http://example.com:22/', 'tablerefresh_url_port' ),
			'whitespace'        => array( 'http://example.com/a b.csv', 'tablerefresh_url_invalid' ),
			'loopback'          => array( 'http://127.0.0.1/a.csv', 'tablerefresh_url_blocked' ),
			'metadata'          => array( 'http://169.254.169.254/latest/meta-data/', 'tablerefresh_url_blocked' ),
			'ipv6 loopback'     => array( 'http://[::1]/a.csv', 'tablerefresh_url_blocked' ),
			'ipv4-mapped'       => array( 'http://[::ffff:127.0.0.1]/a.csv', 'tablerefresh_url_blocked' ),
			'decimal ip'        => array( 'http://2130706433/a.csv', 'tablerefresh_url_host' ),
			'hex ip'            => array( 'http://0x7f000001/a.csv', 'tablerefresh_url_host' ),
			'octal ip'          => array( 'http://0177.0.0.1/a.csv', 'tablerefresh_url_host' ),
			'short ip'          => array( 'http://127.1/a.csv', 'tablerefresh_url_host' ),
			'mixed hex ip'      => array( 'http://0x7f.0.0.1/a.csv', 'tablerefresh_url_host' ),
			'localhost'         => array( 'http://localhost/a.csv', 'tablerefresh_url_blocked' ),
			'docker db host'    => array( 'http://db/a.csv', 'tablerefresh_url_blocked' ),
			'docker wp host'    => array( 'http://wordpress/a.csv', 'tablerefresh_url_blocked' ),
			'unresolvable'      => array( 'https://does-not-exist.invalid/a.csv', 'tablerefresh_url_dns' ),
			'non-ascii host'    => array( 'https://exämple.com/a.csv', 'tablerefresh_url_host' ),
		);
	}

	public function test_private_fixture_host_is_blocked_unless_explicitly_allowed() {
		self::$block_fixture_host = true;
		$result                   = URL_Guard::check( 'http://fixtures/static/simple.csv' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'tablerefresh_url_blocked', $result->get_error_code() );

		self::$block_fixture_host = false; // The test environment allowlists exactly this host.
		$result                   = URL_Guard::check( 'http://fixtures/static/simple.csv' );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['private'] );
	}

	public function test_url_is_rebuilt_from_normalized_host() {
		$result = URL_Guard::check( 'http://FIXTURES./static/simple.csv?x=1#frag' );
		$this->assertIsArray( $result );
		$this->assertSame( 'http://fixtures/static/simple.csv?x=1', $result['url'] );
		$this->assertSame( 'fixtures', $result['host'] );
		$this->assertSame( 80, $result['port'] );
	}

	/**
	 * @dataProvider google_urls
	 */
	public function test_google_sheets_urls_are_normalized( string $input, string $expected ) {
		$this->assertSame( $expected, Google_Sheets::normalize_url( $input ) );
	}

	public function google_urls(): array {
		$id  = '1AbC-_dEf123';
		$pub = '2PACX-1vQ_abc-123';
		return array(
			'sharing'        => array( "https://docs.google.com/spreadsheets/d/{$id}/edit?usp=sharing", "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv" ),
			'edit with gid'  => array( "https://docs.google.com/spreadsheets/d/{$id}/edit#gid=123", "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv&gid=123" ),
			'gid in query'   => array( "https://docs.google.com/spreadsheets/d/{$id}/edit?gid=7#gid=7", "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv&gid=7" ),
			'user path'      => array( "https://docs.google.com/spreadsheets/u/0/d/{$id}/view", "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv" ),
			'bare'           => array( "https://docs.google.com/spreadsheets/d/{$id}", "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv" ),
			'export'         => array( "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv&gid=5", "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv&gid=5" ),
			'pubhtml'        => array( "https://docs.google.com/spreadsheets/d/e/{$pub}/pubhtml", "https://docs.google.com/spreadsheets/d/e/{$pub}/pub?output=csv" ),
			'pubhtml gid'    => array( "https://docs.google.com/spreadsheets/d/e/{$pub}/pubhtml?gid=9&single=true", "https://docs.google.com/spreadsheets/d/e/{$pub}/pub?output=csv&gid=9&single=true" ),
			'gviz unchanged' => array( "https://docs.google.com/spreadsheets/d/{$id}/gviz/tq?tqx=out:csv&sheet=Data", "https://docs.google.com/spreadsheets/d/{$id}/gviz/tq?tqx=out:csv&sheet=Data" ),
			'other host'     => array( 'https://example.com/spreadsheets/d/x/edit', 'https://example.com/spreadsheets/d/x/edit' ),
			'bad gid'        => array( "https://docs.google.com/spreadsheets/d/{$id}/edit#gid=1;drop", "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv&gid=1" ),
		);
	}
}
