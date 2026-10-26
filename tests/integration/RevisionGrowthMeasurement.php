<?php
/**
 * Measures database growth from repeated synchronization (prints numbers, asserts nothing
 * beyond sanity). Shared by RevisionPolicyTest.
 *
 * @package TableRefresh
 */

/**
 * Database growth measurement helper.
 */
trait Revision_Growth_Measurement {

	/**
	 * CSV with the given number of rows and columns; $variant changes every cell.
	 *
	 * @param int $rows    Rows.
	 * @param int $columns Columns.
	 * @param int $variant Variant.
	 * @return string
	 */
	private function generated_csv( int $rows, int $columns, int $variant ): string {
		$lines = array();
		for ( $r = 0; $r < $rows; $r++ ) {
			$cells = array();
			for ( $c = 0; $c < $columns; $c++ ) {
				$cells[] = "v{$variant}-r{$r}-c{$c}-" . str_repeat( 'x', 8 );
			}
			$lines[] = implode( ',', $cells );
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Revisions of a table and their size.
	 *
	 * @param string $table_id Table ID.
	 * @return array{revisions: int, sync_revisions: int, revision_bytes: int, meta_rows: int}
	 */
	private function growth( string $table_id ): array {
		global $wpdb;
		$post_id = TableRefresh\TablePress_Adapter::get_post_id( $table_id );
		$row     = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(post_content)), 0) AS bytes FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision'", $post_id ), ARRAY_A );
		$sync    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_tablerefresh_sync_revision' WHERE p.post_parent = %d AND p.post_type = 'revision'", $post_id ) );
		$meta    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d OR post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d)", $post_id, $post_id ) );
		return array(
			'revisions'      => (int) $row['n'],
			'sync_revisions' => $sync,
			'revision_bytes' => (int) $row['bytes'],
			'meta_rows'      => $meta,
		);
	}
}
