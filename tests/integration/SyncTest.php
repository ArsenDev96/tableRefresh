<?php
/**
 * End-to-end synchronization of real TablePress tables.
 *
 * @package TableRefresh
 */

use TableRefresh\TablePress_Adapter;

/**
 * @covers \TableRefresh\Sync_Service
 * @covers \TableRefresh\Table_Updater
 * @covers \TableRefresh\TablePress_Adapter
 */
class SyncTest extends Integration_Test_Case {

	const ORIGINAL = array( array( 'old1', 'old2' ), array( 'a', 'b' ) );

	public function test_successful_import_keeps_id_and_metadata() {
		$table_id   = $this->create_table( self::ORIGINAL, array( 'name' => 'Prices', 'description' => 'Daily prices' ) );
		$before     = $this->load_table( $table_id );
		$post_id    = TablePress_Adapter::get_post_id( $table_id );
		$num_tables = TablePress::$model_table->count_tables();

		$result = $this->sync( $table_id, $this->static_url( 'simple.csv' ) );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'updated', $result['status'] );
		$this->assertTrue( $result['transactional'] );
		$this->assertSame( 3, $result['rows'] );
		$this->assertSame( 3, $result['columns'] );

		$after = $this->load_table( $table_id );
		$this->assertSame( array( array( 'Name', 'Age', 'City' ), array( 'Alice', '30', 'Berlin' ), array( 'Bob', '25', 'Paris' ) ), $after['data'] );
		$this->assertSame( $table_id, $after['id'] );
		$this->assertSame( $post_id, TablePress_Adapter::get_post_id( $table_id ), 'Table must stay in the same post.' );
		$this->assertSame( $num_tables, TablePress::$model_table->count_tables(), 'No table may be added.' );
		$this->assertSame( 'Prices', $after['name'] );
		$this->assertSame( 'Daily prices', $after['description'] );
		$this->assertSame( $before['author'], $after['author'] );
		$this->assertSame( $before['options'], $after['options'] );
		$this->assertSame( array( 'rows' => array( 1, 1, 1 ), 'columns' => array( 1, 1, 1 ) ), $after['visibility'] );
	}

	public function test_quoted_unicode_csv_and_alternative_delimiter() {
		$table_id = $this->create_table( self::ORIGINAL );
		$this->assertIsArray( $this->sync( $table_id, $this->static_url( 'quoted.csv' ) ) );
		$data = $this->load_table( $table_id )['data'];
		$this->assertSame( 'Widget, large', $data[1][0] );
		$this->assertSame( 'He said "hi"', $data[1][1] );
		$this->assertSame( "Multi\nline", $data[2][0] );
		$this->assertSame( 'Ümläut – 日本', $data[2][1] );

		$this->assertIsArray( $this->sync( $table_id, $this->static_url( 'semicolon.csv' ), array( 'delimiter' => ';' ) ) );
		$this->assertSame( array( array( 'a', 'b', 'c' ), array( '1', '2', '3' ) ), $this->load_table( $table_id )['data'] );

		$this->assertIsArray( $this->sync( $table_id, $this->static_url( 'bom.csv' ) ) );
		$this->assertSame( 'h1', $this->load_table( $table_id )['data'][0][0], 'BOM must be stripped.' );
	}

	public function test_repeated_synchronization() {
		$table_id = $this->create_table( self::ORIGINAL );
		$url      = $this->fixture( 'repeat.csv', "x,y\n1,2\n" );

		$this->assertSame( 'updated', $this->sync( $table_id, $url )['status'] );
		$state     = $this->raw_state( $table_id );
		$revisions = $this->revision_count( $table_id );

		sleep( 1 ); // post_modified has one-second resolution.
		$second = $this->sync( $table_id, $url );
		$this->assertSame( 'unchanged', $second['status'] );
		$this->assertSame( $state, $this->raw_state( $table_id ), 'An unchanged source must not write anything.' );
		$this->assertSame( $revisions, $this->revision_count( $table_id ), 'An unchanged source must not create a revision.' );

		$this->fixture( 'repeat.csv', "x,y\n1,3\n" );
		$third = $this->sync( $table_id, $url );
		$this->assertSame( 'updated', $third['status'] );
		$this->assertSame( array( array( 'x', 'y' ), array( '1', '3' ) ), $this->load_table( $table_id )['data'] );
		$this->assertNotSame( $state['post_modified'], $this->raw_state( $table_id )['post_modified'] );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 'unchanged', $this->sync( $table_id, $url )['status'] );
		}
	}

	/**
	 * @dataProvider invalid_sources
	 */
	public function test_invalid_sources_leave_table_untouched( string $url, string $code, array $args = array() ) {
		$table_id = $this->create_table( self::ORIGINAL );
		$before   = $this->raw_state( $table_id );
		$this->assertFailedUnchanged( $this->sync( $table_id, $url, $args ), $code, $table_id, $before );
	}

	public function invalid_sources(): array {
		$static = TABLEREFRESH_TESTS_FIXTURE_URL . '/static/';
		return array(
			'empty file'          => array( $static . 'empty.csv', 'tablerefresh_csv_empty' ),
			'whitespace only'     => array( $static . 'whitespace.csv', 'tablerefresh_csv_empty' ),
			'unterminated quote'  => array( $static . 'unterminated.csv', 'tablerefresh_csv_unterminated_quote' ),
			'invalid utf-8'       => array( $static . 'bad-utf8.csv', 'tablerefresh_csv_encoding' ),
			'nul byte'            => array( $static . 'null-byte.csv', 'tablerefresh_csv_binary' ),
			'binary download'     => array( TABLEREFRESH_TESTS_FIXTURE_URL . '/binary', 'tablerefresh_csv_binary' ),
			'ragged strict'       => array( $static . 'ragged.csv', 'tablerefresh_csv_ragged', array( 'allow_ragged' => false ) ),
			'html page'           => array( TABLEREFRESH_TESTS_FIXTURE_URL . '/html', 'tablerefresh_http_html' ),
			'html as text/csv'    => array( TABLEREFRESH_TESTS_FIXTURE_URL . '/html-as-csv', 'tablerefresh_http_html' ),
			'http 404'            => array( TABLEREFRESH_TESTS_FIXTURE_URL . '/status?code=404', 'tablerefresh_http_status' ),
			'http 500'            => array( TABLEREFRESH_TESTS_FIXTURE_URL . '/status?code=500', 'tablerefresh_http_status' ),
			'dns failure'         => array( 'https://does-not-exist.invalid/a.csv', 'tablerefresh_url_dns' ),
			'timeout'             => array( TABLEREFRESH_TESTS_FIXTURE_URL . '/hang?seconds=10', 'tablerefresh_http_timeout', array( 'timeout' => 2 ) ),
			'too large'           => array( TABLEREFRESH_TESTS_FIXTURE_URL . '/big?bytes=2000000&cl=0', 'tablerefresh_http_too_large', array( 'max_bytes' => 1000000 ) ),
			'ssrf loopback'       => array( 'http://127.0.0.1/a.csv', 'tablerefresh_url_blocked' ),
			'ssrf metadata'       => array( 'http://169.254.169.254/latest/meta-data/', 'tablerefresh_url_blocked' ),
			'ssrf redirect'       => array( TABLEREFRESH_TESTS_FIXTURE_URL . '/redirect?to=http%3A%2F%2F127.0.0.1%2F', 'tablerefresh_url_blocked' ),
			'google not public'   => array( TABLEREFRESH_TESTS_FIXTURE_URL . '/redirect?to=https%3A%2F%2Faccounts.google.com%2FServiceLogin', 'tablerefresh_google_sheet_not_public' ),
		);
	}

	public function test_missing_table_is_not_created() {
		$count  = TablePress::$model_table->count_tables();
		$result = $this->sync( '999999', $this->static_url( 'simple.csv' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'tablerefresh_table_not_found', $result->get_error_code() );
		$this->assertSame( $count, TablePress::$model_table->count_tables() );
	}

	public function test_ragged_rows_are_padded_by_default() {
		$table_id = $this->create_table( self::ORIGINAL );
		$result   = $this->sync( $table_id, $this->static_url( 'ragged.csv' ) );
		$this->assertSame( 2, $result['padded_rows'] );
		$this->assertSame( array( array( 'a', 'b', 'c', '' ), array( '1', '2', '', '' ), array( '4', '5', '6', '7' ) ), $this->load_table( $table_id )['data'] );
	}

	public function test_changing_row_and_column_counts() {
		$table_id = $this->create_table( array( array( 'h1', 'h2', 'h3' ), array( '1', '2', '3' ), array( '4', '5', '6' ) ) );
		// Hide the second row and the third column, like a user would in the TablePress editor.
		$table               = $this->load_table( $table_id );
		$table['visibility'] = array( 'rows' => array( 1, 0, 1 ), 'columns' => array( 1, 1, 0 ) );
		wp_set_current_user( 1 );
		$this->assertSame( $table_id, TablePress::$model_table->save( $table ) );
		wp_set_current_user( 0 );

		$url = $this->fixture( 'dims.csv', "c1,c2,c3,c4,c5\n1,2,3,4,5\n6,7,8,9,10\n11,12,13,14,15\n" );
		$this->assertSame( 'updated', $this->sync( $table_id, $url )['status'] );
		$table = $this->load_table( $table_id );
		$this->assertCount( 4, $table['data'] );
		$this->assertCount( 5, $table['data'][0] );
		$this->assertSame( array( 1, 0, 1, 1 ), $table['visibility']['rows'], 'Existing flags kept positionally, new rows visible.' );
		$this->assertSame( array( 1, 1, 0, 1, 1 ), $table['visibility']['columns'] );

		$this->fixture( 'dims.csv', "only\n" );
		$this->assertSame( 'updated', $this->sync( $table_id, $url )['status'] );
		$table = $this->load_table( $table_id );
		$this->assertSame( array( array( 'only' ) ), $table['data'] );
		$this->assertSame( array( 'rows' => array( 1 ), 'columns' => array( 1 ) ), $table['visibility'] );

		$this->fixture( 'dims.csv', "a,b\n1,2\n" );
		$this->assertSame( 'updated', $this->sync( $table_id, $url )['status'] );
		$this->assertSame( array( 'rows' => array( 1, 1 ), 'columns' => array( 1, 1 ) ), $this->load_table( $table_id )['visibility'] );
	}

	public function test_preserves_tablepress_settings_and_privileged_html_in_name() {
		$options  = array(
			'table_head'                  => 2,
			'table_foot'                  => 1,
			'alternating_row_colors'      => false,
			'row_hover'                   => false,
			'print_name'                  => true,
			'print_name_position'         => 'below',
			'print_description'           => true,
			'extra_css_classes'           => 'my-class other-class',
			'use_datatables'              => true,
			'datatables_sort'             => false,
			'datatables_paginate_entries' => 25,
			'datatables_scrollx'          => true,
			'datatables_custom_commands'  => '"order": [[ 1, "desc" ]], "bLengthChange": false',
		);
		$table_id = $this->create_table(
			self::ORIGINAL,
			array(
				'name'        => 'Name <strong>bold</strong> <script>/* admin-authored */</script>',
				'description' => 'Desc <iframe src="https://example.com/"></iframe>',
				'options'     => $options,
			)
		);

		// Simulate an option stored by a TablePress extension that is not part of the free template.
		$post_id    = TablePress_Adapter::get_post_id( $table_id );
		$stored     = json_decode( get_post_meta( $post_id, '_tablepress_table_options', true ), true );
		$stored['extension_option'] = 'keep-me';
		update_post_meta( $post_id, '_tablepress_table_options', wp_slash( wp_json_encode( $stored, TABLEPRESS_JSON_OPTIONS ) ) );

		$before = $this->raw_state( $table_id );
		$result = $this->sync( $table_id, $this->static_url( 'large-3x5.csv' ) );
		$this->assertSame( 'updated', $result['status'] );
		$after = $this->raw_state( $table_id );

		$this->assertSame( $before['post_title'], $after['post_title'], 'Name must be byte-identical, even though the sync runs without unfiltered_html.' );
		$this->assertSame( $before['post_excerpt'], $after['post_excerpt'] );
		$this->assertSame( $before['_tablepress_table_options'], $after['_tablepress_table_options'], 'Options meta must be byte-identical.' );
		$this->assertNotSame( $before['post_content'], $after['post_content'] );

		$table = $this->load_table( $table_id );
		$this->assertSame( 'keep-me', $table['options']['extension_option'] );
		$this->assertSame( 2, $table['options']['table_head'] );
		$this->assertSame( '"order": [[ 1, "desc" ]], "bLengthChange": false', $table['options']['datatables_custom_commands'] );
		$this->assertSame( 1, $table['options']['last_editor'], 'Last editor stays the human who configured the table.' );
	}

	public function test_shortcode_output_and_output_cache_reflect_new_data() {
		$table_id  = $this->create_table( array( array( 'Header' ), array( 'BEFORE-SYNC' ) ) );
		$shortcode = '[' . TablePress::$shortcode . ' id=' . $table_id . ' /]';

		// Logged out: TablePress caches the rendered HTML in a transient.
		$html = do_shortcode( $shortcode );
		$this->assertStringContainsString( 'BEFORE-SYNC', $html );
		$this->assertStringContainsString( 'id="tablepress-' . $table_id . '"', $html );
		$this->assertNotFalse( get_transient( 'tablepress_c_' . md5( $table_id ) ), 'Output cache should be primed.' );

		$this->sync( $table_id, $this->fixture( 'sc.csv', "Header\nAFTER-SYNC\n" ) );

		$html = do_shortcode( $shortcode );
		$this->assertStringContainsString( 'AFTER-SYNC', $html, 'Cached output must be invalidated.' );
		$this->assertStringNotContainsString( 'BEFORE-SYNC', $html );
		// Second render in the same request: TablePress suffixes the HTML ID ("-no-2") itself.
		$this->assertStringContainsString( 'class="tablepress tablepress-id-' . $table_id . '"', $html, 'Same table ID.' );
		$this->assertStringContainsString( 'AFTER-SYNC', tablepress_get_table( array( 'id' => $table_id ) ) );
	}

	public function test_shortcode_on_a_real_page_served_by_apache() {
		$table_id = $this->create_table( array( array( 'Header' ), array( 'PAGE-BEFORE' ) ) );
		$page_id  = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Shortcode page',
				'post_content' => '[table id=' . $table_id . ' /]',
			)
		);
		try {
			$fetch = static function () use ( $page_id ) {
				$context = stream_context_create( array( 'http' => array( 'header' => "Host: localhost:8089\r\n", 'timeout' => 10 ) ) );
				return (string) file_get_contents( 'http://wordpress/?page_id=' . $page_id, false, $context );
			};
			$this->assertStringContainsString( 'PAGE-BEFORE', $fetch() );

			$this->sync( $table_id, $this->fixture( 'page.csv', "Header\nPAGE-AFTER\n" ) );

			$html = $fetch();
			$this->assertStringContainsString( 'PAGE-AFTER', $html );
			$this->assertStringNotContainsString( 'PAGE-BEFORE', $html );
			$this->assertStringContainsString( 'id="tablepress-' . $table_id . '"', $html );
		} finally {
			wp_delete_post( $page_id, true );
		}
	}

	public function test_remote_html_formulas_and_shortcodes_are_neutralized() {
		wp_set_current_user( 1 ); // Even an administrator with unfiltered_html must not store remote scripts.
		$table_id = $this->create_table( self::ORIGINAL );
		wp_set_current_user( 1 );
		$csv = "a,b\n<script>alert(1)</script>x,<img src=x onerror=alert(1)>\n=1+1,[table id={$table_id} /]\n=,<a href=\"https://example.com\" onclick=\"x()\">link</a>\n";
		$this->assertSame( 'updated', $this->sync( $table_id, $this->fixture( 'xss.csv', $csv ) )['status'] );
		wp_set_current_user( 0 );

		$data = $this->load_table( $table_id )['data'];
		$this->assertSame( 'alert(1)x', $data[1][0] );
		$this->assertSame( '<img src="x">', $data[1][1] );
		$this->assertSame( '&#61;1+1', $data[2][0] );
		$this->assertSame( '&#91;table id=' . $table_id . ' /&#93;', $data[2][1] );
		$this->assertSame( '=', $data[3][0], 'A lone "=" is not a formula.' );
		$this->assertSame( '<a href="https://example.com">link</a>', $data[3][1] );

		$html = do_shortcode( '[table id=' . $table_id . ' cache_table_output=false /]' );
		$this->assertStringContainsString( '&#61;1+1', $html, 'Formula is displayed, not evaluated.' );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertSame( 1, substr_count( $html, '<table ' ), 'Shortcode from the source must not render a nested table.' );
	}

	public function test_formulas_and_shortcodes_can_be_kept_explicitly() {
		$table_id = $this->create_table( self::ORIGINAL );
		$this->sync( $table_id, $this->fixture( 'f.csv', "a,b\n=2*3,[table-info id={$table_id} field=name /]\n" ), array( 'keep_formulas' => true, 'keep_shortcodes' => true ) );
		$html = do_shortcode( '[table id=' . $table_id . ' cache_table_output=false /]' );
		$this->assertStringContainsString( '>6<', $html );
		$this->assertStringContainsString( '>Test table<', $html );
	}
}
