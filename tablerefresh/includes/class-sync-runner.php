<?php
/**
 * Runs the configured sync of a table and records the result.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Connects the stored configuration to Sync_Service, for the admin screen and the scheduler.
 *
 * - Manual edits are never overwritten: a sync always runs with on_conflict "abort", except
 *   when an administrator explicitly chooses to replace the edits.
 * - Edits made after the last sync pause automatic sync until an administrator decides.
 * - After MAX_FAILURES failed attempts in a row, automatic sync is paused as well; a failed
 *   attempt is never retried before the next scheduled time.
 * - Attempts that did not run because another sync of the table was in progress are not recorded.
 *
 * Callers check capabilities; the scheduler runs without a user.
 */
final class Sync_Runner {

	/**
	 * Failed attempts in a row after which automatic sync is paused.
	 */
	const MAX_FAILURES = 5;

	/**
	 * Sync service.
	 *
	 * @var Sync_Service
	 */
	private Sync_Service $service;

	/**
	 * Constructor.
	 *
	 * @param Sync_Service|null $service Sync service.
	 */
	public function __construct( ?Sync_Service $service = null ) {
		$this->service = $service ? $service : new Sync_Service();
	}

	/**
	 * Synchronizes a configured table.
	 *
	 * @param int    $post_id Table post ID.
	 * @param string $trigger "manual", "scheduled", "setup" (first sync after setup), or "replace"
	 *                        (an administrator chose to replace manual edits with the source data).
	 * @return array{outcome: string, message: string, time: int, rows: int, columns: int}
	 *         Outcome "updated", "unchanged", "conflict", or "failed" (recorded in the history),
	 *         or "busy", "skipped", "missing" (not recorded).
	 */
	public function run( int $post_id, string $trigger ): array {
		$config   = Sync_Config::get( $post_id );
		$table_id = TablePress_Adapter::is_available() ? TablePress_Adapter::get_table_id( $post_id ) : '';
		if ( null === $config || ( '' === $table_id && TablePress_Adapter::is_available() ) ) {
			Scheduler::apply( $post_id, null ); // Configuration or table removed meanwhile.
			return self::result( 'missing', __( 'This table no longer exists or is not set up for automatic sync.', 'tablerefresh' ) );
		}
		if ( 'scheduled' === $trigger && ( ! $config['enabled'] || '' !== $config['attention'] ) ) {
			Scheduler::apply( $post_id, $config );
			return self::result( 'skipped', __( 'Automatic sync is paused for this table.', 'tablerefresh' ) );
		}

		$sync = '' === $table_id
			? TablePress_Adapter::availability_error()
			: $this->service->sync( $table_id, $config['url'], array( 'on_conflict' => 'replace' === $trigger ? 'overwrite' : 'abort' ) );

		if ( is_wp_error( $sync ) && 'tablerefresh_locked' === $sync->get_error_code() ) {
			return self::result( 'busy', __( 'This table is being synchronized right now. Try again in a minute.', 'tablerefresh' ) );
		}

		$config = Sync_Config::get( $post_id ); // Re-read: it may have been edited during the download.
		if ( null === $config ) {
			return self::result( 'missing', __( 'This table is no longer set up for automatic sync.', 'tablerefresh' ) );
		}

		if ( is_wp_error( $sync ) ) {
			$code    = $sync->get_error_code();
			$outcome = in_array( $code, array( 'tablerefresh_conflict_modified', 'tablerefresh_conflict_editing', 'tablerefresh_conflict_concurrent' ), true ) ? 'conflict' : 'failed';
			$result  = self::result( $outcome, self::describe_error( $sync, $config['url'] ) );
			if ( 'tablerefresh_conflict_modified' === $code ) {
				$config['attention'] = Sync_Config::ATTENTION_CONFLICT; // Persists until an administrator decides.
			} elseif ( 'failed' === $outcome && ++$config['failures'] >= self::MAX_FAILURES && $config['enabled'] ) {
				$config['attention'] = Sync_Config::ATTENTION_FAILURES;
				$result['message']  .= ' ' . sprintf(
					/* translators: %d: number of attempts. */
					__( 'Automatic sync is paused after %d failed attempts in a row.', 'tablerefresh' ),
					$config['failures']
				);
			}
		} else {
			$result = self::result(
				$sync['status'],
				'updated' === $sync['status']
					/* translators: 1: number of rows, 2: number of columns. */
					? sprintf( _n( 'The table was updated: %1$d row, %2$d columns.', 'The table was updated: %1$d rows, %2$d columns.', (int) $sync['rows'], 'tablerefresh' ), (int) $sync['rows'], (int) $sync['columns'] )
					: __( 'The source has not changed. The table was left as it is.', 'tablerefresh' ),
				(int) $sync['rows'],
				(int) $sync['columns']
			);
			if ( in_array( $sync['recovery'], array( 'restored', 'completed' ), true ) ) {
				$result['message'] .= ' ' . __( 'An earlier sync that had been interrupted was finished first.', 'tablerefresh' );
			}
			$config['failures']  = 0;
			$config['attention'] = '';
		}

		Sync_Config::save( $post_id, $config );
		Scheduler::apply( $post_id, $config );
		Sync_Log::add( $post_id, $result + array( 'trigger' => $trigger ) );
		return $result;
	}

