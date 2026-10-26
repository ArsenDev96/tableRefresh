<?php
/**
 * Synchronization of one table with one source.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads, validates, sanitizes, and applies a CSV source to an existing TablePress table.
 *
 * This is the entry point that a future admin screen or scheduler will call. It performs
 * no capability checks itself; callers must check `tablepress_edit_table` for the table.
 */
final class Sync_Service {

	/**
	 * HTTP fetcher.
	 *
	 * @var Http_Fetcher
	 */
	private Http_Fetcher $fetcher;

	/**
	 * Table updater.
	 *
	 * @var Table_Updater
	 */
	private Table_Updater $updater;

	/**
	 * Constructor.
	 *
	 * @param Http_Fetcher|null  $fetcher Fetcher.
	 * @param Table_Updater|null $updater Updater.
	 */
	public function __construct( ?Http_Fetcher $fetcher = null, ?Table_Updater $updater = null ) {
		$this->fetcher = $fetcher ? $fetcher : new Http_Fetcher();
		$this->updater = $updater ? $updater : new Table_Updater();
	}

	/**
	 * Synchronizes a table with a CSV URL or public Google Sheets URL.
	 *
	 * @param string               $table_id   Existing TablePress table ID.
	 * @param string               $source_url CSV or Google Sheets URL.
	 * @param array<string, mixed> $args {
	 *     Optional.
	 *
	 *     @type string $delimiter       CSV delimiter. Default ','.
	 *     @type bool   $allow_ragged    Pad rows with missing cells. Default true.
	 *     @type bool   $keep_formulas   Keep a leading "=" so TablePress evaluates the cell as a formula. Default false.
	 *     @type bool   $keep_shortcodes Keep "[" and "]" so TablePress runs shortcodes in cells. Default false.
	 *     @type int    $timeout         Download time budget in seconds. Default 15.
	 *     @type int    $max_bytes       Maximum download size. Default 5 MB.
	 * }
	 * @return array<string, mixed>|WP_Error Result with "status" ("updated" or "unchanged") on success.
	 */
	public function sync( string $table_id, string $source_url, array $args = array() ) {
		$started = microtime( true );
		/**
		 * Filters the default sync arguments.
		 *
		 * @param array<string, mixed> $defaults Defaults.
		 * @param string               $table_id Table ID.
		 */
		$defaults = apply_filters(
			'tablerefresh_sync_defaults',
			array(
				'delimiter'       => ',',
				'allow_ragged'    => true,
				'keep_formulas'   => false,
				'keep_shortcodes' => false,
				'timeout'         => 15,
				'max_bytes'       => 5 * MB_IN_BYTES,
			),
			$table_id
		);
		$args     = wp_parse_args( $args, $defaults );

		$result = $this->run( $table_id, $source_url, $args );

		if ( is_wp_error( $result ) ) {
			/**
			 * Fires after a sync failed. The table is unchanged.
			 *
			 * @param string   $table_id Table ID.
			 * @param WP_Error $error    Error.
			 */
			do_action( 'tablerefresh_sync_failed', $table_id, $result );
			return $result;
		}

		$result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		/**
		 * Fires after a sync succeeded.
		 *
		 * @param string               $table_id Table ID.
		 * @param array<string, mixed> $result   Result.
		 */
		do_action( 'tablerefresh_sync_completed', $table_id, $result );
		return $result;
	}

	/**
	 * Finishes an interrupted update of a table, if there is one.
	 *
	 * @param string $table_id Table ID.
	 * @return array{status: string}|WP_Error
	 */
	public function recover( string $table_id ) {
		$error = TablePress_Adapter::availability_error();
		if ( $error ) {
			return $error;
		}
		$lock = Table_Lock::acquire( $table_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			return $this->updater->recover( $table_id );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Runs the sync steps.
	 *
	 * @param string               $table_id   Table ID.
	 * @param string               $source_url URL.
	 * @param array<string, mixed> $args       Arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	private function run( string $table_id, string $source_url, array $args ) {
		$error = TablePress_Adapter::availability_error();
		if ( $error ) {
			return $error;
		}
		if ( ! TablePress_Adapter::table_exists( $table_id ) ) {
			return new WP_Error(
				'tablerefresh_table_not_found',
				/* translators: %s: table ID. */
				sprintf( __( 'TablePress table "%s" does not exist. TableRefresh only updates existing tables.', 'tablerefresh' ), $table_id )
			);
		}

		$url = Google_Sheets::normalize_url( $source_url );

		$lock = Table_Lock::acquire( $table_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$recovery = $this->updater->recover( $table_id );
			if ( is_wp_error( $recovery ) ) {
				return $recovery;
			}

			$download = $this->fetcher->fetch(
				$url,
				array(
					'timeout'   => (int) $args['timeout'],
					'max_bytes' => (int) $args['max_bytes'],
				)
			);
			if ( is_wp_error( $download ) ) {
				return $download;
			}

			$csv = Csv_Parser::parse(
				$download['body'],
				array(
					'delimiter'    => (string) $args['delimiter'],
					'allow_ragged' => (bool) $args['allow_ragged'],
				)
			);
			if ( is_wp_error( $csv ) ) {
				return $csv;
			}

			$data = self::sanitize_cells( $csv['data'], (bool) $args['keep_formulas'], (bool) $args['keep_shortcodes'] );

			$update = $this->updater->update( $table_id, $data, $lock );
			if ( is_wp_error( $update ) ) {
				return $update;
			}
		} finally {
			$lock->release();
		}

		return array(
			'status'        => $update['status'],
			'table_id'      => $table_id,
			'rows'          => $update['rows'],
			'columns'       => $update['columns'],
			'padded_rows'   => $csv['padded_rows'],
			'source_url'    => $source_url,
			'fetched_url'   => $url,
			'final_url'     => $download['final_url'],
			'redirects'     => $download['redirects'],
			'bytes'         => $download['bytes'],
			'transactional' => $update['transactional'],
			'recovery'      => $recovery['status'],
			'took_over'     => $lock->took_over(),
		);
	}

	/**
	 * Makes remote cell content safe to store in TablePress.
	 *
	 * Source content is untrusted, regardless of the capabilities of the user who started
	 * the sync: HTML is filtered with wp_kses_post(); a leading "=" is encoded so that
	 * TablePress does not evaluate it as a formula; "[" and "]" are encoded so that
	 * TablePress does not execute shortcodes from the source. Both encodings are HTML
	 * entities, so the rendered table shows the original characters.
	 *
	 * @param string[][] $data            Rows.
	 * @param bool       $keep_formulas   Keep formulas.
	 * @param bool       $keep_shortcodes Keep shortcodes.
	 * @return string[][]
	 */
	public static function sanitize_cells( array $data, bool $keep_formulas, bool $keep_shortcodes ): array {
		foreach ( $data as $row_index => $row ) {
			foreach ( $row as $column_index => $cell ) {
				$cell = wp_kses_post( $cell );
				if ( ! $keep_shortcodes ) {
					$cell = str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $cell );
				}
				if ( ! $keep_formulas && strlen( $cell ) > 1 && '=' === $cell[0] ) {
					$cell = '&#61;' . substr( $cell, 1 );
				}
				$data[ $row_index ][ $column_index ] = $cell;
			}
		}
		return $data;
	}
}
