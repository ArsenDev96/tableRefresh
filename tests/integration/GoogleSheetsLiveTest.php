<?php
/**
 * Live tests against real, public Google Sheets. Need internet access; excluded by default.
 *
 * Run with: sh dev/run-tests.sh --group external
 *
 * By default this uses Google's public "Class Data" sample spreadsheet from the Google Sheets
 * API documentation (fictional students; read-only for everyone). Set TABLEREFRESH_LIVE_SHEET_URL
 * to the browser URL of another sheet shared as "Anyone with the link can view" to use that.
 *
 * Google's sample cannot be edited by anyone else, so "changed data" is tested with different
 * live responses for the same sheet (Google Visualization API queries returning other rows and
 * columns), which is what a sync sees when a sheet is edited.
 *
 * @package TableRefresh
 * @group external
 */


/**
 * @group external
 */
class GoogleSheetsLiveTest extends Integration_Test_Case {

	const SAMPLE_ID = '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms';

	private function sheet_url(): string {
		$url = (string) getenv( 'TABLEREFRESH_LIVE_SHEET_URL' );
		return '' !== $url ? $url : 'https://docs.google.com/spreadsheets/d/' . self::SAMPLE_ID . '/edit?usp=sharing#gid=0';
	}

	private function gviz( string $query ): string {
		return 'https://docs.google.com/spreadsheets/d/' . self::SAMPLE_ID . '/gviz/tq?tqx=out:csv&tq=' . rawurlencode( $query );
	}

	protected function setUp(): void {
		parent::setUp();
		self::$block_fixture_host = true; // Production SSRF policy: no private hosts allowed.
	}

	private function assertUpdated( $result ): array {
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '' );
		$this->assertSame( 'updated', $result['status'] );
		$this->assertTrue( $result['atomic'] );
		return $result;
	}

	public function test_sharing_url_is_converted_and_imported_through_googles_redirect() {
		$table_id = $this->create_table( array( array( 'placeholder' ) ) );
		$result   = $this->assertUpdated( $this->sync( $table_id, $this->sheet_url() ) );

		$this->assertMatchesRegularExpression( '#^https://docs\.google\.com/spreadsheets/d/[A-Za-z0-9_-]+/export\?format=csv(&gid=\d+)?$#', $result['fetched_url'] );
		$this->assertGreaterThanOrEqual( 1, $result['redirects'], 'Google redirects export URLs.' );
		$this->assertStringContainsString( '.googleusercontent.com/', $result['final_url'] );

		$data = $this->load_table( $table_id )['data'];
		$this->assertGreaterThan( 1, count( $data ) );
		$this->assertNotSame( '', $data[0][0] );
		if ( '' === (string) getenv( 'TABLEREFRESH_LIVE_SHEET_URL' ) ) {
			$this->assertSame( array( 'Student Name', 'Gender', 'Class Level', 'Home State', 'Major', 'Extracurricular Activity' ), $data[0] );
			$this->assertSame( 31, count( $data ), '30 students plus the header row.' );
		}
		fwrite( STDERR, sprintf( "\n[google] %d rows x %d columns, %d bytes, %d redirect(s), final host %s\n", $result['rows'], $result['columns'], $result['bytes'], $result['redirects'], wp_parse_url( $result['final_url'], PHP_URL_HOST ) ) );
	}

	public function test_repeated_and_changed_google_data() {
		$table_id  = $this->create_table( array( array( 'placeholder' ) ) );
		$sheet     = $this->sheet_url();
		$this->assertUpdated( $this->sync( $table_id, $sheet ) );
		$state     = $this->raw_state( $table_id );
		$revisions = $this->revision_count( $table_id );

		// Repeated synchronization of unchanged data writes nothing.
		sleep( 1 );
		$this->assertSame( 'unchanged', $this->sync( $table_id, $sheet )['status'] );
		$this->assertSame( $state, $this->raw_state( $table_id ) );
		$this->assertSame( $revisions, $this->revision_count( $table_id ) );

		// Changed data from Google (fewer rows and columns), then more rows, then back.
		// Google's "limit N" counts the header row.
		$this->assertUpdated( $this->sync( $table_id, $this->gviz( 'select A, E limit 3' ) ) );
		$table = $this->load_table( $table_id );
		$this->assertSame( array( array( 'Student Name', 'Major' ), array( 'Alexandra', 'English' ), array( 'Andrew', 'Math' ) ), $table['data'] );
		$this->assertSame( array( 'rows' => array( 1, 1, 1 ), 'columns' => array( 1, 1 ) ), $table['visibility'] );

		$this->assertUpdated( $this->sync( $table_id, $this->gviz( 'select A, E limit 5' ) ) );
		$this->assertCount( 5, $this->load_table( $table_id )['data'] );
		$this->assertSame( 'unchanged', $this->sync( $table_id, $this->gviz( 'select A, E limit 5' ) )['status'] );

		$this->assertUpdated( $this->sync( $table_id, $sheet ) );
		$this->assertSame( $state['post_content'], $this->raw_state( $table_id )['post_content'], 'Back to the full sheet.' );
	}

	public function test_unpublished_sheet_fails_cleanly() {
		// The sample is shared by link but not "published to the web": Google answers 401 (HTML).
		$table_id = $this->create_table( array( array( 'keep' ) ) );
		$before   = $this->raw_state( $table_id );
		$result   = $this->sync( $table_id, 'https://docs.google.com/spreadsheets/d/' . self::SAMPLE_ID . '/pub?output=csv' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( $result->get_error_code(), array( 'tablerefresh_http_status', 'tablerefresh_http_html', 'tablerefresh_google_sheet_not_public' ), $result->get_error_code() );
		$this->assertSame( $before, $this->raw_state( $table_id ) );
	}

	public function test_missing_sheet_fails_cleanly() {
		$table_id = $this->create_table( array( array( 'keep' ) ) );
		$before   = $this->raw_state( $table_id );
		$result   = $this->sync( $table_id, 'https://docs.google.com/spreadsheets/d/1ThisSheetDoesNotExist000000000000000000000/edit' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( $result->get_error_code(), array( 'tablerefresh_http_status', 'tablerefresh_google_sheet_not_public', 'tablerefresh_http_html' ), $result->get_error_code() );
		$this->assertSame( $before, $this->raw_state( $table_id ) );
	}
}
