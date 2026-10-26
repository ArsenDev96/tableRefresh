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

mutate() { # file, exact original, replacement, PHPUnit --filter
	file="tablerefresh/includes/$1"
	cp "$file" "$file.orig"
	python -I - "$file" "$2" "$3" <<'PY'
import sys
path, old, new = sys.argv[1:4]
s = open(path, encoding="utf-8").read()
if s.count(old) != 1:
    sys.exit("snippet not found exactly once in " + path)
open(path, "w", encoding="utf-8", newline="\n").write(s.replace(old, new))
PY
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
	"		} elseif ( self::is_partial_write( \$current, \$journal, true ) ) {" \
	"		} elseif ( true ) {" \
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
