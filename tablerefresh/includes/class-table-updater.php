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
 * 4. Verify the stored result byte-level and through TablePress, then COMMIT (confirmed by
 *    re-reading the table if the connection was lost meanwhile) and delete the journal.
 *
 * Any WP_Error, exception, failed verification, exit() or fatal error during step 3–4
 * rolls back (and, without transactions, restores the snapshot). If the process is killed
 * outright, InnoDB discards the open transaction and the journal remains, so the next sync
 * (or `wp tablerefresh recover`) can confirm or restore the table.
 *
 * The database itself can end the transaction early: a deadlock rolls it back, and after a lost
 * connection wpdb reconnects and repeats the failed statement in a new session. Every later
 * statement would then be committed on its own. So after any database error or reconnect during
 * step 3–4, all further writes are blocked, the update is undone, and it is retried.
 */
final class Table_Updater {

	/**
	 * How often an update is attempted when the database interrupted the write.
	 */
	const MAX_ATTEMPTS = 3;

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
	 * Whether cache additions were suspended before the write began, or null if TableRefresh
	 * is not suspending them.
	 *
	 * @var bool|null
	 */
	private static ?bool $cache_addition_was_suspended = null;

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
	 * @param string               $table_id Table ID.
	 * @param string[][]           $data     Validated, sanitized, rectangular data.
	 * @param Table_Lock           $lock     Lock held for this table.
	 * @param array<string, mixed> $args {
	 *     Optional.
	 *
	 *     @type string $on_conflict "abort" (default): refuse to overwrite data that was edited
	 *                               after the last sync, or a table that is open in the editor.
	 *                               "overwrite": replace it anyway. Concurrent saves during the
	 *                               write itself always abort.
	 * }
	 * @return array{status: string, rows: int, columns: int, atomic: bool, attempts: int}|WP_Error
	 */
	public function update( string $table_id, array $data, Table_Lock $lock, array $args = array() ) {
		for ( $attempt = 1; ; $attempt++ ) {
			$result = $this->attempt( $table_id, $data, $lock, $args );
			if ( ! is_wp_error( $result ) ) {
				return $result + array( 'attempts' => $attempt );
			}
			// Retry only when the database interrupted the write and the table is back in its
			// previous state; the next attempt repeats every check, including conflict detection.
			$reverted = $result->get_error_data( 'tablerefresh_reverted' );
			if ( $attempt >= self::MAX_ATTEMPTS || 'tablerefresh_write_interrupted' !== $result->get_error_code() || ( is_array( $reverted ) && empty( $reverted['reverted'] ) ) ) {
				return $result;
			}
			usleep( wp_rand( 50000, 250000 ) * $attempt ); // A deadlock ends when the other transaction finishes.
		}
	}

