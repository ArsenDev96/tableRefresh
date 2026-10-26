<?php
/**
 * The single place where TableRefresh touches TablePress.
 *
 * Normal writes go through TablePress's public table model (load(), prepare_table(),
 * sanitize(), save()), exactly like TablePress's own "Replace existing table" import.
 * Raw snapshots read and restore the table post and its two meta fields byte for
 * byte; they never interpret the stored format, so they stay valid if TablePress
 * changes how it encodes the data.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter around the free TablePress plugin.
 */
final class TablePress_Adapter {

	/**
	 * Oldest TablePress version that this integration was verified against.
	 */
	const MIN_VERSION = '3.0';

	/**
	 * Post meta keys used by TablePress_Table_Model.
	 */
	const OPTIONS_META_KEY    = '_tablepress_table_options';
	const VISIBILITY_META_KEY = '_tablepress_table_visibility';

	/**
	 * Whether TablePress is loaded, initialized (it boots on `init`), and recent enough.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( 'TablePress', false )
			&& defined( 'TablePress::version' )
			&& version_compare( \TablePress::version, self::MIN_VERSION, '>=' )
			&& isset( \TablePress::$model_table );
	}

	/**
	 * Returns an error if TablePress cannot be used right now.
	 *
	 * @return WP_Error|null
	 */
	public static function availability_error(): ?WP_Error {
		if ( self::is_available() ) {
			return null;
		}
		return new WP_Error(
			'tablerefresh_tablepress_unavailable',
			sprintf(
				/* translators: %s: minimum TablePress version. */
				__( 'TablePress %s or newer must be installed, active, and initialized.', 'tablerefresh' ),
				self::MIN_VERSION
			)
		);
	}

	/**
	 * Whether a table with this ID exists.
	 *
	 * @param string $table_id Table ID.
	 * @return bool
	 */
	public static function table_exists( string $table_id ): bool {
		return '' !== $table_id && \TablePress::$model_table->table_exists( $table_id );
	}

