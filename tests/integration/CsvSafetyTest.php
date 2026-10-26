<?php
/**
 * How neutralized formulas and shortcodes are stored, shown in the editor, and rendered.
 *
 * @package TableRefresh
 */

use TableRefresh\Sync_Service;

/**
 * @covers \TableRefresh\Sync_Service::sanitize_cells
 */
class CsvSafetyTest extends Integration_Test_Case {

	private function neutralize( string $cell ): string {
		return Sync_Service::sanitize_cells( array( array( $cell ) ), false, false )[0][0];
	}

	/**
	 * @dataProvider stored_representations
	 */
	public function test_stored_representation( string $source, string $stored ) {
		$this->assertSame( $stored, $this->neutralize( $source ) );
	}

	public function stored_representations(): array {
		return array(
			'formula'                  => array( '=1+1', "'=1+1" ),
			'formula with function'    => array( '=SUM(A1:A3)', "'=SUM(A1:A3)" ),
			'legacy formula notation'  => array( '=Total {A1+B1}', "'=Total {A1+B1}" ),
			'lone equals sign'         => array( '=', '=' ),
			'equals not first'         => array( 'a=b', 'a=b' ),
			'space before equals'      => array( ' =1+1', ' =1+1' ),
			'already escaped formula'  => array( "'=1+1", "'=1+1" ),
			'shortcode'                => array( '[gallery ids="1"]', '&#091;gallery ids="1"]' ),
			'self-closing shortcode'   => array( '[table id=1 /]', '&#091;table id=1 /]' ),
			'enclosing shortcode'      => array( '[b]x[/b]', '&#091;b]x[/b]' ),
			'escaped shortcode'        => array( '[[gallery]]', '[&#091;gallery]]' ),
			'footnote'                 => array( 'Text [1]', 'Text &#091;1]' ),
			'bracket then space'       => array( 'a [ b ]', 'a [ b ]' ),
			'empty brackets'           => array( 'list[]', 'list[]' ),
			'closing tag only'         => array( '[/b]', '[/b]' ),
			'bracket then equals'      => array( '[=x]', '[=x]' ),
			'bracket at end'           => array( 'end [', 'end [' ),
			'unicode shortcode name'   => array( '[ü]', '&#091;ü]' ),
			'formula and shortcode'    => array( '=[x]', "'=&#091;x]" ),
		);
	}

	public function test_editor_shows_readable_values_and_page_renders_original_text() {
		add_shortcode( 'tablerefresh_probe', static fn() => 'SHORTCODE-EXECUTED' );
		$table_id = $this->create_table( array( array( 'a' ) ) );
		$csv      = "Formula,Shortcode,Brackets,Escaped\n=1+1,[tablerefresh_probe],a [ b ] list[],[[tablerefresh_probe]]\n";
		$this->assertSame( 'updated', $this->sync( $table_id, $this->fixture( 'safety.csv', $csv ) )['status'] );

		// What the TablePress editor shows (data embedded in the real edit screen).
		$editor = $this->open_editor( $table_id, $this->login_cookie() );
		$this->assertSame( array( "'=1+1", '&#091;tablerefresh_probe]', 'a [ b ] list[]', '[&#091;tablerefresh_probe]]' ), $editor['data'][1] );

		// What visitors see: the original text, nothing evaluated or executed, even after a second
		// do_shortcode() pass over the rendered table (as some themes and page builders do).
		$html = do_shortcode( do_shortcode( '[table id=' . $table_id . ' cache_table_output=false /]' ) );
		$this->assertStringContainsString( '>=1+1<', $html );
		$this->assertStringNotContainsString( '>2<', $html );
		$this->assertStringNotContainsString( 'SHORTCODE-EXECUTED', $html );
		$this->assertSame( 'a [ b ] list[]', html_entity_decode( $this->cell( $html, 2, 3 ) ) );
		$this->assertSame( '[tablerefresh_probe]', html_entity_decode( $this->cell( $html, 2, 2 ) ) );
		$this->assertSame( '[[tablerefresh_probe]]', html_entity_decode( $this->cell( $html, 2, 4 ) ) );
		remove_shortcode( 'tablerefresh_probe' );
	}

	public function test_protection_holds_when_tablerefresh_is_inactive() {
		// The protection is stored in the data: rendering does not depend on TableRefresh hooks.
		// [table-info] is a TablePress shortcode, so it exists in the separate process too.
		$table_id = $this->create_table( array( array( 'a' ) ), array( 'name' => 'Secret name' ) );
		$this->sync( $table_id, $this->fixture( 'inactive.csv', "x,y\n=2*3,[table-info id={$table_id} field=name /]\n" ) );

		$run = $this->run_cli_raw( array( 'eval', 'echo do_shortcode( "[table id=' . $table_id . ' cache_table_output=false /]" );', '--skip-plugins=tablerefresh' ) );
		$this->assertStringContainsString( 'tablepress-id-' . $table_id, $run, $run );
		$this->assertStringContainsString( '>=2*3<', $run );
		$this->assertStringNotContainsString( '>6<', $run );
		$this->assertStringContainsString( '&#091;table-info id=' . $table_id . ' field=name /]', $run );
		$this->assertStringNotContainsString( 'Secret name', $run );
	}

	/**
	 * Inner HTML of a rendered TablePress cell.
	 *
	 * @param string $html   Rendered table.
	 * @param int    $row    Row number (1-based, as in TablePress's CSS classes).
	 * @param int    $column Column number (1-based).
	 * @return string
	 */
	private function cell( string $html, int $row, int $column ): string {
		$this->assertSame( 1, preg_match( '#<tr class="row-' . $row . '[^"]*">(.*?)</tr>#s', $html, $tr ) );
		$this->assertSame( 1, preg_match( '#<t[dh] class="column-' . $column . '">(.*?)</t[dh]>#s', $tr[1], $td ) );
		return $td[1];
	}

	/**
	 * Runs a plain WP-CLI command and returns its output.
	 *
	 * @param string[] $args Arguments after "wp".
	 * @return string
	 */
	private function run_cli_raw( array $args ): string {
		$process = proc_open( array_merge( array( 'wp', '--path=' . TABLEREFRESH_TESTS_WP_DIR ), $args ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$out     = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		proc_close( $process );
		return (string) $out;
	}
}
