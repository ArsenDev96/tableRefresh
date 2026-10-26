<?php
/**
 * Live test against a real public Google Sheet. Needs internet access; excluded by default.
 *
 * Run with: sh dev/run-tests.sh --group external
 *
 * Set TABLEREFRESH_LIVE_SHEET_URL to the browser URL of a sheet shared as "Anyone with the
 * link can view" (first cell of the first row must not be empty).
 *
 * @package TableRefresh
 * @group external
 */

/**
 * @group external
 */
class GoogleSheetsLiveTest extends Integration_Test_Case {

	public function test_public_google_sheet_syncs_through_redirects() {
		$sheet = (string) getenv( 'TABLEREFRESH_LIVE_SHEET_URL' );
		if ( '' === $sheet ) {
			$this->markTestSkipped( 'TABLEREFRESH_LIVE_SHEET_URL is not set.' );
		}
		self::$block_fixture_host = true; // Production SSRF policy only: no private hosts allowed.
		$table_id = $this->create_table( array( array( 'placeholder' ) ) );

		$result = $this->sync( $table_id, $sheet );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '' );
		$this->assertSame( 'updated', $result['status'] );
		$this->assertStringContainsString( '/export?format=csv', $result['fetched_url'] );
		$this->assertGreaterThanOrEqual( 1, $result['redirects'], 'Google redirects export URLs to googleusercontent.com.' );
		$this->assertStringContainsString( 'googleusercontent.com', $result['final_url'] );

		$data = $this->load_table( $table_id )['data'];
		$this->assertNotSame( '', $data[0][0] );

		$this->assertSame( 'unchanged', $this->sync( $table_id, $sheet )['status'] );
	}

	public function test_unavailable_google_sheet_fails_cleanly() {
		// Google's former public Sheets API sample; it answered 404 when this test was written.
		$table_id = $this->create_table( array( array( 'keep' ) ) );
		$before   = $this->raw_state( $table_id );
		$result   = $this->sync( $table_id, 'https://docs.google.com/spreadsheets/d/1BxiMVs0XlA5qLY9IhUO2pvRUGnVKKnmmNYeYrzw8Gnk/edit#gid=0' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( $result->get_error_code(), array( 'tablerefresh_http_status', 'tablerefresh_google_sheet_not_public', 'tablerefresh_http_html' ), $result->get_error_code() );
		$this->assertSame( $before, $this->raw_state( $table_id ) );
	}

	public function test_private_or_missing_google_sheet_fails_cleanly() {
		$table_id = $this->create_table( array( array( 'keep' ) ) );
		$before   = $this->raw_state( $table_id );
		$result   = $this->sync( $table_id, 'https://docs.google.com/spreadsheets/d/1ThisSheetDoesNotExist000000000000000000000/edit' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( $result->get_error_code(), array( 'tablerefresh_http_status', 'tablerefresh_google_sheet_not_public', 'tablerefresh_http_html' ), $result->get_error_code() );
		$this->assertSame( $before, $this->raw_state( $table_id ) );
	}
}