	/**
	 * One attempt of update().
	 *
	 * @param string               $table_id Table ID.
	 * @param string[][]           $data     Data.
	 * @param Table_Lock           $lock     Lock.
	 * @param array<string, mixed> $args     See update().
	 * @return array{status: string, rows: int, columns: int, atomic: bool}|WP_Error
	 */
	private function attempt( string $table_id, array $data, Table_Lock $lock, array $args ) {
		global $wpdb;

		$on_conflict = isset( $args['on_conflict'] ) && 'overwrite' === $args['on_conflict'] ? 'overwrite' : 'abort';

		// Start from what is stored, not from a cache that another process may have outdated.
		$post_id = TablePress_Adapter::get_post_id( $table_id );
		if ( $post_id > 0 ) {
			TablePress_Adapter::clear_caches( $table_id, $post_id );
		}
		$existing = TablePress_Adapter::load( $table_id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		$snapshot = $post_id > 0 ? TablePress_Adapter::read_snapshot( $post_id ) : null;
		if ( null === $snapshot ) {
			return new WP_Error( 'tablerefresh_table_post_missing', __( 'The storage post of the table could not be determined.', 'tablerefresh' ) );
		}
		$mismatch = TablePress_Adapter::verify_post( $snapshot, $existing );
		if ( $mismatch ) {
			return $mismatch;
		}

		$table = TablePress_Adapter::prepare( $existing, $data );
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$expected_data = TablePress_Adapter::expected_stored_data( $table );
		$target_hash   = Sync_State::data_hash( $expected_data );
		$state         = Sync_State::from_snapshot( $snapshot );
		$summary       = array(
			'rows'    => count( $expected_data ),
			'columns' => count( $expected_data[0] ),
			'atomic'  => false,
		);

		if ( $existing['data'] === $expected_data && $existing['visibility'] == $table['visibility'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- int/string-insensitive comparison of visibility flags.
			// Nothing to write. If someone made the table match the source by hand, adopt it as the baseline.
			if ( null === $state || $state['data_md5'] !== $target_hash ) {
				TablePress_Adapter::write_meta( $post_id, Sync_State::META_KEY, Sync_State::encode( $target_hash ) );
			}
			return array( 'status' => 'unchanged' ) + $summary;
		}

		if ( 'abort' === $on_conflict ) {
			$conflict = $this->detect_conflict( $post_id, $existing, $state );
			if ( $conflict ) {
				return $conflict;
			}
		}

		$atomic = $this->supports_transactions();
		if ( ! $atomic && ! self::allow_non_atomic() ) {
			return new WP_Error( 'tablerefresh_db_not_transactional', __( 'The database tables for posts and post meta do not support transactions (InnoDB is required), so the table cannot be updated atomically. Nothing was changed.', 'tablerefresh' ) );
		}

		// A process whose lock expired must not touch the journal of the new lock holder.
		if ( ! $lock->is_held() ) {
			return new WP_Error( 'tablerefresh_lock_lost', __( 'The sync lock expired before the table was written. Nothing was changed.', 'tablerefresh' ) );
		}

		$new_state = Sync_State::encode( $target_hash );
		$journal   = array(
			'version'       => 2,
			'owner'         => $lock->owner(),
			'table_id'      => $table_id,
			'post_id'       => $post_id,
			'created'       => time(),
			'transactional' => $atomic,
			'revision_id'   => Revision_Policy::max_revision_id( $post_id ),
			'snapshot'      => $snapshot,
			'target'        => array(
				'data_md5'   => $target_hash,
				'visibility' => $table['visibility'],
				'state'      => $new_state,
			),
		);
		if ( ! Journal::write( $table_id, $journal ) ) {
			return new WP_Error( 'tablerefresh_journal_failed', __( 'The backup of the table could not be stored, so the table was not changed.', 'tablerefresh' ) );
		}

		/**
		 * Fires after the backup was stored and right before the guarded write of a table begins.
		 *
		 * @param string $table_id Table ID.
		 */
		do_action( 'tablerefresh_before_write', $table_id );

		$this->begin( $table_id, $post_id, $snapshot, $atomic, $lock, $journal['target'] );

		// Failures before the first write only need a rollback; nothing may be restored, because
		// the table may already hold someone else's newer data.
		if ( $atomic && false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $this->abandon( new WP_Error( 'tablerefresh_transaction_failed', __( 'A database transaction could not be started.', 'tablerefresh' ) ) );
		}
		$held = $lock->is_held( $atomic );
		if ( self::write_interrupted() ) {
			return $this->abandon( self::interrupted_error() );
		}
		if ( ! $held ) {
			return $this->abandon( new WP_Error( 'tablerefresh_lock_lost', __( 'The sync lock expired before the table was written. Nothing was changed.', 'tablerefresh' ) ) );
		}

		// Compare-and-swap: lock the table rows and make sure nobody saved the table since it was
		// loaded above. A TablePress editor save that starts now waits until this transaction ends.
		$current = TablePress_Adapter::read_snapshot( $post_id, $atomic );
		if ( self::write_interrupted() ) {
			return $this->abandon( self::interrupted_error() );
		}
		if ( ! TablePress_Adapter::snapshots_equal( $current, $snapshot ) || $current['post_modified_gmt'] !== $snapshot['post_modified_gmt'] ) {
			return $this->abandon( self::conflict_error( 'tablerefresh_conflict_concurrent', __( 'The table was saved by someone else while it was being synchronized. Nothing was changed.', 'tablerefresh' ), $existing ) );
		}

		TablePress_Adapter::write_meta( $post_id, Sync_State::META_KEY, $new_state );

		self::$writing = true;
		Revision_Policy::writing( $post_id );
		try {
			$saved = TablePress_Adapter::save( $table );
		} catch ( \Throwable $exception ) {
			$saved = new WP_Error( 'tablerefresh_table_save_failed', __( 'Saving the table failed with an exception.', 'tablerefresh' ), $exception->getMessage() );
		}
		Revision_Policy::writing( 0 );
		self::$writing = false;
		if ( self::write_interrupted() ) {
			return $this->abort( self::interrupted_error() );
		}
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

		// A transaction that the database already ended would "commit" successfully.
		if ( self::write_interrupted() ) {
			return $this->abort( self::interrupted_error() );
		}
		if ( $atomic && false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $this->abort( new WP_Error( 'tablerefresh_transaction_failed', __( 'The database transaction could not be committed.', 'tablerefresh' ) ) );
		}
		// If the connection was lost at the COMMIT, wpdb repeated it in a new session, where it
		// succeeds without committing anything. Whether the server committed before the connection
		// was lost shows in what is stored: this update's unique baseline, or the previous state.
		if ( self::write_interrupted() && ! self::matches_target( TablePress_Adapter::read_snapshot( $post_id ), $journal ) ) {
			return $this->abort( self::interrupted_error() );
		}

		$this->end();
		Journal::delete( $table_id, $lock->owner() );
		TablePress_Adapter::clear_caches( $table_id, $post_id );

		$summary['atomic']            = $atomic;
		$summary['revisions_deleted'] = Revision_Policy::prune( $post_id ); // Only after the commit.
		return array( 'status' => 'updated' ) + $summary;
	}

	/**
	 * Refuses to overwrite manual changes or a table that is open in the TablePress editor.
	 *
	 * @param int                                             $post_id  Table post ID.
	 * @param array<string, mixed>                            $existing Table as loaded.
	 * @param array{data_md5: string, synced_at: string}|null $state    Baseline of the last sync.
	 * @return WP_Error|null
	 */
	private function detect_conflict( int $post_id, array $existing, ?array $state ): ?WP_Error {
		// No baseline: the table was never synchronized; linking it to a source replaces its data.
		if ( null !== $state && Sync_State::data_hash( $existing['data'] ) !== $state['data_md5'] ) {
			return self::conflict_error( 'tablerefresh_conflict_modified', __( 'The table data was edited after the last synchronization. It was not overwritten. Synchronize with "overwrite" to replace the manual changes.', 'tablerefresh' ), $existing, $state );
		}

		$editors = Editor_Guard::active_sessions( $post_id );
		if ( ! empty( $editors ) ) {
			$error = self::conflict_error( 'tablerefresh_conflict_editing', __( 'The table is open in the TablePress editor. It was not synchronized, so that unsaved changes are not lost.', 'tablerefresh' ), $existing, $state );
			$names = array();
			foreach ( $editors as $user_id => $opened ) {
				$names[] = array(
					'user_id' => $user_id,
					'name'    => \TablePress::get_user_display_name( $user_id ),
					'opened'  => gmdate( 'Y-m-d H:i:s', (int) $opened ),
				);
			}
			$error->add_data( array( 'editors' => $names ), 'tablerefresh_conflict_editing_users' );
			return $error;
		}
		return null;
	}

	/**
	 * Builds a conflict error with details for the user.
	 *
	 * @param string                                          $code     Error code.
	 * @param string                                          $message  Message.
	 * @param array<string, mixed>                            $existing Table as loaded.
	 * @param array{data_md5: string, synced_at: string}|null $state    Baseline.
	 * @return WP_Error
	 */
	private static function conflict_error( string $code, string $message, array $existing, ?array $state = null ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array(
				'conflict'      => true,
				'last_modified' => isset( $existing['last_modified'] ) ? $existing['last_modified'] : '',
				'last_editor'   => isset( $existing['options']['last_editor'] ) ? \TablePress::get_user_display_name( (int) $existing['options']['last_editor'] ) : '',
				'last_synced'   => null !== $state ? gmdate( 'Y-m-d H:i:s', (int) $state['synced_at'] ) : null,
			)
		);
	}

	/**
	 * Whether best-effort (non-atomic) updates are allowed on databases without transactions.
	 *
	 * @return bool
	 */
	private static function allow_non_atomic(): bool {
		/**
		 * Filters whether tables may be updated on a database without transaction support.
		 *
		 * Off by default. When enabled, a failed write is undone from the journal snapshot, but a
		 * crash (killed process) can leave the table partially written until the next sync or
		 * `wp tablerefresh recover` restores it; visitors can see that state meanwhile.
		 *
		 * @param bool $allow Default false.
		 */
		return (bool) apply_filters( 'tablerefresh_allow_non_atomic_updates', false );
	}

	/**
	 * Finishes an interrupted update of a table, based on its journal.
	 *
	 * The caller must hold the table's lock.
	 *
	 * @param string $table_id Table ID.
	 * A snapshot is only restored over a partial write of that same update: the table must still
	 * carry the update's unique baseline (written first, inside the write) and its data must be
	 * the before- or after-state of that update; or every part of the table (data, visibility,
	 * baseline, options, name, description) must be its value before or after that update. Any
	 * other state means the table was changed after the interrupted update, so the journal is
	 * outdated and the table is left alone.
	 *
	 * @return array{status: string}|WP_Error Status "none", "rolled_back" (the table is unchanged),
	 *                                        "completed" (the write had finished), "restored"
	 *                                        (a partial write was undone), or "discarded" (the
	 *                                        journal was outdated or the table no longer exists).
	 */
	public function recover( string $table_id ) {
		global $wpdb;
		$journal = Journal::read( $table_id );
		if ( null === $journal ) {
			return array( 'status' => 'none' );
		}

		$post_id = isset( $journal['post_id'] ) ? (int) $journal['post_id'] : 0;
		$current = $post_id > 0 ? TablePress_Adapter::read_snapshot( $post_id ) : null;
		if ( null === $current && $post_id > 0 && '' !== (string) $wpdb->last_error ) {
			// Only a table that is really gone may lose its journal, not one that could not be read.
			return new WP_Error( 'tablerefresh_recovery_failed', __( 'The table could not be read, so an interrupted update could not be checked. The backup was kept and will be checked again on the next sync.', 'tablerefresh' ) );
		}
		if ( null === $current || ! isset( $journal['snapshot'] ) || TablePress_Adapter::get_post_id( $table_id ) !== $post_id ) {
			Journal::delete( $table_id ); // The table was deleted or re-mapped meanwhile.
			return array( 'status' => 'discarded' );
		}

		if ( TablePress_Adapter::snapshots_equal( $current, $journal['snapshot'] ) ) {
			$status = 'rolled_back';
		} elseif ( self::matches_target( $current, $journal ) ) {
			$status = 'completed';
		} elseif ( self::is_partial_write( $current, $journal, true )
			// A statement that wpdb repeated after a lost connection was committed outside the
			// transaction, which the server rolled back together with the baseline.
			|| ( isset( $journal['target']['state'] ) && self::holds_only_update_values( $current, $journal ) ) ) {
			if ( ! TablePress_Adapter::restore_snapshot( $table_id, $post_id, $journal['snapshot'] ) ) {
				return new WP_Error( 'tablerefresh_recovery_failed', __( 'An interrupted update could not be undone. The backup was kept.', 'tablerefresh' ) );
			}
			// Without a transaction, the interrupted write may have stored a revision of its data.
			if ( isset( $journal['revision_id'] ) ) {
				Revision_Policy::delete_created_after( $post_id, (int) $journal['revision_id'] );
			}
			$status = 'restored';
		} else {
			$status = 'discarded';
		}

		Journal::delete( $table_id );
		TablePress_Adapter::clear_caches( $table_id, $post_id );
		return array( 'status' => $status );
	}

	/**
	 * Gives up before anything was written: rolls back and drops this update's journal.
	 *
	 * @param WP_Error $error Cause.
	 * @return WP_Error
	 */
	private function abandon( WP_Error $error ): WP_Error {
		global $wpdb;
		self::$writing = false;
		$context       = self::$active;
		$this->end();
		if ( null !== $context ) {
			if ( $context['transactional'] ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			}
			Journal::delete( $context['table_id'], $context['lock']->owner() );
			TablePress_Adapter::clear_caches( $context['table_id'], $context['post_id'] );
		}
		return $error;
	}

	/**
	 * Undoes the current write and returns the error.
	 *
	 * @param WP_Error $error Cause.
	 * @return WP_Error
	 */
	private function abort( WP_Error $error ): WP_Error {
		self::$writing = false;
		self::write_interrupted(); // Record a database error of the last statement in the context.
		$context = self::$active;
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
	 * Rolls back, restores the snapshot over this update's own partial write, and deletes the
	 * journal once no partial write of this update remains.
	 *
	 * @param array<string, mixed> $context Update context.
	 * @return bool Whether no partial write of this update remains.
	 */
	private static function undo( array $context ): bool {
		global $wpdb;

		if ( $context['transactional'] ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$current = TablePress_Adapter::read_snapshot( $context['post_id'] );
		if ( null === $current ) {
			// The table could not be read (a database error, or it was deleted meanwhile): whether a
			// partial write of this update remains is unknown. Keep the journal for recovery.
			TablePress_Adapter::clear_caches( $context['table_id'], $context['post_id'] );
			return false;
		}
		$own     = self::is_partial_write( $current, array( 'target' => array( 'state' => $context['target']['state'] ) ), false )
			// After a database error, statements of this update may have been committed outside the
			// transaction (also before its baseline): restore if only this update's values are stored.
			|| ( $context['interrupted'] && self::holds_only_update_values( $current, $context ) );
		$restored = TablePress_Adapter::snapshots_equal( $current, $context['snapshot'] )
			|| ! $own // Changed by someone else afterwards.
			|| TablePress_Adapter::restore_snapshot( $context['table_id'], $context['post_id'], $context['snapshot'] );

		// Without a transaction, a revision of the failed content may already be stored.
		foreach ( $context['revision_ids'] as $revision_id ) {
			wp_delete_post_revision( $revision_id );
		}

		TablePress_Adapter::clear_caches( $context['table_id'], $context['post_id'] );
		if ( $restored ) {
			Journal::delete( $context['table_id'], $context['lock']->owner() );
		}
		return $restored;
	}

	/**
	 * Whether the stored state is a partial write of the update described by a journal entry.
	 *
	 * @param array<string, string|null>|null $current     Current snapshot.
	 * @param array<string, mixed>            $journal     Journal entry (or an entry with only target.state).
	 * @param bool                            $strict_data Also require the data to be the update's
	 *                                                     before- or after-state.
	 * @return bool
	 */
	private static function is_partial_write( ?array $current, array $journal, bool $strict_data ): bool {
		if ( null === $current ) {
			return false;
		}
		if ( ! isset( $journal['target']['state'] ) ) {
			return true; // Journal format 1 (no fingerprint): restore, as before.
		}
		if ( ! isset( $current[ Sync_State::META_KEY ] ) || $current[ Sync_State::META_KEY ] !== $journal['target']['state'] ) {
			return false;
		}
		if ( ! $strict_data ) {
			return true;
		}
		$data = json_decode( (string) $current['post_content'], true );
		$hash = is_array( $data ) ? Sync_State::data_hash( $data ) : '';
		$old  = json_decode( (string) $journal['snapshot']['post_content'], true );
		return $hash === $journal['target']['data_md5'] || ( is_array( $old ) && Sync_State::data_hash( $old ) === $hash );
	}

	/**
	 * Whether every part of the stored table is either its value before or after this update.
	 *
	 * @param array<string, string|null>|null $current Current snapshot.
	 * @param array<string, mixed>            $context Update context or journal entry (snapshot, target).
	 * @return bool
	 */
	private static function holds_only_update_values( ?array $current, array $context ): bool {
		if ( null === $current ) {
			return false;
		}
		$snapshot   = $context['snapshot'];
		$target     = $context['target'];
		$options    = TablePress_Adapter::OPTIONS_META_KEY;
		$visibility = TablePress_Adapter::VISIBILITY_META_KEY;
		$state      = Sync_State::META_KEY;
		$data       = json_decode( (string) $current['post_content'], true );
		$old        = json_decode( (string) $snapshot['post_content'], true );
		$hash       = is_array( $data ) ? Sync_State::data_hash( $data ) : '';

		return ( $hash === $target['data_md5'] || ( is_array( $old ) && Sync_State::data_hash( $old ) === $hash ) )
			&& in_array( $current[ $state ] ?? null, array( null, $snapshot[ $state ] ?? null, $target['state'] ), true )
			&& ( ( $current[ $visibility ] ?? null ) === ( $snapshot[ $visibility ] ?? null ) || json_decode( (string) ( $current[ $visibility ] ?? '' ), true ) == $target['visibility'] ) // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- int/string-insensitive comparison of visibility flags.
			&& json_decode( (string) ( $current[ $options ] ?? '' ), true ) == json_decode( (string) ( $snapshot[ $options ] ?? '' ), true ) // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- TablePress normalizes boolean/integer options.
			&& $current['post_title'] === $snapshot['post_title']
			&& $current['post_excerpt'] === $snapshot['post_excerpt'];
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
			&& Sync_State::data_hash( $data ) === $journal['target']['data_md5']
			&& ( ! isset( $journal['target']['state'] ) || ( isset( $current[ Sync_State::META_KEY ] ) && $current[ Sync_State::META_KEY ] === $journal['target']['state'] ) )
			&& json_decode( (string) $current[ TablePress_Adapter::VISIBILITY_META_KEY ], true ) == $journal['target']['visibility'] // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			&& json_decode( (string) $current[ $options ], true ) == json_decode( (string) $snapshot[ $options ], true ) // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- TablePress normalizes boolean/integer options.
			&& $current['post_title'] === $snapshot['post_title']
			&& $current['post_excerpt'] === $snapshot['post_excerpt'];
	}

	/**
	 * Whether updates can be atomic: the posts, postmeta, and options tables all use InnoDB.
	 *
	 * Fails closed: if the storage engines cannot be determined, updates are not atomic.
	 *
	 * @return bool
	 */
	private function supports_transactions(): bool {
		/**
		 * Filters whether TableRefresh wraps table writes in a database transaction.
		 *
		 * Returning false makes every update non-atomic (and refused, unless
		 * `tablerefresh_allow_non_atomic_updates` allows it).
		 *
		 * @param bool $use_transactions Default true (if the tables use InnoDB).
		 */
		if ( ! apply_filters( 'tablerefresh_use_transactions', true ) ) {
			return false;
		}
		$engines = self::storage_engines();
		if ( 3 !== count( $engines ) ) {
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
	 * Storage engines of the tables that a sync writes to.
	 *
	 * @return array<string, string> Table name => engine; tables whose engine is unknown are missing.
	 */
	public static function storage_engines(): array {
		global $wpdb;
		$tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->options );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Metadata queries; placeholders are filled via spread.
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s, %s)', ...$tables ), ARRAY_A );
		$engines = array();
		foreach ( (array) $rows as $row ) {
			$engines[ $row['TABLE_NAME'] ] = (string) $row['ENGINE'];
		}
		// Some hosts restrict information_schema; SHOW TABLE STATUS works with plain table privileges.
		foreach ( array_diff( $tables, array_keys( $engines ) ) as $table ) {
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
			if ( is_array( $status ) && ! empty( $status['Engine'] ) ) {
				$engines[ $table ] = (string) $status['Engine'];
			}
		}
		// phpcs:enable
		return $engines;
	}

	/**
	 * Records the update context so that exit() or fatal errors can be undone on shutdown.
	 *
	 * @param string                     $table_id      Table ID.
	 * @param int                        $post_id       Post ID.
	 * @param array<string, string|null> $snapshot      Snapshot.
	 * @param bool                       $transactional Whether a transaction is used.
	 * @param Table_Lock                 $lock          Lock.
	 * @param array<string, mixed>       $target        Intended result (journal target), including the
	 *                                                  baseline value (fingerprint) of this update.
	 */
	private function begin( string $table_id, int $post_id, array $snapshot, bool $transactional, Table_Lock $lock, array $target ): void {
		global $wpdb;
		self::$active = array(
			'table_id'      => $table_id,
			'post_id'       => $post_id,
			'snapshot'      => $snapshot,
			'transactional' => $transactional,
			'lock'          => $lock,
			'target'        => $target,
			'revision_ids'  => array(),
			'connection'    => $wpdb->dbh,
			'interrupted'   => false,
		);
		add_filter( 'query', array( __CLASS__, 'guard_query' ), PHP_INT_MAX );
		// Until the commit, nothing this process reads may enter a persistent object cache:
		// WordPress re-reads the post while saving it, and if the process dies before the commit,
		// the database discards the write but a shared cache (Redis, Memcached) would keep
		// serving the uncommitted data to every visitor.
		self::$cache_addition_was_suspended = wp_suspend_cache_addition();
		wp_suspend_cache_addition( true );
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
		remove_filter( 'query', array( __CLASS__, 'guard_query' ), PHP_INT_MAX );
		self::resume_cache_addition();
		Revision_Policy::writing( 0 );
		remove_action( '_wp_put_post_revision', array( __CLASS__, 'track_revision' ), 10 );
		self::$active = null;
	}

	/**
	 * Ends the cache-addition suspension started in begin().
	 */
	private static function resume_cache_addition(): void {
		if ( null !== self::$cache_addition_was_suspended ) {
			wp_suspend_cache_addition( self::$cache_addition_was_suspended );
			self::$cache_addition_was_suspended = null;
		}
	}

	/**
	 * Whether the database reported an error or the connection was replaced since the write began.
	 *
	 * Either can mean that the transaction no longer exists (a deadlock rolls it back; wpdb
	 * reconnects after a lost connection), so that further statements would be committed one by one.
	 *
	 * @return bool
	 */
	private static function write_interrupted(): bool {
		global $wpdb;
		if ( null === self::$active ) {
			return false;
		}
		if ( ! self::$active['interrupted'] && ( '' !== (string) $wpdb->last_error || $wpdb->dbh !== self::$active['connection'] ) ) {
			self::$active['interrupted'] = '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : 'reconnected';
		}
		return false !== self::$active['interrupted'];
	}

	/**
	 * Blocks every write after the database interrupted the update (filter `query`).
	 *
	 * The filter runs before each statement, when `$wpdb->last_error` still holds the result of
	 * the previous one.
	 *
	 * @param string $query SQL statement.
	 * @return string The statement, or an empty string, which makes wpdb skip it.
	 */
	public static function guard_query( $query ) {
		if ( ! self::write_interrupted() || preg_match( '/^\s*(?:SELECT|SHOW)\s/i', (string) $query ) ) {
			return $query;
		}
		return '';
	}

	/**
	 * Error for an update that the database interrupted.
	 *
	 * @return WP_Error
	 */
	private static function interrupted_error(): WP_Error {
		return new WP_Error(
			'tablerefresh_write_interrupted',
			__( 'A database error interrupted the write (for example, a deadlock with another write or a lost connection). The change was undone.', 'tablerefresh' ),
			null !== self::$active ? self::$active['interrupted'] : null
		);
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
			Revision_Policy::mark( (int) $revision_id );
		}
	}

	/**
	 * Undoes an update that was interrupted by exit() or a fatal error, and releases its lock.
	 */
	public static function on_shutdown(): void {
		if ( null === self::$active ) {
			return;
		}
		self::write_interrupted();
		$context       = self::$active;
		self::$active  = null;
		self::$writing = false;
		remove_filter( 'query', array( __CLASS__, 'guard_query' ), PHP_INT_MAX );
		self::resume_cache_addition();
		Revision_Policy::writing( 0 );
		try {
			self::undo( $context );
			$context['lock']->release();
		} catch ( \Throwable $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The journal still allows recovery.
		}
	}
}