	/**
	 * Loads a table with data, options, and visibility.
	 *
	 * @param string $table_id Table ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function load( string $table_id ) {
		$table = \TablePress::$model_table->load( $table_id, true, true );
		if ( is_wp_error( $table ) ) {
			return new WP_Error(
				'tablerefresh_table_load_failed',
				/* translators: %s: table ID. */
				sprintf( __( 'TablePress table "%s" could not be loaded.', 'tablerefresh' ), $table_id ),
				$table->get_error_codes()
			);
		}
		if ( ! empty( $table['is_corrupted'] ) ) {
			return new WP_Error(
				'tablerefresh_table_corrupted',
				/* translators: %s: table ID. */
				sprintf( __( 'TablePress reports that table "%s" is corrupted. It will not be overwritten.', 'tablerefresh' ), $table_id )
			);
		}
		return $table;
	}

	/**
	 * Returns the post ID that stores a table.
	 *
	 * TablePress keeps the table-ID-to-post-ID map in its `tablepress_tables` option and
	 * only exposes it through the public `_debug_get_tables()` method.
	 *
	 * @param string $table_id Table ID.
	 * @return int Post ID, or 0 if unknown.
	 */
	public static function get_post_id( string $table_id ): int {
		if ( ! method_exists( \TablePress::$model_table, '_debug_get_tables' ) ) {
			return 0;
		}
		$tables = \TablePress::$model_table->_debug_get_tables();
		return isset( $tables['table_post'][ $table_id ] ) ? (int) $tables['table_post'][ $table_id ] : 0;
	}

	/**
	 * Merges new data into an existing table and runs TablePress's consistency checks.
	 *
	 * Mirrors TablePress_Import::import_tablepress_table() for the "replace" type:
	 * keeps name and description, cuts or pads the visibility arrays to the new size.
	 * Unlike that import, all table options are kept exactly as they were.
	 *
	 * @param array<string, mixed> $existing Table as returned by load().
	 * @param string[][]           $data     New, rectangular table data.
	 * @return array<string, mixed>|WP_Error Prepared table.
	 */
	public static function prepare( array $existing, array $data ) {
		$num_rows    = count( $data );
		$num_columns = count( $data[0] );

		$new_table               = $existing;
		$new_table['data']       = $data;
		$new_table['number']     = array(
			'rows'    => $num_rows,
			'columns' => $num_columns,
		);
		$new_table['visibility'] = array(
			'rows'    => array_pad( array_slice( array_map( 'intval', $existing['visibility']['rows'] ), 0, $num_rows ), $num_rows, 1 ),
			'columns' => array_pad( array_slice( array_map( 'intval', $existing['visibility']['columns'] ), 0, $num_columns ), $num_columns, 1 ),
		);

		$table = \TablePress::$model_table->prepare_table( $existing, $new_table, true );
		if ( is_wp_error( $table ) ) {
			return new WP_Error(
				'tablerefresh_table_prepare_failed',
				__( 'TablePress rejected the new table data as inconsistent.', 'tablerefresh' ),
				$table->get_error_codes()
			);
		}

		// prepare_table() drops unknown option keys, rewrites `last_editor`, and converts
		// DataTables custom commands. A sync must not change configuration, so restore it.
		$table['options'] = $existing['options'];

		return $table;
	}

	/**
	 * Returns the data that TablePress will actually store for a table.
	 *
	 * TablePress runs wp_kses_post() over every cell when the current user lacks
	 * `unfiltered_html` (e.g. in WP-CLI or cron).
	 *
	 * @param array<string, mixed> $table Prepared table.
	 * @return string[][]
	 */
	public static function expected_stored_data( array $table ): array {
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$table = \TablePress::$model_table->sanitize( $table );
		}
		return $table['data'];
	}

	/**
	 * Saves a prepared table via TablePress (updates the post, its meta, and output caches).
	 *
	 * @param array<string, mixed> $table Prepared table.
	 * @return true|WP_Error
	 */
	public static function save( array $table ) {
		$result = \TablePress::$model_table->save( $table );
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'tablerefresh_table_save_failed',
				__( 'TablePress could not save the table.', 'tablerefresh' ),
				$result->get_error_codes()
			);
		}
		return true;
	}

	/**
	 * Encodes table data or visibility exactly like TablePress stores it.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function encode( $value ): string {
		$flags = defined( 'TABLEPRESS_JSON_OPTIONS' ) ? TABLEPRESS_JSON_OPTIONS : JSON_UNESCAPED_SLASHES;
		return (string) wp_json_encode( $value, $flags );
	}

	/**
	 * Reads the raw stored state of a table post, bypassing all caches.
	 *
	 * @param int $post_id Table post ID.
	 * @return array<string, string|null>|null Null if the post does not exist.
	 */
	public static function read_snapshot( int $post_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Must bypass caches to see what is really stored.
		$post = $wpdb->get_row( $wpdb->prepare( "SELECT post_title, post_excerpt, post_content, post_modified, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $post_id ), ARRAY_A );
		if ( null === $post ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
		$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN (%s, %s) ORDER BY meta_id", $post_id, self::OPTIONS_META_KEY, self::VISIBILITY_META_KEY ), ARRAY_A );

		$snapshot                              = $post;
		$snapshot[ self::OPTIONS_META_KEY ]    = null;
		$snapshot[ self::VISIBILITY_META_KEY ] = null;
		foreach ( $meta as $row ) {
			if ( null === $snapshot[ $row['meta_key'] ] ) { // TablePress stores one row per key; the first one wins, like get_post_meta().
				$snapshot[ $row['meta_key'] ] = $row['meta_value'];
			}
		}
		return $snapshot;
	}

	/**
	 * Writes a raw snapshot back, byte for byte, and clears all caches for the table.
	 *
	 * Deliberately bypasses wp_update_post() so that no KSES filtering or other
	 * content filters can alter content that a privileged user stored originally.
	 *
	 * @param string                     $table_id Table ID.
	 * @param int                        $post_id  Table post ID.
	 * @param array<string, string|null> $snapshot Snapshot from read_snapshot().
	 * @return bool Whether the stored state now equals the snapshot.
	 */
	public static function restore_snapshot( string $table_id, int $post_id, array $snapshot ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw restore, caches are cleared below.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_title'        => $snapshot['post_title'],
				'post_excerpt'      => $snapshot['post_excerpt'],
				'post_content'      => $snapshot['post_content'],
				'post_modified'     => $snapshot['post_modified'],
				'post_modified_gmt' => $snapshot['post_modified_gmt'],
			),
			array( 'ID' => $post_id ),
			'%s',
			'%d'
		);

		foreach ( array( self::OPTIONS_META_KEY, self::VISIBILITY_META_KEY ) as $meta_key ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery -- Raw restore of one post's two meta rows (indexed by post_id).
			$wpdb->delete(
				$wpdb->postmeta,
				array(
					'post_id'  => $post_id,
					'meta_key' => $meta_key,
				),
				array( '%d', '%s' )
			);
			if ( null !== $snapshot[ $meta_key ] ) {
				$wpdb->insert(
					$wpdb->postmeta,
					array(
						'post_id'    => $post_id,
						'meta_key'   => $meta_key,
						'meta_value' => $snapshot[ $meta_key ],
					),
					array( '%d', '%s', '%s' )
				);
			}
			// phpcs:enable
		}

		self::clear_caches( $table_id, $post_id );

		return self::snapshots_equal( self::read_snapshot( $post_id ), $snapshot );
	}

	/**
	 * Clears object caches and TablePress output caches for a table.
	 *
	 * @param string $table_id Table ID.
	 * @param int    $post_id  Table post ID.
	 */
	public static function clear_caches( string $table_id, int $post_id ): void {
		clean_post_cache( $post_id );
		wp_cache_delete( $post_id, 'post_meta' );
		if ( self::is_available() ) {
			\TablePress::$model_table->invalidate_table_output_cache( $table_id );
		}
	}

	/**
	 * Whether two snapshots describe the same stored table (ignoring modification dates).
	 *
	 * @param array<string, string|null>|null $a Snapshot.
	 * @param array<string, string|null>|null $b Snapshot.
	 * @return bool
	 */
	public static function snapshots_equal( ?array $a, ?array $b ): bool {
		if ( null === $a || null === $b ) {
			return false;
		}
		foreach ( array( 'post_title', 'post_excerpt', 'post_content', self::OPTIONS_META_KEY, self::VISIBILITY_META_KEY ) as $key ) {
			if ( $a[ $key ] !== $b[ $key ] ) {
				return false;
			}
		}
		return true;
	}
}
