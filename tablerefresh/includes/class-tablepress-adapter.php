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
	 * Post meta keys that make up the stored state of a table, as snapshotted and restored.
	 * TableRefresh's own sync baseline is included, so that it can never disagree with the data.
	 */
	const SNAPSHOT_META_KEYS = array( self::OPTIONS_META_KEY, self::VISIBILITY_META_KEY, Sync_State::META_KEY );

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
	 * TablePress has no supported API for this. It keeps the table-ID-to-post-ID map in its
	 * `tablepress_tables` option (JSON, unchanged since TablePress 1.0) and exposes it only
	 * through the public `_debug_get_tables()` method, with the option as fallback. Callers
	 * must confirm the result with verify_post(), so a changed map format leads to a refused
	 * sync instead of a write to the wrong post.
	 *
	 * @param string $table_id Table ID.
	 * @return int Post ID, or 0 if unknown.
	 */
	public static function get_post_id( string $table_id ): int {
		$tables = self::tables_map();
		return isset( $tables['table_post'][ $table_id ] ) && is_numeric( $tables['table_post'][ $table_id ] ) ? (int) $tables['table_post'][ $table_id ] : 0;
	}

	/**
	 * Returns the ID of the table stored in a post (the reverse of get_post_id()).
	 *
	 * The post ID stays the same when a table ID is changed in the TablePress editor.
	 *
	 * @param int $post_id Table post ID.
	 * @return string Table ID, or '' if no table is stored in that post.
	 */
	public static function get_table_id( int $post_id ): string {
		$tables = self::tables_map();
		if ( $post_id <= 0 || ! isset( $tables['table_post'] ) || ! is_array( $tables['table_post'] ) ) {
			return '';
		}
		$table_id = array_search( $post_id, array_map( 'intval', $tables['table_post'] ), true );
		return false === $table_id ? '' : (string) $table_id;
	}

	/**
	 * TablePress's map of table IDs to post IDs (see get_post_id()).
	 *
	 * @return array<string, mixed>
	 */
	private static function tables_map(): array {
		if ( method_exists( \TablePress::$model_table, '_debug_get_tables' ) ) {
			$tables = \TablePress::$model_table->_debug_get_tables();
		} else {
			$tables = json_decode( (string) get_option( 'tablepress_tables', '' ), true );
		}
		return is_array( $tables ) ? $tables : array();
	}

	/**
	 * URL of a TablePress admin screen, wherever the TablePress menu is placed.
	 *
	 * @param array<string, string> $params Screen parameters, e.g. action "edit" and table_id.
	 * @return string
	 */
	public static function admin_url( array $params ): string {
		if ( isset( \TablePress::$controller ) && method_exists( '\TablePress', 'url' ) ) {
			return \TablePress::url( $params );
		}
		return add_query_arg( array_map( 'rawurlencode', $params ), admin_url( 'admin.php?page=tablepress' ) );
	}

	/**
	 * IDs and names of all tables, without loading their data.
	 *
	 * @return array<string, string> Table ID => name.
	 */
	public static function list_tables(): array {
		$tables = array();
		foreach ( \TablePress::$model_table->load_all( false ) as $table_id ) {
			$table = \TablePress::$model_table->load( (string) $table_id, false, false );
			if ( is_array( $table ) ) {
				$tables[ (string) $table_id ] = (string) $table['name'];
			}
		}
		return $tables;
	}

	/**
	 * The post type of TablePress tables (filterable in TablePress via `tablepress_post_type`).
	 *
	 * @return string
	 */
	public static function post_type(): string {
		/** This filter is documented in TablePress's models/model-post.php. */
		return (string) apply_filters( 'tablepress_post_type', 'tablepress_table' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- TablePress's own filter, applied to get the same value.
	}

	/**
	 * Confirms that a raw snapshot is the storage post of a table loaded through TablePress.
	 *
	 * @param array<string, string|null> $snapshot Result of read_snapshot().
	 * @param array<string, mixed>       $table    Table from load().
	 * @return WP_Error|null
	 */
	public static function verify_post( array $snapshot, array $table ): ?WP_Error {
		if ( self::post_type() !== $snapshot['post_type'] || json_decode( (string) $snapshot['post_content'], true ) !== $table['data'] ) {
			return new WP_Error( 'tablerefresh_table_mapping_invalid', __( 'The storage post of the table could not be verified. Nothing was changed.', 'tablerefresh' ) );
		}
		return null;
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
	 * @param int  $post_id    Table post ID.
	 * @param bool $for_update Lock the rows until the current transaction ends (SELECT ... FOR UPDATE).
	 *                         Every TablePress save updates the post row first, so this serializes
	 *                         against concurrent saves from the TablePress editor.
	 * @return array<string, string|null>|null Null if the post does not exist or could not be read
	 *                                         (then `$wpdb->last_error` is set).
	 */
	public static function read_snapshot( int $post_id, bool $for_update = false ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Must bypass caches to see what is really stored.
		if ( $for_update ) {
			$post = $wpdb->get_row( $wpdb->prepare( "SELECT post_type, post_title, post_excerpt, post_content, post_modified, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $post_id ), ARRAY_A );
		} else {
			$post = $wpdb->get_row( $wpdb->prepare( "SELECT post_type, post_title, post_excerpt, post_content, post_modified, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $post_id ), ARRAY_A );
		}
		if ( null === $post ) {
			return null;
		}
		if ( $for_update ) {
			$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN (%s, %s, %s) ORDER BY meta_id FOR UPDATE", $post_id, self::OPTIONS_META_KEY, self::VISIBILITY_META_KEY, Sync_State::META_KEY ), ARRAY_A );
		} else {
			$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN (%s, %s, %s) ORDER BY meta_id", $post_id, self::OPTIONS_META_KEY, self::VISIBILITY_META_KEY, Sync_State::META_KEY ), ARRAY_A );
		}
		// phpcs:enable
		if ( '' !== (string) $wpdb->last_error || ! is_array( $meta ) ) {
			return null; // A failed query returns no rows, which would look like a table without options.
		}

		$snapshot = $post;
		foreach ( self::SNAPSHOT_META_KEYS as $meta_key ) {
			$snapshot[ $meta_key ] = null;
		}
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

		foreach ( self::SNAPSHOT_META_KEYS as $meta_key ) {
			self::write_meta( $post_id, $meta_key, isset( $snapshot[ $meta_key ] ) ? $snapshot[ $meta_key ] : null );
		}

		self::clear_caches( $table_id, $post_id );

		return self::snapshots_equal( self::read_snapshot( $post_id ), $snapshot );
	}

	/**
	 * Writes (or deletes, for null) a single post meta row raw, without filters or caches.
	 *
	 * @param int         $post_id  Post ID.
	 * @param string      $meta_key Meta key.
	 * @param string|null $value    Raw value, or null to delete.
	 */
	public static function write_meta( int $post_id, string $meta_key, ?string $value ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery -- Raw write of one post's meta row (indexed by post_id); caches are cleared by the caller.
		$wpdb->delete(
			$wpdb->postmeta,
			array(
				'post_id'  => $post_id,
				'meta_key' => $meta_key,
			),
			array( '%d', '%s' )
		);
		if ( null !== $value ) {
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $post_id,
					'meta_key'   => $meta_key,
					'meta_value' => $value,
				),
				array( '%d', '%s', '%s' )
			);
		}
		// phpcs:enable
		wp_cache_delete( $post_id, 'post_meta' );
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
		foreach ( array_merge( array( 'post_title', 'post_excerpt', 'post_content' ), self::SNAPSHOT_META_KEYS ) as $key ) {
			if ( ( isset( $a[ $key ] ) ? $a[ $key ] : null ) !== ( isset( $b[ $key ] ) ? $b[ $key ] : null ) ) {
				return false;
			}
		}
		return true;
	}
}
