#!/bin/sh
# Disables one protection at a time and checks that the tests notice.
#
# Each mutation replaces an exact snippet in a plugin file, runs the given tests, and
# restores the file. A mutation that leaves the tests green means a gap in the tests.
set -u
cd "$(dirname "$0")/.."

# Apache's OPcache does not reliably notice file changes on Windows bind mounts, so it could
# keep serving a mutated (or, after restoring, an outdated) file. Restart Apache instead.
reset_opcache() {
	(cd dev && docker compose restart wordpress >/dev/null 2>&1)
}

# Python edits the file: on the host if it has one, else in the fixtures container.
if python -c '' >/dev/null 2>&1; then
	replace() { python -I - "tablerefresh/includes/$1" "$2" "$3"; }
else
	replace() { MSYS_NO_PATHCONV=1 docker compose -f dev/docker-compose.yml run --rm --no-deps -T -v "$(pwd -W 2>/dev/null || pwd)/tablerefresh:/plugin" fixtures python -I - "/plugin/includes/$1" "$2" "$3"; }
fi

mutate() { # file, exact original, replacement, PHPUnit --filter
	file="tablerefresh/includes/$1"
	cp "$file" "$file.orig"
	replace "$1" "$2" "$3" <<'PY'
import sys
path, old, new = sys.argv[1:4]
s = open(path, encoding="utf-8").read()
if s.count(old) != 1:
    sys.exit("snippet not found exactly once in " + path)
open(path, "w", encoding="utf-8", newline="\n").write(s.replace(old, new))
PY
	if [ $? -ne 0 ]; then
		mv "$file.orig" "$file"
		echo "ERROR     $5 -> snippet not found; mutation not applied"
		return
	fi
	reset_opcache
	result=$(sh dev/run-tests.sh --filter "$4" 2>&1 | grep -E "^(OK|Tests:)")
	mv "$file.orig" "$file"
	reset_opcache
	case "$result" in
		OK*) echo "SURVIVED  $5 -> $result" ;;
		*)   echo "caught    $5 -> $result" ;;
	esac
}

mutate class-table-updater.php \
	"			\$conflict = \$this->detect_conflict( \$post_id, \$existing, \$state );" \
	"			\$conflict = null;" \
	"EditorConflictTest" "no conflict detection"
mutate class-table-lock.php \
	"			if ( 1 === \$inserted ) {" \
	"			if ( true ) {" \
	"test_only_one_of_several_parallel_processes_writes" "lock acquired without the atomic insert"
mutate class-table-updater.php \
	"		if ( ! TablePress_Adapter::snapshots_equal( \$current, \$snapshot ) || \$current['post_modified_gmt'] !== \$snapshot['post_modified_gmt'] ) {" \
	"		if ( false ) {" \
	"test_save_between_load_and_write" "no compare-and-swap"
mutate class-table-updater.php \
	"			return \$this->abandon( self::conflict_error( 'tablerefresh_conflict_concurrent'," \
	"			return \$this->abort( self::conflict_error( 'tablerefresh_conflict_concurrent'," \
	"test_save_between_load_and_write" "restore after compare-and-swap conflict"
mutate class-editor-guard.php \
	"		if ( null !== \$opened && null !== \$state && (float) \$state['synced_at'] > (float) \$opened ) {" \
	"		if ( false ) {" \
	"test_stale_editor" "no stale-editor guard"
mutate class-table-updater.php \
	"		} elseif ( self::is_partial_write( \$current, \$journal, true )" \
	"		} elseif ( true" \
	"IntegrityGuardsTest|RecoveryTest" "restore any outdated journal"
mutate class-tablepress-adapter.php \
	"		if ( self::post_type() !== \$snapshot['post_type'] || json_decode( (string) \$snapshot['post_content'], true ) !== \$table['data'] ) {" \
	"		if ( false ) {" \
	"test_table_mapped_to_a_foreign_post" "no storage-post verification"
mutate class-table-updater.php \
	"		if ( ! \$atomic && ! self::allow_non_atomic() ) {" \
	"		if ( false ) {" \
	"test_non_atomic" "non-atomic updates allowed silently"
