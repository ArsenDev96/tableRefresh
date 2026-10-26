<?php
/**
 * Protection against conflicts with the TablePress table editor.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

defined( 'ABSPATH' ) || exit;

/**
 * Detects concurrent editing in the TablePress admin editor.
 *
 * TablePress's editor has no conflict detection: "Save Changes" posts the whole table as
 * the browser loaded it and overwrites whatever is stored. This class, using only public
 * TablePress hooks:
 *
 * - records when a user opens a table in the editor (`tablepress_view_data`, action "edit"),
 *   so that a sync can refuse to run while someone is editing the table;
 * - rejects an editor save (the `wp_ajax_tablepress_save_table` request, before TablePress
 *   handles it) if the table was synchronized after that editor was opened, because saving
 *   would silently overwrite the synchronized data with the stale copy in the browser.
 *   TablePress shows the message in its usual error notice and nothing is written.
 *
 * The save request is intercepted through WordPress's AJAX action rather than TablePress's
 * `tablepress_event_pre_save_table`, which only exists since TablePress 3.1.
 */
final class Editor_Guard {

	/**
	 * Post meta key prefix, followed by the user ID. Value: time the editor was opened.
	 */
	const META_PREFIX = '_tablerefresh_editor_';

	/**
	 * Registers the hooks.
	 */
	public static function init(): void {
		add_filter( 'tablepress_view_data', array( __CLASS__, 'record_editor_session' ), 10, 2 );
		add_action( 'wp_ajax_tablepress_save_table', array( __CLASS__, 'guard_editor_save' ), 1 ); // Before TablePress (10).
	}

	/**
	 * Remembers that the current user opened a table in the editor.
	 *
	 * @param array<string, mixed> $data   View data.
	 * @param string               $action TablePress admin action.
	 * @return array<string, mixed>
	 */
	public static function record_editor_session( $data, $action ) {
		if ( 'edit' === $action && is_array( $data ) && isset( $data['table']['id'] ) && get_current_user_id() > 0 ) {
			$post_id = TablePress_Adapter::get_post_id( (string) $data['table']['id'] );
			if ( $post_id > 0 ) {
				TablePress_Adapter::write_meta( $post_id, self::META_PREFIX . get_current_user_id(), Sync_State::now() );
			}
		}
		return $data;
	}

	/**
	 * Rejects a save from the TablePress editor whose copy of the table predates the last sync.
	 *
	 * Runs before TablePress's own handler, so it verifies the request the same way TablePress
	 * does. Invalid requests are left to TablePress, which rejects them; in particular, a forged
	 * request must not refresh the user's editor session.
	 */
	public static function guard_editor_save(): void {
		if ( ! isset( $_POST['tablepress']['id'], $_POST['_ajax_nonce'] ) || ! TablePress_Adapter::is_available() ) {
			return;
		}
		$table_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) wp_unslash( $_POST['tablepress']['id'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the allowlist.
		if ( '' === $table_id
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_ajax_nonce'] ) ), \TablePress::nonce( 'edit', $table_id ) )
			|| ! current_user_can( 'tablepress_edit_table', $table_id ) ) {
			return;
		}
		$post_id = TablePress_Adapter::get_post_id( $table_id );
		if ( $post_id <= 0 ) {
			return;
		}

		$opened = self::opened_at( $post_id, get_current_user_id() );
		$state  = Sync_State::read( $post_id );
		if ( null !== $opened && null !== $state && (float) $state['synced_at'] > (float) $opened ) {
			if ( ob_get_level() > 0 ) {
				ob_clean(); // TablePress buffers output during AJAX saves.
			}
			wp_send_json(
				array(
					'success'       => false,
					'message'       => 'error_save',
					'error_details' => esc_html__( 'TableRefresh synchronized this table after you opened it in the editor. Saving now would overwrite the synchronized data with an older copy, so nothing was saved. Copy your changes, reload the page, and apply them again.', 'tablerefresh' ),
				)
			);
		}

		// The editor's copy is current; it stays current after this save.
		TablePress_Adapter::write_meta( $post_id, self::META_PREFIX . get_current_user_id(), Sync_State::now() );
	}

	/**
	 * When a user last opened (or saved) a table in the editor.
	 *
	 * @param int $post_id Table post ID.
	 * @param int $user_id User ID.
	 * @return string|null
	 */
	public static function opened_at( int $post_id, int $user_id ): ?string {
		$sessions = self::sessions( $post_id, 0 );
		return isset( $sessions[ $user_id ] ) ? $sessions[ $user_id ] : null;
	}

	/**
	 * Editor sessions of a table that were active within the session lifetime.
	 *
	 * Without a heartbeat from the editor page, the lifetime is a fixed window after the
	 * editor was opened or last saved.
	 *
	 * @param int $post_id Table post ID.
	 * @return array<int, string> User ID => time opened.
	 */
	public static function active_sessions( int $post_id ): array {
		/**
		 * Filters how long an opened TablePress editor blocks synchronization of its table.
		 *
		 * @param int $seconds Default 900 (15 minutes). 0 disables the check.
		 * @param int $post_id Table post ID.
		 */
		$ttl = (int) apply_filters( 'tablerefresh_editor_session_ttl', 15 * MINUTE_IN_SECONDS, $post_id );
		return $ttl > 0 ? self::sessions( $post_id, microtime( true ) - $ttl ) : array();
	}

	/**
	 * Reads editor sessions directly from the database.
	 *
	 * @param int   $post_id Table post ID.
	 * @param float $since   Only sessions newer than this timestamp.
	 * @return array<int, string>
	 */
	private static function sessions( int $post_id, float $since ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Must bypass caches; indexed by post_id.
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $post_id, $wpdb->esc_like( self::META_PREFIX ) . '%' ), ARRAY_A );
		$sessions = array();
		foreach ( $rows as $row ) {
			$user_id = (int) substr( $row['meta_key'], strlen( self::META_PREFIX ) );
			if ( $user_id > 0 && (float) $row['meta_value'] > $since ) {
				$sessions[ $user_id ] = (string) $row['meta_value'];
			}
		}
		return $sessions;
	}
}
