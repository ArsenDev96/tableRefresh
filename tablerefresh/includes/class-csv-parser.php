<?php
/**
 * Strict CSV parser.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Parses and validates a complete CSV document (RFC 4180) before anything is written.
 *
 * TablePress's own CSV parser is internal and silently repairs malformed input, which
 * could turn a truncated or broken download into a corrupted table. This parser fails
 * instead: unterminated quotes, text after a closing quote, invalid UTF-8, binary
 * content, empty documents, and documents over the size limits are errors.
 *
 * Accepted leniencies: LF, CRLF, or CR line endings; a UTF-8 BOM; literal quote
 * characters inside unquoted fields (e.g. `5" screen`); rows with fewer or more
 * columns (padded with empty cells unless `allow_ragged` is false).
 */
final class Csv_Parser {

	/**
	 * Allowed field delimiters.
	 */
	const DELIMITERS = array( ',', ';', "\t", '|' );

	/**
	 * Parses a CSV string.
	 *
	 * @param string               $csv  CSV document.
	 * @param array<string, mixed> $args {
	 *     Optional.
	 *
	 *     @type string $delimiter      One of DELIMITERS. Default ','.
	 *     @type int    $max_rows       Default 20000.
	 *     @type int    $max_columns    Default 200.
	 *     @type int    $max_cells      Default 500000.
	 *     @type int    $max_cell_bytes Default 65535.
	 *     @type bool   $allow_ragged   Pad short rows instead of failing. Default true.
	 * }
	 * @return array{data: string[][], rows: int, columns: int, padded_rows: int}|WP_Error
	 */
	public static function parse( string $csv, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'delimiter'      => ',',
				'max_rows'       => 20000,
				'max_columns'    => 200,
				'max_cells'      => 500000,
				'max_cell_bytes' => 65535,
				'allow_ragged'   => true,
			)
		);

		$delimiter = (string) $args['delimiter'];
		if ( ! in_array( $delimiter, self::DELIMITERS, true ) ) {
			return self::error( 'tablerefresh_csv_delimiter', __( 'Unsupported CSV delimiter.', 'tablerefresh' ) );
		}

		if ( "\xEF\xBB\xBF" === substr( $csv, 0, 3 ) ) {
			$csv = substr( $csv, 3 );
		}
		if ( '' === trim( $csv ) ) {
			return self::error( 'tablerefresh_csv_empty', __( 'The CSV data is empty.', 'tablerefresh' ) );
		}
		if ( false !== strpos( $csv, "\0" ) ) {
			return self::error( 'tablerefresh_csv_binary', __( 'The data contains binary content and is not a CSV file.', 'tablerefresh' ) );
		}
		if ( 1 !== preg_match( '//u', $csv ) ) {
			return self::error( 'tablerefresh_csv_encoding', __( 'The CSV data is not valid UTF-8. Export the file as "CSV UTF-8".', 'tablerefresh' ) );
		}

		$length = strlen( $csv );
		$stops  = $delimiter . "\r\n";
		$rows   = array();
		$row    = array();
		$line   = 1;
		$pos    = 0;

		while ( true ) {
			// Read one field.
			if ( $pos < $length && '"' === $csv[ $pos ] ) {
				$start_line = $line;
				$value      = '';
				++$pos;
				while ( true ) {
					$quote = strpos( $csv, '"', $pos );
					if ( false === $quote ) {
						/* translators: %d: line number. */
						return self::error( 'tablerefresh_csv_unterminated_quote', sprintf( __( 'A quoted field starting on line %d is never closed. The file may be truncated or malformed.', 'tablerefresh' ), $start_line ) );
					}
					$chunk  = substr( $csv, $pos, $quote - $pos );
					$line  += self::count_line_breaks( $chunk );
					$value .= $chunk;
					if ( $quote + 1 < $length && '"' === $csv[ $quote + 1 ] ) {
						$value .= '"';
						$pos    = $quote + 2;
						continue;
					}
					$pos = $quote + 1;
					break;
				}
				if ( $pos < $length && false === strpos( $stops, $csv[ $pos ] ) ) {
					/* translators: %d: line number. */
					return self::error( 'tablerefresh_csv_malformed_quote', sprintf( __( 'Unexpected text after a closing quote on line %d.', 'tablerefresh' ), $line ) );
				}
			} else {
				$span  = strcspn( $csv, $stops, $pos );
				$value = (string) substr( $csv, $pos, $span );
				$pos  += $span;
			}

			if ( strlen( $value ) > (int) $args['max_cell_bytes'] ) {
				/* translators: %d: line number. */
				return self::error( 'tablerefresh_csv_limit', sprintf( __( 'A cell on line %d exceeds the maximum cell size.', 'tablerefresh' ), $line ) );
			}
			$row[] = $value;
			if ( count( $row ) > (int) $args['max_columns'] ) {
				return self::error( 'tablerefresh_csv_limit', __( 'The CSV data has more columns than allowed.', 'tablerefresh' ) );
			}

			// Field terminator: delimiter, line break, or end of data.
			if ( $pos < $length && $delimiter === $csv[ $pos ] ) {
				++$pos;
				continue; // A trailing delimiter at the very end yields one more empty field.
			}

			$rows[] = $row;
			$row    = array();
			if ( count( $rows ) > (int) $args['max_rows'] ) {
				return self::error( 'tablerefresh_csv_limit', __( 'The CSV data has more rows than allowed.', 'tablerefresh' ) );
			}

			if ( $pos >= $length ) {
				break;
			}
			$pos += ( "\r" === $csv[ $pos ] && $pos + 1 < $length && "\n" === $csv[ $pos + 1 ] ) ? 2 : 1;
			++$line;
			if ( $pos >= $length ) {
				break; // Final line break does not start a new row.
			}
		}

		// Drop blank lines at the end of the file (but keep rows like ",,", which are empty sheet rows).
		$row_count = count( $rows );
		while ( $row_count > 1 && array( '' ) === $rows[ $row_count - 1 ] ) {
			array_pop( $rows );
			--$row_count;
		}

		$has_content = false;
		$columns     = 0;
		foreach ( $rows as $cells ) {
			$columns = max( $columns, count( $cells ) );
			if ( ! $has_content && '' !== trim( implode( '', $cells ) ) ) {
				$has_content = true;
			}
		}
		if ( ! $has_content ) {
			return self::error( 'tablerefresh_csv_empty', __( 'The CSV data contains no values.', 'tablerefresh' ) );
		}
		if ( count( $rows ) * $columns > (int) $args['max_cells'] ) {
			return self::error( 'tablerefresh_csv_limit', __( 'The CSV data has more cells than allowed.', 'tablerefresh' ) );
		}

		$padded = 0;
		foreach ( $rows as $index => $cells ) {
			if ( count( $cells ) === $columns ) {
				continue;
			}
			if ( ! $args['allow_ragged'] ) {
				/* translators: 1: row number, 2: number of columns found, 3: expected number of columns. */
				return self::error( 'tablerefresh_csv_ragged', sprintf( __( 'Row %1$d has %2$d columns, expected %3$d.', 'tablerefresh' ), $index + 1, count( $cells ), $columns ) );
			}
			$rows[ $index ] = array_pad( $cells, $columns, '' );
			++$padded;
		}

		return array(
			'data'        => $rows,
			'rows'        => count( $rows ),
			'columns'     => $columns,
			'padded_rows' => $padded,
		);
	}

	/**
	 * Counts line breaks (LF, CRLF, or lone CR) in a string.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function count_line_breaks( string $text ): int {
		return substr_count( $text, "\n" ) + substr_count( $text, "\r" ) - substr_count( $text, "\r\n" );
	}

	/**
	 * Builds an error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function error( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message );
	}
}