mutate class-journal.php \
	"INSERT IGNORE INTO" \
	"REPLACE INTO" \
	"test_journal_of_another_owner" "journal overwrite"
mutate class-revision-policy.php \
	"		return self::delete( array_slice( self::sync_revision_ids( \$post_id ), \$keep ) );" \
	"		return 0;" \
	"test_default_retention" "no retention"
mutate class-revision-policy.php \
	"			return 0 === self::to_keep( \$post->ID ) ? 0 : -1; // No WordPress pruning during a sync." \
	"			return \$num;" \
	"test_manual_revisions_survive" "WordPress pruning during syncs"
mutate class-revision-policy.php \
	"		return \$num < 0 ? \$num : \$num + self::count( \$post->ID );" \
	"		return \$num;" \
	"test_manual_revisions_survive" "sync revisions take manual slots"
mutate class-table-updater.php \
	"				Revision_Policy::delete_created_after( \$post_id, (int) \$journal['revision_id'] );" \
	"" \
	"test_recovery_before_and_after_retention" "crashed write's revision kept"
mutate class-http-fetcher.php \
	"			curl_setopt( \$handle, CURLOPT_PROXY, '' ); // Ignore http_proxy/https_proxy environment variables." \
	"" \
	"test_proxy_environment_variables" "environment proxies used"
mutate class-http-fetcher.php \
	"		if ( \$via_proxy && ! apply_filters( 'tablerefresh_allow_proxy', false, \$target['url'] ) ) {" \
	"		if ( false ) {" \
	"test_wordpress_proxy_is_refused" "WordPress proxy allowed silently"
mutate class-http-fetcher.php \
	"			\$this->decoded_too_large = true;" \
	"" \
	"test_decompression_bomb" "no decoded-size abort (caught only with libcurl < 8.4: CLI_SERVICE=cli74)"
mutate class-http-fetcher.php \
	"			case 18: // Body shorter than announced, or chunked stream not terminated." \
	"			case 99918:" \
	"test_interrupted_downloads" "truncation not reported"
mutate class-table-updater.php \
	"		wp_suspend_cache_addition( true );" \
	"" \
	"test_killed_write_leaves_no_uncommitted" "uncommitted data cached (needs: sh dev/redis.sh enable)"
mutate class-editor-guard.php \
	"			|| ! wp_verify_nonce( sanitize_key( wp_unslash( \$_POST['_ajax_nonce'] ) ), \TablePress::nonce( 'edit', \$table_id ) )" \
	"" \
	"test_forged_save_request" "editor guard without nonce check"
mutate class-table-updater.php \
	"		if ( ! self::\$active['interrupted'] && ( '' !== (string) \$wpdb->last_error || \$wpdb->dbh !== self::\$active['connection'] ) ) {" \
	"		if ( ! self::\$active['interrupted'] && ( \$wpdb->dbh !== self::\$active['connection'] ) ) {" \
	"TransactionLossTest" "database errors during the write ignored"
mutate class-table-updater.php \
	"		if ( ! self::\$active['interrupted'] && ( '' !== (string) \$wpdb->last_error || \$wpdb->dbh !== self::\$active['connection'] ) ) {" \
	"		if ( ! self::\$active['interrupted'] && ( '' !== (string) \$wpdb->last_error ) ) {" \
	"test_lost_connection" "reconnects during the write ignored"
mutate class-table-updater.php \
	"		return '';" \
	"		return \$query;" \
	"TransactionLossTest" "writes not blocked after a database error"
mutate class-table-updater.php \
	"			|| ( \$context['interrupted'] && self::holds_only_update_values( \$current, \$context ) );" \
	"			;" \
	"test_lost_connection" "statements committed outside the transaction not restored"
mutate class-table-updater.php \
	"'tablerefresh_write_interrupted' !== \$result->get_error_code()" \
	"true" \
	"test_deadlock_during_the_write_is_rolled_back_and_retried" "no retry after a deadlock"
