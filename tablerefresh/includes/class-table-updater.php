<?php
/**
 * All-or-nothing replacement of a TablePress table's data.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the data of an existing table so that a failure can never leave it half-written.
 *
 * Sequence for one update (the caller holds the table's Table_Lock):
 *
 * 1. Load the table through TablePress and prepare the new version with TablePress's own
 *    consistency checks. If nothing changed, stop (no write, no new revision).
 * 2. Take a raw snapshot of the table post and its two meta rows and store it, together
 *    with a description of the intended result, in the Journal (committed on its own).
 * 3. Start a database transaction (if posts and postmeta are InnoDB), re-check lock
 *    ownership with SELECT ... FOR UPDATE, and save through TablePress_Table_Model::save().
 * 4. Verify the stored result byte-level and through TablePress, then COMMIT and delete
 *    the journal.
 *
 * Any WP_Error, exception, failed verification, exit() or fatal error during step 3–4
 * rolls back (and, without transactions, restores the snapshot). If the process is killed
 * outright, InnoDB discards the open transaction and the journal remains, so the next sync
 * (or `wp tablerefresh recover`) can confirm or restore the table.
 */
final class Table_Updater {

	/**
	 * Whether TablePress is currently saving on behalf of TableRefresh.
	 *
	 * @var bool
	 */
	private static bool $writing = false;

	/**
	 * Context of the update in progress, for the shutdown handler.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $active = null;

	/**
	 * Whether the shutdown handler was registered.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Whether a guarded TablePress write is in progress (used by tests for fault injection).
	 *
	 * @return bool
	 */
	public static function is_writing(): bool {
		return self::$writing;
	}