	/**
	 * Explains a sync error in plain words.
	 *
	 * @param WP_Error $error Error from Sync_Service::sync().
	 * @param string   $url   Source URL as configured.
	 * @return string
	 */
	public static function describe_error( WP_Error $error, string $url ): string {
		$code = $error->get_error_code();
		switch ( $code ) {
			case 'tablerefresh_conflict_modified':
				$message = __( 'The table was changed in TablePress after the last sync. To keep those changes, the table was not updated and automatic sync is paused until you decide what to do.', 'tablerefresh' );
				break;
			case 'tablerefresh_conflict_editing':
				$editors = $error->get_error_data( 'tablerefresh_conflict_editing_users' );
				$names   = isset( $editors['editors'] ) && is_array( $editors['editors'] ) ? wp_list_pluck( $editors['editors'], 'name' ) : array();
				$message = empty( $names )
					? __( 'The table is open in the TablePress editor. It was not updated, so that unsaved changes are not lost. The next sync will try again.', 'tablerefresh' )
					/* translators: %s: comma-separated user names. */
					: sprintf( __( 'The table is open in the TablePress editor (%s). It was not updated, so that unsaved changes are not lost. The next sync will try again.', 'tablerefresh' ), implode( ', ', $names ) );
				break;
			case 'tablerefresh_conflict_concurrent':
				$message = __( 'The table was saved in TablePress while it was being synchronized. It was not updated.', 'tablerefresh' );
				break;
			case 'tablerefresh_google_sheet_not_public':
				$message = __( 'This Google Sheet is not public. In Google Sheets, click Share, set General access to "Anyone with the link", and try again. Private sheets are not supported.', 'tablerefresh' );
				break;
			case 'tablerefresh_http_html':
				$message = Sync_Config::is_google_sheet( $url )
					? __( 'Google returned a web page instead of the sheet data. Check that the sheet is shared with "Anyone with the link". Private sheets are not supported.', 'tablerefresh' )
					: __( 'The address returned a web page instead of CSV data. Use the direct link to the CSV file.', 'tablerefresh' );
				break;
			case 'tablerefresh_http_status':
				$data   = $error->get_error_data();
				$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
				if ( in_array( $status, array( 404, 410 ), true ) ) {
					/* translators: %d: HTTP status code. */
					$message = sprintf( __( 'Nothing was found at the source address (HTTP %d). Check the link.', 'tablerefresh' ), $status );
				} elseif ( in_array( $status, array( 401, 403 ), true ) ) {
					/* translators: %d: HTTP status code. */
					$message = sprintf( __( 'The source refused access (HTTP %d). The file must be publicly available without signing in.', 'tablerefresh' ), $status );
				} elseif ( $status >= 500 ) {
					/* translators: %d: HTTP status code. */
					$message = sprintf( __( 'The source server reported an error (HTTP %d). This is often temporary.', 'tablerefresh' ), $status );
				} else {
					$message = $error->get_error_message();
				}
				break;
			case 'tablerefresh_http_timeout':
				$message = __( 'The source did not respond in time.', 'tablerefresh' );
				break;
			case 'tablerefresh_http_unreachable':
			case 'tablerefresh_url_dns':
				$message = __( 'The source could not be reached. Check the address.', 'tablerefresh' );
				break;
			case 'tablerefresh_lock_lost':
				$message = __( 'The sync took too long and was stopped before it changed the table.', 'tablerefresh' );
				break;
			case 'tablerefresh_tablepress_unavailable':
				$message = __( 'TablePress is not active, so the table could not be synchronized.', 'tablerefresh' );
				break;
			default:
				// The engine's messages are written for administrators and contain no URLs.
				$message = $error->get_error_message();
				if ( 0 === strpos( $code, 'tablerefresh_csv_' ) ) {
					/* translators: %s: reason. */
					$message = sprintf( __( 'The downloaded file is not valid CSV: %s', 'tablerefresh' ), $message );
				}
		}
		// The previous state of the table could not be confirmed after an interrupted write.
		if ( in_array( 'tablerefresh_revert_failed', $error->get_error_codes(), true ) ) {
			$message .= ' ' . $error->get_error_message( 'tablerefresh_revert_failed' );
		}
		return $message;
	}

	/**
	 * Builds a result.
	 *
	 * @param string $outcome Outcome.
	 * @param string $message Message.
	 * @param int    $rows    Rows.
	 * @param int    $columns Columns.
	 * @return array{outcome: string, message: string, time: int, rows: int, columns: int}
	 */
	private static function result( string $outcome, string $message, int $rows = 0, int $columns = 0 ): array {
		return array(
			'outcome' => $outcome,
			'message' => $message,
			'time'    => time(),
			'rows'    => $rows,
			'columns' => $columns,
		);
	}
}