mutate class-table-updater.php \
	"			|| ( isset( \$journal['target']['state'] ) && self::holds_only_update_values( \$current, \$journal ) ) ) {" \
	"			) {" \
	"test_write_committed_outside_the_transaction" "recovery ignores writes committed outside the transaction"
mutate class-table-updater.php \
	"		if ( self::write_interrupted() && ! self::matches_target( TablePress_Adapter::read_snapshot( \$post_id ), \$journal ) ) {" \
	"		if ( false ) {" \
	"test_connection_lost_at_the" "COMMIT repeated after a lost connection trusted"
mutate class-table-updater.php \
	"		if ( self::write_interrupted() && ! self::matches_target( TablePress_Adapter::read_snapshot( \$post_id ), \$journal ) ) {" \
	"		if ( self::write_interrupted() ) {" \
	"test_commit_whose_confirmation_was_lost" "commit whose confirmation was lost undone"
mutate class-admin.php \
	"		if ( ! self::can_manage( \$table_id ) ) {" \
	"		if ( false ) {" \
	"test_requests_without_valid_nonce_or_permission" "admin: no permission check"
mutate class-admin.php \
	"		check_admin_referer( 'tablerefresh_' . \$action . '_' . \$post_id );" \
	"" \
	"test_requests_without_valid_nonce_or_permission" "admin: no nonce check"
mutate class-admin.php \
	"		if ( empty( \$_POST['confirm_replace'] ) ) {" \
	"		if ( false ) {" \
	"test_conflict_is_shown_and_resolved" "admin: replace without confirmation"
mutate class-admin.php \
	"		if ( empty( \$_POST['confirm_remove'] ) ) {" \
	"		if ( false ) {" \
	"test_edit_changes_the_schedule_and_remove_keeps_the_table" "admin: remove without confirmation"
mutate class-admin.php \
	"		if ( ( \$is_new || null === Sync_State::read( \$post_id ) ) && empty( \$_POST['confirm_replace'] ) ) {" \
	"		if ( false ) {" \
	"test_setup_requires_confirmation" "admin: setup without confirmation"
mutate class-sync-runner.php \
	"'replace' === \$trigger ? 'overwrite' : 'abort'" \
	"'overwrite'" \
	"test_manual_edit_pauses" "runner: syncs overwrite manual edits"
mutate class-sync-runner.php \
	"				\$config['attention'] = Sync_Config::ATTENTION_CONFLICT; // Persists until an administrator decides." \
	"" \
	"test_manual_edit_pauses" "runner: manual edits do not pause automatic sync"
mutate class-sync-runner.php \
	"			} elseif ( 'failed' === \$outcome && ++\$config['failures'] >= self::MAX_FAILURES && \$config['enabled'] ) {" \
	"			} elseif ( false ) {" \
	"test_automatic_sync_pauses_after_repeated_failures" "runner: endless retries"
mutate class-scheduler.php \
	"		wp_clear_scheduled_hook( self::HOOK, \$args );
		wp_schedule_event(" \
	"		wp_schedule_event(" \
	"test_exactly_one_event" "scheduler: duplicate events"
mutate class-table-updater.php \
	"		if ( null === \$current ) {
			// The table could not be read" \
	"		if ( false ) {
			// The table could not be read" \
	"cannot_be_confirmed|unconfirmed_revert" "revert claimed for a table that could not be read"
mutate class-table-updater.php \
	"		if ( null === \$current && \$post_id > 0 && '' !== (string) \$wpdb->last_error ) {" \
	"		if ( false ) {" \
	"test_recovery_keeps_a_backup_it_could_not_check" "recovery discards a backup after a read error"
mutate class-tablepress-adapter.php \
	"		if ( '' !== (string) \$wpdb->last_error || ! is_array( \$meta ) ) {" \
	"		if ( false ) {" \
	"test_recovery_keeps_a_backup_it_could_not_check" "failed meta read taken for a table without options"
mutate class-table-updater.php \
	"		unset( \$editors[ get_current_user_id() ] );" \
	"		\$editors = array();" \
	"test_own_editor_session_does_not_block" "editor sessions of other users ignored"