	/**
	 * Replaces the table data.
	 *
	 * @param string     $table_id Table ID.
	 * @param string[][] $data     Validated, sanitized, rectangular data.
	 * @param Table_Lock $lock     Lock held for this table.
	 * @return array{status: string, rows: int, columns: int, transactional: bool}|WP_Error
	 */
	public function update( string $table_id, array $data, Table_Lock $lock ) {
		global $wpdb;

		$existing = TablePress_Adapter::load( $table_id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		$post_id = TablePress_Adapter::get_post_id( $table_id );
		if ( $post_id <= 0 ) {
			return new WP_Error( 'tablerefresh_table_post_missing', __( 'The storage post of the table could not be determined.', 'tablerefresh' ) );
		}

		$table = TablePress_Adapter::prepare( $existing, $data );
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$expected_data = TablePress_Adapter::expected_stored_data( $table );
		$summary       = array(
			'rows'          => count( $expected_data ),
			'columns'       => count( $expected_data[0] ),
			'transactional' => false,
		);

		if ( $existing['data'] === $expected_data && $existing['visibility'] == $table['visibility'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- int/string-insensitive comparison of visibility flags.
			return array( 'status' => 'unchanged' ) + $summary;
		}

		$snapshot = TablePress_Adapter::read_snapshot( $post_id );
		if ( null === $snapshot ) {
			return new WP_Error( 'tablerefresh_table_post_missing', __( 'The storage post of the table could not be read.', 'tablerefresh' ) );
		}

		// A process whose lock expired must not touch the journal of the new lock holder.
		if ( ! $lock->is_held() ) {
			return new WP_Error( 'tablerefresh_lock_lost', __( 'The sync lock expired before the table was written. Nothing was changed.', 'tablerefresh' ) );
		}

		$transactional = $this->supports_transactions();
		$journal       = array(
			'version'       => 1,
			'table_id'      => $table_id,
			'post_id'       => $post_id,
			'created'       => time(),
			'transactional' => $transactional,
			'snapshot'      => $snapshot,
			'target'        => array(
				'data_md5'   => md5( (string) wp_json_encode( $expected_data ) ),
				'visibility' => $table['visibility'],
			),
		);
		if ( ! Journal::write( $table_id, $journal ) ) {
			return new WP_Error( 'tablerefresh_journal_failed', __( 'The backup of the table could not be stored, so the table was not changed.', 'tablerefresh' ) );
		}

		$this->begin( $table_id, $post_id, $snapshot, $transactional, $lock );

		if ( $transactional && false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $this->abort( new WP_Error( 'tablerefresh_transaction_failed', __( 'A database transaction could not be started.', 'tablerefresh' ) ) );
		}
		if ( ! $lock->is_held( $transactional ) ) {
			return $this->abort( new WP_Error( 'tablerefresh_lock_lost', __( 'The sync lock expired before the table was written. Nothing was changed.', 'tablerefresh' ) ) );
		}

		self::$writing = true;
		try {
			$saved = TablePress_Adapter::save( $table );
		} catch ( \Throwable $exception ) {
			$saved = new WP_Error( 'tablerefresh_table_save_failed', __( 'Saving the table failed with an exception.', 'tablerefresh' ), $exception->getMessage() );
		}
		self::$writing = false;
		if ( is_wp_error( $saved ) ) {
			return $this->abort( $saved );
		}

		// Without `unfiltered_html`, TablePress runs KSES over the name and description too.
		// They are not part of the sync, so keep them exactly as a privileged user stored them.
		$after = TablePress_Adapter::read_snapshot( $post_id );
		if ( null !== $after && ( $after['post_title'] !== $snapshot['post_title'] || $after['post_excerpt'] !== $snapshot['post_excerpt'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw restore of untouched fields.
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_title'   => $snapshot['post_title'],
					'post_excerpt' => $snapshot['post_excerpt'],
				),
				array( 'ID' => $post_id ),
				'%s',
				'%d'
			);
		}
		TablePress_Adapter::clear_caches( $table_id, $post_id );

		if ( ! self::matches_target( TablePress_Adapter::read_snapshot( $post_id ), $journal ) ) {
			return $this->abort( new WP_Error( 'tablerefresh_verify_failed', __( 'The saved table did not match the imported data. The change was reverted.', 'tablerefresh' ) ) );
		}
		$reloaded = TablePress_Adapter::load( $table_id );
		if ( is_wp_error( $reloaded ) || $reloaded['data'] !== $expected_data ) {
			return $this->abort( new WP_Error( 'tablerefresh_verify_failed', __( 'TablePress did not return the imported data after saving. The change was reverted.', 'tablerefresh' ) ) );
		}

		if ( $transactional && false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $this->abort( new WP_Error( 'tablerefresh_transaction_failed', __( 'The database transaction could not be committed.', 'tablerefresh' ) ) );
		}

		$this->end();
		Journal::delete( $table_id );
		TablePress_Adapter::clear_caches( $table_id, $post_id );

		$summary['transactional'] = $transactional;
		return array( 'status' => 'updated' ) + $summary;
	}

	/**
	 * Finishes an interrupted update of a table, based on its journal.
	 *
	 * The caller must hold the table's lock.
	 *
	 * @param string $table_id Table ID.
	 * @return array{status: string}|WP_Error Status "none", "rolled_back" (the table is unchanged),
	 *                                        "completed" (the write had finished), "restored"
	 *                                        (a partial write was undone), or "discarded".
	 */
	public function recover( string $table_id ) {
		$journal = Journal::read( $table_id );
		if ( null === $journal ) {
			return array( 'status' => 'none' );
		}

		$post_id = isset( $journal['post_id'] ) ? (int) $journal['post_id'] : 0;
		$current = $post_id > 0 ? TablePress_Adapter::read_snapshot( $post_id ) : null;
		if ( null === $current || ! isset( $journal['snapshot'] ) || TablePress_Adapter::get_post_id( $table_id ) !== $post_id ) {
			Journal::delete( $table_id ); // The table was deleted or re-mapped meanwhile.
			return array( 'status' => 'discarded' );
		}

		$status = 'restored';
		if ( TablePress_Adapter::snapshots_equal( $current, $journal['snapshot'] ) ) {
			$status = 'rolled_back';
		} elseif ( self::matches_target( $current, $journal ) ) {
			$status = 'completed';
		} elseif ( ! TablePress_Adapter::restore_snapshot( $table_id, $post_id, $journal['snapshot'] ) ) {
			return new WP_Error( 'tablerefresh_recovery_failed', __( 'An interrupted update could not be undone. The backup was kept.', 'tablerefresh' ) );
		}

		Journal::delete( $table_id );
		TablePress_Adapter::clear_caches( $table_id, $post_id );
		return array( 'status' => $status );
	}

	/**
	 * Undoes the current write and returns the error.
	 *
	 * @param WP_Error $error Cause.
	 * @return WP_Error
	 */
	private function abort( WP_Error $error ): WP_Error {
		self::$writing = false;
		$context       = self::$active;
		$this->end();
		if ( null === $context ) {
			return $error;
		}

		$restored = self::undo( $context );
		$error->add_data( array( 'reverted' => $restored ), 'tablerefresh_reverted' );
		if ( ! $restored ) {
			$error->add( 'tablerefresh_revert_failed', __( 'The previous table state could not be confirmed. A backup was kept and will be restored on the next sync.', 'tablerefresh' ) );
		}
		return $error;
	}

	/**
	 * Rolls back, restores the snapshot if needed, and deletes the journal once the table is back
	 * in its original state.
	 *
	 * @param array<string, mixed> $context Update context.
	 * @return bool Whether the table is in its original state.
	 */
	private static function undo( array $context ): bool {
		global $wpdb;

		if ( $context['transactional'] ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$current  = TablePress_Adapter::read_snapshot( $context['post_id'] );
		$restored = TablePress_Adapter::snapshots_equal( $current, $context['snapshot'] )
			|| TablePress_Adapter::restore_snapshot( $context['table_id'], $context['post_id'], $context['snapshot'] );

		// Without a transaction, a revision of the failed content may already be stored.
		foreach ( $context['revision_ids'] as $revision_id ) {
			wp_delete_post_revision( $revision_id );
		}

		TablePress_Adapter::clear_caches( $context['table_id'], $context['post_id'] );
		if ( $restored ) {
			Journal::delete( $context['table_id'] );
		}
		return $restored;
	}

	/**
	 * Whether a stored state equals the journal's intended result.
	 *
	 * @param array<string, string|null>|null $current Snapshot of the current state.
	 * @param array<string, mixed>            $journal Journal entry.
	 * @return bool
	 */
	private static function matches_target( ?array $current, array $journal ): bool {
		if ( null === $current || ! isset( $journal['snapshot'], $journal['target'] ) ) {
			return false;
		}
		$snapshot = $journal['snapshot'];
		$data     = json_decode( (string) $current['post_content'], true );
		$options  = TablePress_Adapter::OPTIONS_META_KEY;

		return is_array( $data )
			&& md5( (string) wp_json_encode( $data ) ) === $journal['target']['data_md5']
			&& json_decode( (string) $current[ TablePress_Adapter::VISIBILITY_META_KEY ], true ) == $journal['target']['visibility'] // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			&& json_decode( (string) $current[ $options ], true ) == json_decode( (string) $snapshot[ $options ], true ) // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- TablePress normalizes boolean/integer options.
			&& $current['post_title'] === $snapshot['post_title']
			&& $current['post_excerpt'] === $snapshot['post_excerpt'];
	}

	/**
	 * Whether the posts and postmeta tables support transactions (InnoDB).
	 *
	 * @return bool
	 */
	private function supports_transactions(): bool {
		global $wpdb;

		/**
		 * Filters whether TableRefresh wraps table writes in a database transaction.
		 *
		 * Without transactions, the journal is still used to undo failed writes.
		 *
		 * @param bool $use_transactions Default true (if the tables use InnoDB).
		 */
		if ( ! apply_filters( 'tablerefresh_use_transactions', true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Metadata query.
		$engines = $wpdb->get_col( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)', $wpdb->posts, $wpdb->postmeta ) );
		if ( 2 !== count( $engines ) ) {
			return false;
		}
		foreach ( $engines as $engine ) {
			if ( 'innodb' !== strtolower( (string) $engine ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Records the update context so that exit() or fatal errors can be undone on shutdown.
	 *
	 * @param string                     $table_id      Table ID.
	 * @param int                        $post_id       Post ID.
	 * @param array<string, string|null> $snapshot      Snapshot.
	 * @param bool                       $transactional Whether a transaction is used.
	 * @param Table_Lock                 $lock          Lock.
	 */
	private function begin( string $table_id, int $post_id, array $snapshot, bool $transactional, Table_Lock $lock ): void {
		self::$active = array(
			'table_id'      => $table_id,
			'post_id'       => $post_id,
			'snapshot'      => $snapshot,
			'transactional' => $transactional,
			'lock'          => $lock,
			'revision_ids'  => array(),
		);
		add_action( '_wp_put_post_revision', array( __CLASS__, 'track_revision' ), 10, 2 );
		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
			self::$shutdown_registered = true;
		}
	}

	/**
	 * Clears the update context.
	 */
	private function end(): void {
		remove_action( '_wp_put_post_revision', array( __CLASS__, 'track_revision' ), 10 );
		self::$active = null;
	}

	/**
	 * Remembers revisions of the table post created during the write.
	 *
	 * @param int $revision_id Revision ID.
	 * @param int $post_id     Parent post ID.
	 */
	public static function track_revision( $revision_id, $post_id = 0 ): void {
		if ( null !== self::$active && (int) $post_id === self::$active['post_id'] ) {
			self::$active['revision_ids'][] = (int) $revision_id;
		}
	}

	/**
	 * Undoes an update that was interrupted by exit() or a fatal error, and releases its lock.
	 */
	public static function on_shutdown(): void {
		if ( null === self::$active ) {
			return;
		}
		$context       = self::$active;
		self::$active  = null;
		self::$writing = false;
		try {
			self::undo( $context );
			$context['lock']->release();
		} catch ( \Throwable $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The journal still allows recovery.
		}
	}
}
