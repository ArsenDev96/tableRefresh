<?php
/**
 * CSV parsing and validation.
 *
 * @package TableRefresh
 */

use TableRefresh\Csv_Parser;

/**
 * @covers \TableRefresh\Csv_Parser
 */
class CsvParserTest extends Integration_Test_Case {

	private function parse( string $csv, array $args = array() ) {
		return Csv_Parser::parse( $csv, $args );
	}

	private function assertParseError( string $code, $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function test_simple_crlf() {
		$result = $this->parse( "a,b\r\n1,2\r\n" );
		$this->assertSame( array( array( 'a', 'b' ), array( '1', '2' ) ), $result['data'] );
		$this->assertSame( 2, $result['rows'] );
		$this->assertSame( 2, $result['columns'] );
	}

	public function test_lf_cr_and_no_trailing_newline() {
		$this->assertSame( array( array( 'a' ), array( 'b' ) ), $this->parse( "a\nb" )['data'] );
		$this->assertSame( array( array( 'a' ), array( 'b' ) ), $this->parse( "a\rb\r" )['data'] );
	}

	public function test_quoted_fields_with_delimiters_quotes_newlines_and_unicode() {
		$csv    = "Product,Description\n\"Widget, large\",\"He said \"\"hi\"\"\"\n\"Multi\r\nline\",Ümläut – 日本\n";
		$result = $this->parse( $csv );
		$this->assertSame(
			array(
				array( 'Product', 'Description' ),
				array( 'Widget, large', 'He said "hi"' ),
				array( "Multi\r\nline", 'Ümläut – 日本' ),
			),
			$result['data']
		);
	}

	public function test_bom_is_removed() {
		$this->assertSame( array( array( 'h1', 'h2' ) ), $this->parse( "\xEF\xBB\xBFh1,h2\n" )['data'] );
	}

	public function test_trailing_delimiter_creates_empty_cell() {
		$this->assertSame( array( array( 'a', 'b', '' ) ), $this->parse( 'a,b,' )['data'] );
	}

	public function test_empty_sheet_rows_are_kept_but_trailing_blank_lines_dropped() {
		$result = $this->parse( "a,b\n,\n1,2\n\n\n" );
		$this->assertSame( array( array( 'a', 'b' ), array( '', '' ), array( '1', '2' ) ), $result['data'] );
	}

	public function test_literal_quote_inside_unquoted_field_is_accepted() {
		$this->assertSame( array( array( 'a', '5" screen' ) ), $this->parse( "a,5\" screen\n" )['data'] );
	}

	public function test_alternative_delimiters() {
		$this->assertSame( array( array( 'a', 'b' ) ), $this->parse( "a;b\n", array( 'delimiter' => ';' ) )['data'] );
		$this->assertSame( array( array( 'a', 'b' ) ), $this->parse( "a\tb\n", array( 'delimiter' => "\t" ) )['data'] );
		$this->assertParseError( 'tablerefresh_csv_delimiter', $this->parse( "a:b\n", array( 'delimiter' => ':' ) ) );
	}

	public function test_empty_and_whitespace_only_input_is_rejected() {
		$this->assertParseError( 'tablerefresh_csv_empty', $this->parse( '' ) );
		$this->assertParseError( 'tablerefresh_csv_empty', $this->parse( "\xEF\xBB\xBF" ) );
		$this->assertParseError( 'tablerefresh_csv_empty', $this->parse( "  \r\n\n\t\n" ) );
		$this->assertParseError( 'tablerefresh_csv_empty', $this->parse( ",,\n , ,\n" ) );
	}

	public function test_unterminated_quote_is_rejected_with_line_number() {
		$result = $this->parse( "a,b\n1,2\n\"open,2\n3,4\n" );
		$this->assertParseError( 'tablerefresh_csv_unterminated_quote', $result );
		$this->assertStringContainsString( 'line 3', $result->get_error_message() );
	}

	public function test_text_after_closing_quote_is_rejected() {
		$this->assertParseError( 'tablerefresh_csv_malformed_quote', $this->parse( "\"a\"b,c\n" ) );
	}

	public function test_invalid_utf8_and_binary_are_rejected() {
		$this->assertParseError( 'tablerefresh_csv_encoding', $this->parse( "a,b\n\xff\xfe,2\n" ) );
		$this->assertParseError( 'tablerefresh_csv_binary', $this->parse( "a,b\n1\x00,2\n" ) );
	}

	public function test_ragged_rows_are_padded_or_rejected_in_strict_mode() {
		$result = $this->parse( "a,b,c\n1,2\n4,5,6,7\n" );
		$this->assertSame( 4, $result['columns'] );
		$this->assertSame( 2, $result['padded_rows'] );
		$this->assertSame( array( '1', '2', '', '' ), $result['data'][1] );

		$this->assertParseError( 'tablerefresh_csv_ragged', $this->parse( "a,b,c\n1,2\n", array( 'allow_ragged' => false ) ) );
	}

	public function test_limits() {
		$this->assertParseError( 'tablerefresh_csv_limit', $this->parse( str_repeat( "a\n", 11 ), array( 'max_rows' => 10 ) ) );
		$this->assertParseError( 'tablerefresh_csv_limit', $this->parse( implode( ',', range( 1, 11 ) ), array( 'max_columns' => 10 ) ) );
		$this->assertParseError( 'tablerefresh_csv_limit', $this->parse( str_repeat( 'x', 101 ), array( 'max_cell_bytes' => 100 ) ) );
		$this->assertParseError( 'tablerefresh_csv_limit', $this->parse( "a,b,c\n1,2,3\n", array( 'max_cells' => 5 ) ) );
		$this->assertIsArray( $this->parse( str_repeat( "a\n", 10 ), array( 'max_rows' => 10 ) ) );
	}
}
