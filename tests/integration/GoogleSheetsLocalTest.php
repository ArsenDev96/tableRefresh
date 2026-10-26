<?php
/**
 * Deterministic regression tests for Google Sheets responses, without internet access.
 *
 * The fixture server reproduces what Google sends for a public sheet's CSV export (captured
 * from docs.google.com): a 307 redirect with "Content-Type: application/binary" to
 * googleusercontent.com, then "text/csv; charset=utf-8" as an attachment, with CRLF line
 * endings and no line break after the last row.
 *
 * @package TableRefresh
 */

/**
 * @covers \TableRefresh\Http_Fetcher
 * @covers \TableRefresh\Sync_Service
 */
class GoogleSheetsLocalTest extends Integration_Test_Case {

	/**
	 * Writes a sheet export and returns its "docs.google.com" (fixture) export URL.
	 *
	 * @param string[][] $rows Rows.
	 * @return string
	 */
	private function google_export( array $rows ): string {
		$lines = array();
		foreach ( $rows as $row ) {
			$lines[] = implode( ',', array_map( static fn( $cell ) => preg_match( '/[",\r\n]/', $cell ) ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell, $row ) );
		}
		$url = $this->fixture( 'sheet.csv', implode( "\r\n", $lines ) ); // Google: CRLF, no final EOL.
		return str_replace( '/data/', '/google/export/', $url );
	}

	public function test_google_shaped_export_is_imported_repeated_and_changed() {
		$table_id = $this->create_table( array( array( 'placeholder' ) ) );
		$rows     = array(
			array( 'Name', 'Note' ),
			array( 'Alexandra', 'Says "hi", twice' ),
			array( 'Andrew', "Two\r\nlines" ),
		);
		$url      = $this->google_export( $rows );

		$result = $this->sync( $table_id, $url );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'updated', $result['status'] );
		$this->assertSame( 1, $result['redirects'] );
		$this->assertSame( 'text/csv', ( new TableRefresh\Http_Fetcher() )->fetch( $url )['content_type'] );
		$this->assertSame( $rows, $this->load_table( $table_id )['data'] );

		$this->assertSame( 'unchanged', $this->sync( $table_id, $url )['status'] );

		$rows[1][1] = 'Edited in the sheet';
		$rows[]     = array( 'Anna', 'New row' );
		$this->google_export( $rows );
		$this->assertSame( 'updated', $this->sync( $table_id, $url )['status'] );
		$this->assertSame( $rows, $this->load_table( $table_id )['data'] );
	}

	public function test_google_sign_in_redirect_and_missing_sheet() {
		$table_id = $this->create_table( array( array( 'keep' ) ) );
		$before   = $this->raw_state( $table_id );
		$this->assertFailedUnchanged( $this->sync( $table_id, 'http://fixtures/redirect?code=307&to=' . rawurlencode( 'https://accounts.google.com/ServiceLogin?continue=https://docs.google.com/' ) ), 'tablerefresh_google_sheet_not_public', $table_id, $before );
		$this->assertFailedUnchanged( $this->sync( $table_id, 'http://fixtures/google/export/missing.csv' ), 'tablerefresh_http_status', $table_id, $before );
	}
}
