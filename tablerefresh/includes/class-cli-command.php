<?php
/**
 * WP-CLI commands.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

use WP_CLI;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Synchronizes TablePress tables with CSV sources.
 */
final class CLI_Command {

	/**
	 * Replaces the data of an existing TablePress table with a CSV URL or public Google Sheet.
	 *
	 * Table name, description, options, shortcode, and ID are kept. Nothing is changed if
	 * the download or validation fails.
	 *
	 * ## OPTIONS
	 *
	 * <table_id>
	 * : ID of an existing TablePress table.
	 *
	 * <url>
	 * : CSV URL, or a Google Sheets sharing/published URL.
	 *
	 * [--delimiter=<delimiter>]
	 * : Field delimiter: comma, semicolon, tab, or pipe.
	 * ---
	 * default: comma
	 * options:
	 *   - comma
	 *   - semicolon
	 *   - tab
	 *   - pipe
	 * ---
	 *
	 * [--strict-columns]
	 * : Fail if rows have different numbers of columns, instead of padding them.
	 *
	 * [--keep-formulas]
	 * : Let TablePress evaluate cells starting with "=" as formulas.
	 *
	 * [--keep-shortcodes]
	 * : Let TablePress run shortcodes contained in cells.
	 *
	 * [--on-conflict=<on-conflict>]
	 * : What to do if the table data was edited after the last sync, or the table is open in the
	 * TablePress editor.
	 * ---
	 * default: abort
	 * options:
	 *   - abort
	 *   - overwrite
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tablerefresh sync 1 https://example.com/prices.csv
	 *     wp tablerefresh sync 2 "https://docs.google.com/spreadsheets/d/ID/edit#gid=0"
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function sync( array $args, array $assoc_args ): void {
		$delimiters = array(
			'comma'     => ',',
			'semicolon' => ';',
			'tab'       => "\t",
			'pipe'      => '|',
		);
		$delimiter  = WP_CLI\Utils\get_flag_value( $assoc_args, 'delimiter', 'comma' );

		$result = ( new Sync_Service() )->sync(
			(string) $args[0],
			(string) $args[1],
			array(
				'delimiter'       => isset( $delimiters[ $delimiter ] ) ? $delimiters[ $delimiter ] : $delimiter,
				'allow_ragged'    => ! WP_CLI\Utils\get_flag_value( $assoc_args, 'strict-columns', false ),
				'keep_formulas'   => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'keep-formulas', false ),
				'keep_shortcodes' => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'keep-shortcodes', false ),
				'on_conflict'     => (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'on-conflict', 'abort' ),
			)
		);

		$this->output( $result, WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'text' ) );
	}

	/**
	 * Finishes or undoes an interrupted update of a table.
	 *
	 * Normally not needed: every sync does this automatically first.
	 *
	 * ## OPTIONS
	 *
	 * <table_id>
	 * : ID of an existing TablePress table.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function recover( array $args, array $assoc_args ): void {
		$this->output( ( new Sync_Service() )->recover( (string) $args[0] ), WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'text' ) );
	}

	/**
	 * Prints a result and sets the exit code.
	 *
	 * @param array<string, mixed>|WP_Error $result Result.
	 * @param string                        $format "text" or "json".
	 */
	private function output( $result, string $format ): void {
		if ( 'json' === $format ) {
			if ( is_wp_error( $result ) ) {
				$data = array();
				foreach ( array_merge( $result->get_error_codes(), array( 'tablerefresh_reverted', 'tablerefresh_conflict_editing_users' ) ) as $code ) {
					if ( null !== $result->get_error_data( $code ) ) {
						$data[ $code ] = $result->get_error_data( $code );
					}
				}
				WP_CLI::line(
					(string) wp_json_encode(
						array(
							'status'  => 'error',
							'code'    => $result->get_error_code(),
							'codes'   => $result->get_error_codes(),
							'message' => $result->get_error_message(),
							'data'    => $data,
						)
					)
				);
				WP_CLI::halt( 1 );
			}
			WP_CLI::line( (string) wp_json_encode( $result ) );
			return;
		}

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() . ' [' . $result->get_error_code() . ']' );
		}
		foreach ( $result as $key => $value ) {
			WP_CLI::line( sprintf( '%s: %s', $key, (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
		}
		WP_CLI::success( 'Done.' );
	}
}
