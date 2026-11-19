#!/bin/sh
# Disables one protection at a time and checks that the tests notice.
#
#   sh dev/mutation-check.sh                     # every mutation
#   sh dev/mutation-check.sh --scheduler         # only the scheduler mutations (class-scheduler.php)
#   sh dev/mutation-check.sh --match 'REGEX'     # mutations whose description matches (grep -E)
#   sh dev/mutation-check.sh --list [selection]  # print the selection, run nothing
#   sh dev/mutation-check.sh --recover           # after a run that was killed (see dev/README.md)
#   CLI_SERVICE=cli74 sh dev/mutation-check.sh   # passed on to run-tests.sh (PHP 7.4)
#
# Each mutation replaces an exact snippet in a plugin file, runs the given tests (PHPUnit
# --filter), and restores the file. Before the first mutation, the selected tests run once
# unmutated (baseline); they must pass, or no mutation result would mean anything.
#
#   CAUGHT    the tests ran and at least one assertion failed (no test errors or warnings)
#   SURVIVED  every selected test ran and passed despite the mutation: a gap in the tests
#   ERROR     inconclusive: mutation not applied, Docker/PHPUnit/PHP failure, no matching test,
#             test errors (exceptions, PHP errors) instead of assertion failures, skipped tests,
#             or a missing or incomplete JUnit report
#
# Exit status: 0 (PASS) only if the baseline passed, every selected mutation was CAUGHT, and
# every plugin file is byte-identical afterwards; 1 (FAIL) otherwise; 2 if the run refused to
# start; 128+N after signal N.
#
# Safety: one run at a time. The lock directory dev/.mutation-state also holds the backup of
# the file being mutated (never inside the plugin). On normal exit, errors, and INT/TERM/HUP the
# running test is stopped (process tree and its Docker container) and the file is restored; a
# file that was changed by someone else meanwhile is never overwritten. SIGKILL, crashes and
# power loss run no cleanup: the next run refuses to start, and --recover restores the file.
set -u
cd "$(dirname "$0")/.." || exit 2
export MSYS_NO_PATHCONV=1 # Git Bash on Windows: do not rewrite container paths or taskkill options.

COMPOSE="docker compose -f dev/docker-compose.yml"
STATE=dev/.mutation-state                          # lock + backup of the file being mutated
JUNIT_HOST=dev/fixtures/data/.mutation-junit.xml   # = /fixtures-data in the cli container
JUNIT_CONTAINER=/fixtures-data/.mutation-junit.xml
EXPECTED_SCHEDULER=18 # change deliberately when adding or removing a scheduler mutation

usage() {
	sed -n '2,9p' "$0" | sed 's/^# \{0,1\}//'
	exit "${1:-2}"
}

SELECT=all MATCH= LIST=0 RECOVER=0
while [ $# -gt 0 ]; do
	case $1 in
		--scheduler) [ "$SELECT" = all ] || usage; SELECT=scheduler ;;
		--match) [ "$SELECT" = all ] && [ $# -ge 2 ] || usage; SELECT=match MATCH=$2; shift ;;
		--list) LIST=1 ;;
		--recover) RECOVER=1 ;;
		-h | --help) usage 0 ;;
		*) echo "Unknown option: $1" >&2; usage ;;
	esac
	shift
done

# Exact replacement on bytes (no newline or encoding translation): file on stdin, result on stdout.
PY="import sys
old, new = (a.encode('utf-8') for a in sys.argv[1:3])
data = sys.stdin.buffer.read()
n = data.count(old)
if n != 1:
    sys.exit('snippet found %d times in the file, expected exactly once' % n)
sys.stdout.buffer.write(data.replace(old, new))"
if python -c '' >/dev/null 2>&1; then
	PYTHON="python -I" PYTHON_WHERE=host
else
	PYTHON="$COMPOSE run --rm --no-deps -T fixtures python -I" PYTHON_WHERE="fixtures container"
fi

# ---------------------------------------------------------------- state and output

IN_RUN=0 CHILD= INTERRUPTED= PROJECT= ONEOFF_BEFORE= OPCACHE_DIRTY=0 BASELINE=not-run
LOG_DIR= MAIN_LOG=/dev/null RESULTS=/dev/null CUR_LABEL= CUR_LOG=/dev/null STATUS= DETAIL=
ID=0 MODE=count N_TOTAL=0 N_SELECTED=0 N_DONE=0 N_CAUGHT=0 N_SURVIVED=0 N_ERROR=0
SELECTED_IDS= FILTERS= SCHED_FILE=0 SCHED_LABEL=0 HARNESS_ERRORS=

say() {
	printf '%s\n' "$*"
	printf '%s\n' "$*" >>"$MAIN_LOG"
}
harness_error() {
	HARNESS_ERRORS="$HARNESS_ERRORS
  - $*"
	say "HARNESS ERROR: $*"
}
winpath() { cygpath -w "$1" 2>/dev/null || printf '%s\n' "$1"; }

new_log_dir() {
	LOG_DIR="${TMPDIR:-/tmp}/tablerefresh-mutation-$(date +%Y%m%d-%H%M%S)-$$"
	mkdir -p "$LOG_DIR" || exit 2
	MAIN_LOG="$LOG_DIR/main.log" RESULTS="$LOG_DIR/results.tsv"
	: >"$MAIN_LOG"
	: >"$RESULTS"
}

# ---------------------------------------------------------------- processes and containers

# Runs a command as a background job and waits for it, so that a trapped signal interrupts the
# wait at once (a foreground command would delay the trap until it finished on its own).
run_bg() { # stdin, stdout (appended), stderr (appended), command...
	_in=$1 _out=$2 _err=$3
	shift 3
	"$@" <"$_in" >>"$_out" 2>>"$_err" &
	CHILD=$!
	wait "$CHILD"
	_rc=$?
	CHILD=
	return $_rc
}

kill_tree_posix() {
	for _c in $(pgrep -P "$1" 2>/dev/null); do kill_tree_posix "$_c"; done
	kill -TERM "$1" 2>/dev/null
}

stop_child() {
	[ -n "$CHILD" ] || return 0
	_pid=$CHILD
	CHILD=
	say "Stopping the running command (pid $_pid) and its child processes."
	if [ -r "/proc/$_pid/winpid" ] && command -v taskkill >/dev/null 2>&1; then
		taskkill /F /T /PID "$(cat "/proc/$_pid/winpid")" >>"$MAIN_LOG" 2>&1 # Git Bash: kill the Windows tree
	else
		kill_tree_posix "$_pid"
	fi
	wait "$_pid" 2>/dev/null
}

project_name() {
	PROJECT=$($COMPOSE config 2>>"$MAIN_LOG" | sed -n 's/^name: *//p' | head -n 1)
	[ -n "$PROJECT" ]
}

# Running `docker compose run` containers of the project (tests, replacements).
oneoff_containers() {
	docker ps -q --no-trunc --filter "label=com.docker.compose.project=$PROJECT" \
		--filter "label=com.docker.compose.oneoff=True" 2>>"$MAIN_LOG"
}

# Killing the docker client does not stop its container: remove the ones this run started.
stop_run_containers() {
	[ -n "$PROJECT" ] || return 0
	for _pass in 1 2; do # a container may appear shortly after its client was killed
		for _id in $(oneoff_containers); do
			case " $ONEOFF_BEFORE " in *" $_id "*) continue ;; esac
			say "Removing container $(printf '%.12s' "$_id") left by the stopped command."
			docker rm -f "$_id" >>"$MAIN_LOG" 2>&1 || harness_error "could not remove container $_id"
		done
		[ "$_pass" = 2 ] || sleep 2
	done
}

# Apache's OPcache does not reliably notice file changes on Windows bind mounts, so it could
# keep serving a mutated (or, after restoring, an outdated) file. Restart Apache instead.
reset_opcache() { # log
	printf '%s\n' "-- restart wordpress (OPcache)" >>"$1"
	run_bg /dev/null "$1" "$1" $COMPOSE restart wordpress
}

# ---------------------------------------------------------------- backup and restore

# Restores the file of the mutation in progress. Returns 0 when it holds its original bytes
# again (or no mutation is in progress); never overwrites content that is neither the original
# nor the mutation this run wrote.
restore_target() {
	[ -f "$STATE/target" ] || return 0
	_t=$(cat "$STATE/target")
	if [ ! -f "$STATE/original" ]; then
		say "NOT RESTORED: $STATE/target names $_t, but $STATE/original is missing."
		return 1
	elif cmp -s "$STATE/original" "$_t"; then
		: # The mutation was not written (yet).
	elif [ -f "$STATE/mutated" ] && cmp -s "$STATE/mutated" "$_t"; then
		if ! cp "$STATE/original" "$_t" || ! cmp -s "$STATE/original" "$_t"; then
			say "NOT RESTORED: could not write $_t. The original is in $STATE/original."
			return 1
		fi
		say "Restored $_t."
	else
		say "NOT RESTORED: $_t is neither its original nor the mutated version (changed during the run,"
		say "  or the run was killed while writing it). It was left as it is. Compare and restore by hand:"
		say "    diff -u $STATE/original $_t        # original vs. now"
		say "    diff -u $STATE/mutated $_t         # mutation vs. now"
		say "    cp $STATE/original $_t             # only if the changes are the mutation's"
		say "  Then: rm -r $STATE && sh dev/mutation-check.sh --recover"
		return 1
	fi
	rm -f "$STATE/target" "$STATE/original" "$STATE/mutated" "$STATE/candidate"
	return 0
}

legacy_backups() { find tablerefresh -name '*.orig' 2>/dev/null; }

refuse_if_legacy_backups() {
	_legacy=$(legacy_backups)
	[ -n "$_legacy" ] || return 0
	echo "Backups left by an older version of this script (a run that did not finish):" >&2
	printf '  %s\n' $_legacy >&2
	echo "Each sits next to the file it backs up; the file may still be mutated. For each:" >&2
	echo "  diff -u FILE.orig FILE    # should show only the mutation" >&2
	echo "  mv FILE.orig FILE         # restores the original" >&2
	echo "Then check git status and run again." >&2
	exit 2
}

lock_owner_alive() {
	_owner=$(cat "$STATE/pid" 2>/dev/null)
	[ -n "$_owner" ] && kill -0 "$_owner" 2>/dev/null
}

snapshot() { # all plugin files with checksums
	find tablerefresh -type f -exec cksum {} + | LC_ALL=C sort -k 3
}

# ---------------------------------------------------------------- one mutation

apply_mutation() { # file, original snippet, replacement, log; sets DETAIL on failure
	if [ ! -f "$1" ]; then
		DETAIL="file not found: $1; mutation not applied"
		return 1
	fi
	if ! cp -p "$1" "$STATE/original" || ! cmp -s "$1" "$STATE/original"; then
		rm -f "$STATE/original"
		DETAIL="could not back up $1; mutation not applied"
		return 1
	fi
	printf '%s\n' "$1" >"$STATE/target" # From here on, every exit path restores the file.
	printf '%s\n' "-- replace ($PYTHON_WHERE)" >>"$4"
	rm -f "$STATE/candidate"
	if ! run_bg "$1" "$STATE/candidate" "$4" $PYTHON -c "$PY" "$2" "$3"; then
		DETAIL="mutation not applied: $(tail -n 1 "$4")"
		return 1
	fi
	if [ ! -s "$STATE/candidate" ] || cmp -s "$STATE/candidate" "$1"; then
		DETAIL="mutation not applied: the replacement produced no change"
		return 1
	fi
	mv "$STATE/candidate" "$STATE/mutated" || { DETAIL="could not store the mutated version"; return 1; }
	diff -u "$STATE/original" "$STATE/mutated" >>"$4"
	OPCACHE_DIRTY=1
	if ! cp "$STATE/mutated" "$1" || ! cmp -s "$STATE/mutated" "$1"; then
		DETAIL="could not write the mutated file"
		return 1
	fi
	return 0
}

run_tests() { # filter, log
	rm -f "$JUNIT_HOST"
	printf '%s\n' "-- sh dev/run-tests.sh --filter '$1' (CLI_SERVICE=${CLI_SERVICE:-cli})" >>"$2"
	run_bg /dev/null "$2" "$2" sh dev/run-tests.sh --log-junit "$JUNIT_CONTAINER" --filter "$1"
}

junit_attr() { # name -> number from the first (aggregate) <testsuite> element, or empty
	printf '%s\n' "$J_HEAD" | sed -n "s/.* $1=\"\([0-9][0-9]*\)\".*/\1/p"
}

# Tests with a <failure> (or <error>) in the report, at most three.
junit_cases() { # xml, failure|error
	awk -v tag="<$2" '/<testcase / { if (match($0, /name="[^"]*"/)) n = substr($0, RSTART + 6, RLENGTH - 7) }
		index($0, tag) { print n }' "$1" | uniq | head -n 3 | tr '\n' ' ' | sed 's/ $//'
}

# Sets STATUS and DETAIL from the exit status of run-tests.sh and the JUnit report.
classify() { # exit status, xml, log
	_rc=$1 _x=$2
	if [ ! -s "$_x" ] || ! grep -q '</testsuites>' "$_x"; then
		if grep -q '^No tests executed!' "$3"; then
			_why="no test matched the filter"
		elif grep -q '^Run dev/setup.sh first.' "$3"; then
			_why="WP-CLI could not load the site (plugin not active, or a PHP fatal error while loading plugins)"
		elif grep -Eq 'PHP (Parse|Fatal) error|^(Parse|Fatal) error' "$3"; then
			_why="PHP fatal error"
		else
			_why="PHPUnit did not run or did not finish (Docker, setup, crash)"
		fi
		STATUS=ERROR DETAIL="no complete JUnit report (exit $_rc): $_why"
		return
	fi
	J_HEAD=$(grep -m 1 '<testsuite ' "$_x")
	_tests=$(junit_attr tests) _asserts=$(junit_attr assertions) _fail=$(junit_attr failures)
	_err=$(junit_attr errors) _skip=$(junit_attr skipped) _warn=$(junit_attr warnings)
	if [ -z "$_tests" ] || [ -z "$_fail" ] || [ -z "$_err" ] || [ -z "$_skip" ]; then
		STATUS=ERROR DETAIL="unreadable JUnit report (exit $_rc)"
		return
	fi
	_warn=${_warn:-0}
	_counts="Tests: $_tests, Assertions: ${_asserts:-?}, Failures: $_fail, Errors: $_err, Warnings: $_warn, Skipped: $_skip"
	if [ "$_tests" -eq 0 ]; then
		STATUS=ERROR DETAIL="no test matched the filter (exit $_rc)"
	elif [ "$_err" -gt 0 ]; then
		STATUS=ERROR DETAIL="$_counts; test errors (exception or PHP error), not assertion failures: $(junit_cases "$_x" error)"
	elif [ "$_warn" -gt 0 ]; then
		STATUS=ERROR DETAIL="$_counts; PHPUnit warnings"
	elif [ "$_fail" -gt 0 ]; then
		if [ "$_rc" -eq 1 ]; then
			STATUS=CAUGHT DETAIL="$_counts; failed: $(junit_cases "$_x" failure)"
		else
			STATUS=ERROR DETAIL="$_counts; but exit status $_rc instead of 1"
		fi
	elif [ "$_rc" -ne 0 ]; then
		STATUS=ERROR DETAIL="$_counts; exit status $_rc without failures (risky test or PHPUnit problem)"
	elif [ "$_skip" -gt 0 ]; then
		STATUS=ERROR DETAIL="$_counts; passed, but $_skip selected test(s) skipped or incomplete: inconclusive"
	else
		STATUS=SURVIVED DETAIL="$_counts"
	fi
}

record() { # status, detail
	N_DONE=$((N_DONE + 1))
	case $1 in
		CAUGHT) N_CAUGHT=$((N_CAUGHT + 1)) ;;
		SURVIVED) N_SURVIVED=$((N_SURVIVED + 1)) ;;
		*) N_ERROR=$((N_ERROR + 1)) ;;
	esac
	printf '%s\t%s\t%s\t%s\n' "$ID" "$1" "$CUR_LABEL" "$2" >>"$RESULTS"
	say "         $(printf '%-8s' "$1") $2"
	if [ "$1" != CAUGHT ]; then
		say "         log: $(winpath "$CUR_LOG")"
	fi
	if [ "$1" = ERROR ]; then
		tail -n 15 "$CUR_LOG" | sed 's/^/         | /'
		tail -n 15 "$CUR_LOG" | sed 's/^/         | /' >>"$MAIN_LOG"
	fi
}

run_mutation() { # file, original snippet, replacement, PHPUnit --filter, description
	_file="tablerefresh/includes/$1"
	CUR_LABEL=$5
	CUR_LOG="$LOG_DIR/$(printf '%03d' "$ID").log"
	printf '== #%s %s\nfile: %s\nfilter: %s\n' "$ID" "$5" "$_file" "$4" >"$CUR_LOG"
	say "[$((N_DONE + 1))/$N_SELECTED] #$ID $5  (filter: $4)"
	STATUS= DETAIL=
	if ! apply_mutation "$_file" "$2" "$3" "$CUR_LOG"; then
		STATUS=ERROR
	elif ! reset_opcache "$CUR_LOG"; then
		STATUS=ERROR DETAIL="could not restart Apache, which could serve stale code; tests not run"
	else
		run_tests "$4" "$CUR_LOG"
		_trc=$?
		_xml="$LOG_DIR/$(printf '%03d' "$ID").junit.xml"
		[ -f "$JUNIT_HOST" ] && mv "$JUNIT_HOST" "$_xml"
		classify "$_trc" "$_xml" "$CUR_LOG"
	fi
	if ! restore_target; then
		harness_error "#$ID: $_file could not be restored safely; stopping"
		exit 1
	fi
	if [ "$OPCACHE_DIRTY" = 1 ]; then
		if reset_opcache "$CUR_LOG"; then
			OPCACHE_DIRTY=0
		else
			harness_error "#$ID: could not restart Apache after restoring $_file; stopping"
			exit 1
		fi
	fi
	record "$STATUS" "$DETAIL"
}

# ---------------------------------------------------------------- selection

selected() { # file, description
	case $SELECT in
		all) return 0 ;;
		scheduler) case $2 in "scheduler: "*) return 0 ;; esac; return 1 ;;
		match) printf '%s\n' "$2" | grep -Eq -- "$MATCH" ;;
	esac
}

mutate() { # file, exact original, replacement, PHPUnit --filter, description
	ID=$((ID + 1))
	if [ "$MODE" = count ]; then
		N_TOTAL=$((N_TOTAL + 1))
		[ "$1" = class-scheduler.php ] && SCHED_FILE=$((SCHED_FILE + 1))
		case $5 in "scheduler: "*) SCHED_LABEL=$((SCHED_LABEL + 1)) ;; esac
		case "$1/$5" in
			"class-scheduler.php/scheduler: "*) ;;
			class-scheduler.php/* | */"scheduler: "*) SCHED_MISMATCH="${SCHED_MISMATCH:-}#$ID " ;;
		esac
	fi
	selected "$1" "$5" || return 0
	case $MODE in
		count)
			N_SELECTED=$((N_SELECTED + 1))
			SELECTED_IDS="$SELECTED_IDS $ID"
			FILTERS="${FILTERS:+$FILTERS|}$4"
			;;
		list) printf '#%-3s %-28s %s\n' "$ID" "$1" "$5" ;;
		run) run_mutation "$@" ;;
	esac
}

# ---------------------------------------------------------------- mutations

define_mutations() {
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
	"		self::place_event( \$post_id, time() + \$interval, \$config['frequency'] ); // Never without an event in between." \
	"		wp_schedule_event( time() + \$interval, \$config['frequency'], self::HOOK, \$args );" \
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
mutate class-admin.php \
	"		unset( \$menu_order[ \$own ] );" \
	"		return \$menu_order;" \
	"test_menu_position_avoids_taken_positions" "admin: menu not kept below TablePress"
mutate class-admin.php \
	"		add_action( 'admin_menu', array( __CLASS__, 'redirect_former_url' ), 0 );" \
	"" \
	"test_former_tools_url" "admin: former Tools URL not redirected"
mutate class-scheduler.php \
	"		if ( 'busy' === \$result['outcome'] ) {" \
	"		if ( false ) {" \
	"test_runner_that_finds_the_table_busy" "scheduler: busy runner leaves a lost event lost"
mutate class-scheduler.php \
	"		if ( self::over_budget() ) {" \
	"		if ( false ) {" \
	"test_slow_sources_ahead_do_not_get_a_table_cut_off" "scheduler: no time budget for cron requests"
mutate class-scheduler.php \
	"		if ( 0 === self::\$started || ! wp_doing_cron() ) {" \
	"		if ( ! wp_doing_cron() ) {" \
	"test_cron_request_hands_remaining_syncs" "scheduler: first sync of a request can be deferred"
mutate class-scheduler.php \
	"		\$latest   = min( time(), \$event['time'] - \$interval ); // The last regular time that has passed." \
	"		\$latest   = time();" \
	"test_cron_request_hands_remaining_syncs" "scheduler: deferred table loses its place and regular time"
mutate class-scheduler.php \
	"		\$time = null !== \$due && \$due <= \$latest && 0 === ( \$latest - \$due ) % \$interval ? \$due : \$latest;" \
	"		\$time = \$latest;" \
	"overload|keeps_its_original_due_time|backlog" "scheduler: repeated deferral loses the original due time (starvation)"
mutate class-scheduler.php \
	"		add_filter( 'pre_reschedule_event', array( __CLASS__, 'note_due_time' ), 10, 2 );" \
	"" \
	"test_minimal_overload|keeps_its_original_due_time" "scheduler: due times reported by WP-Cron not noted"
mutate class-scheduler.php \
	"			self::place_event( \$post_id, time(), \$config['frequency'] );
			return;" \
	"			wp_clear_scheduled_hook( self::HOOK, array( \$post_id ) );
			wp_schedule_event( time(), \$config['frequency'], self::HOOK, array( \$post_id ) );
			return;" \
	"test_interrupted_replacement_of_an_unexpected_event" "scheduler: unexpected event removed before the new one is added"
mutate class-scheduler.php \
	"					continue; // Not scheduled: the other events stay." \
	"" \
	"test_deferral_that_cannot_schedule" "scheduler: events removed although the new one was not scheduled"
mutate class-scheduler.php \
	"				if ( true !== wp_schedule_event( \$time, \$frequency, self::HOOK, \$args ) ) {
					self::refresh_cron();
				}" \
	"				wp_schedule_event( \$time, \$frequency, self::HOOK, \$args );" \
	"test_deferral_survives_an_identical_write" "scheduler: outdated copy of WP-Cron's list kept after a write that changed nothing"
mutate class-scheduler.php \
	"		if ( \$turn > time() ) {" \
	"		if ( false ) {" \
	"test_turn_marker_ahead_of_the_clock" "scheduler: turn marker ahead of the clock trusted"
mutate class-scheduler.php \
	"				delete_post_meta( \$post_id, self::TURN_META_KEY ); // Nothing of the scheduler stays without a configuration." \
	"" \
	"test_turn_marker_is_removed_with_the_configuration" "scheduler: turn marker kept after the configuration was removed"
mutate class-scheduler.php \
	"		if ( null !== \$due && \$due <= time() && null !== Sync_Config::get( \$post_id ) ) {" \
	"		if ( null !== \$due ) {" \
	"test_turn_marker_is_removed_with_the_configuration" "scheduler: turn marker written for a post without a configuration"
mutate class-scheduler.php \
	"		if ( null !== \$due && \$turn >= \$due ) {" \
	"		if ( false ) {" \
	"test_overlapping_request_does_not_hand_back" "scheduler: deferral hands back a turn an overlapping request took"
mutate class-scheduler.php \
	"			update_post_meta( \$post_id, self::TURN_META_KEY, \$due );" \
	"" \
	"test_overlapping_request_does_not_hand_back" "scheduler: started sync does not record its turn"
mutate class-scheduler.php \
	"		if ( null === \$event || \$config['frequency'] !== \$event['schedule'] || \$event['interval'] <= 0 ) {" \
	"		if ( null === \$event ) {" \
	"test_frequency_change_while_a_table_is_deferred|test_deferral_never_leaves" "scheduler: deferral keeps a changed frequency or a duplicate event"
mutate class-scheduler.php \
	"		if ( null === \$config || empty( \$config['enabled'] ) || '' !== \$config['attention'] ) {
			self::apply( \$post_id, \$config ); // Removes the event." \
	"		if ( null === \$config ) {
			self::apply( \$post_id, \$config ); // Removes the event." \
	"test_deferral_removes_events" "scheduler: deferral keeps events of disabled or paused tables"
mutate class-scheduler.php \
	"'cli' === PHP_SAPI ? 0 : self::TIME_BUDGET" \
	"self::TIME_BUDGET" \
	"test_command_line_cron_has_no_time_budget" "scheduler: command-line cron runs budgeted by default"
mutate class-table-lock.php \
	"WHERE option_name = %s FOR UPDATE\", \$name )" \
	"WHERE option_name = %s\", \$name )" \
	"test_lock_check_in_a_transaction_blocks_a_takeover" "lock check inside the transaction does not lock the row"
mutate class-cli-command.php \
	"(string) wp_json_encode( \$value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )" \
	"(string) print_r( \$value, true )" \
	"test_cli_text_output" "cli: text output values not JSON-encoded"
mutate class-plugin.php \
	"		if ( null === \$screen || 'plugins' !== \$screen->id || ! current_user_can( 'activate_plugins' ) || TablePress_Adapter::is_available() ) {" \
	"		if ( ! current_user_can( 'activate_plugins' ) || TablePress_Adapter::is_available() ) {" \
	"test_dependency_notice_only_on_the_plugins_screen" "dependency notice on every admin screen"
mutate class-admin.php \
	"( 'dashboard' !== \$screen->id && false === strpos( \$screen->id, 'tablepress' ) )" \
	"false" \
	"test_attention_notice_only_on_the_dashboard" "attention notice on every admin screen"
mutate ../uninstall.php \
	"		\$wpdb->esc_like( '_transient_tablerefresh_' ) . '%'," \
	"		\$wpdb->esc_like( 'tablerefresh_lock_' ) . '%'," \
	"test_uninstall_removes" "uninstall leaves the admin notice transients"
mutate ../tablerefresh.php \
	"array( 'ArsenManukyan\TableRefresh\Plugin', 'activate' )" \
	"array( 'TableRefresh\Plugin', 'activate' )" \
	"test_plugin_callbacks_resolve" "activation hook names the former namespace"
}

# ---------------------------------------------------------------- recovery

recover() {
	new_log_dir
	refuse_if_legacy_backups
	if [ ! -d "$STATE" ]; then
		say "No interrupted run is recorded ($STATE does not exist)."
	elif lock_owner_alive; then
		say "A run is still in progress (pid $(cat "$STATE/pid")); stop it first (Ctrl+C or kill -TERM)."
		say "If no run is active (the pid was reused after a reboot), delete $STATE/pid and retry."
		exit 2
	else
		say "Recovering after a run that ended without cleanup (pid $(cat "$STATE/pid" 2>/dev/null))."
	fi
	# A killed run leaves its test running against the shared database: stop it before restoring.
	_docker=0
	if project_name; then
		_docker=1
		for _id in $(oneoff_containers); do
			say "Removing test container $(printf '%.12s' "$_id") still running from the interrupted run."
			docker rm -f "$_id" >>"$MAIN_LOG" 2>&1 || { say "Could not remove container $_id."; exit 1; }
		done
	fi
	if [ -d "$STATE" ]; then
		restore_target || exit 1
	fi
	if [ "$_docker" = 0 ]; then
		say "Docker is not reachable: check 'docker ps' for leftover *-run-* containers and restart"
		say "the wordpress service ($COMPOSE restart wordpress) before the next run."
		exit 1
	fi
	reset_opcache "$MAIN_LOG" || { say "Could not restart Apache: run '$COMPOSE restart wordpress'."; exit 1; }
	rm -f "$JUNIT_HOST" "$STATE/pid"
	if [ -d "$STATE" ] && ! rmdir "$STATE"; then
		say "$STATE is not empty; inspect it before removing it."
		exit 1
	fi
	say "Recovered. git status of the plugin:"
	git status --short -- tablerefresh
	exit 0
}

# ---------------------------------------------------------------- end of a run

on_signal() { # name, exit status
	trap '' INT TERM HUP
	INTERRUPTED=$1
	say ""
	say "Interrupted by SIG$1: stopping the tests and restoring the plugin files (further signals are ignored)."
	exit "$2"
}

finish() {
	_code=$?
	trap '' INT TERM HUP
	trap - EXIT
	stop_child
	stop_run_containers
	_restored=1
	restore_target || _restored=0
	if [ "$_restored" = 1 ] && [ "$OPCACHE_DIRTY" = 1 ]; then
		reset_opcache "$MAIN_LOG" && OPCACHE_DIRTY=0 || harness_error "could not restart Apache after restoring the plugin file"
	fi
	rm -f "$JUNIT_HOST"
	_tree=unchanged
	if [ -f "$LOG_DIR/tree-before" ]; then
		snapshot >"$LOG_DIR/tree-after"
		if ! cmp -s "$LOG_DIR/tree-before" "$LOG_DIR/tree-after"; then
			_tree=CHANGED
			harness_error "plugin files differ from the start of the run:"
			diff "$LOG_DIR/tree-before" "$LOG_DIR/tree-after" | grep '^[<>]' | sed 's/^/      /'
		fi
	else
		_tree="not checked"
	fi
	if [ "$_restored" = 1 ]; then
		rm -f "$STATE/pid"
		rmdir "$STATE" 2>/dev/null || harness_error "$STATE is not empty; inspect it"
	fi

	_done_ids=" $(cut -f 1 "$RESULTS" | tr '\n' ' ')"
	_missing=
	for _i in $SELECTED_IDS; do
		case "$_done_ids" in *" $_i "*) ;; *) _missing="$_missing #$_i" ;; esac
	done
	_incomplete=$((N_SELECTED - N_DONE))
	_result=PASS
	case $BASELINE in passed*) _baseline_ok=1 ;; *) _baseline_ok=0 ;; esac
	{ [ -n "$INTERRUPTED" ] || [ "$_baseline_ok" != 1 ] || [ "$_incomplete" -ne 0 ] || [ "$N_SURVIVED" -ne 0 ] ||
		[ "$N_ERROR" -ne 0 ] || [ -n "$HARNESS_ERRORS" ] || [ "$_restored" != 1 ] || [ "$_tree" != unchanged ]; } && _result=FAIL

	say ""
	say "== Mutation check summary ($SELECT${MATCH:+: $MATCH}${CLI_SERVICE:+, CLI_SERVICE=$CLI_SERVICE})"
	say "Selected:     $N_SELECTED of $N_TOTAL ($(printf '#%s ' $SELECTED_IDS | sed 's/ $//'))"
	say "Baseline:     $BASELINE"
	say "Executed:     $N_DONE"
	say "Caught:       $N_CAUGHT"
	say "Survived:     $N_SURVIVED"
	say "Errors:       $N_ERROR"
	say "Incomplete:   $_incomplete${_missing:+ (not run to a result:$_missing)}"
	[ -n "$INTERRUPTED" ] && say "Interrupted:  SIG$INTERRUPTED"
	say "Restored:     $([ "$_restored" = 1 ] && echo yes || echo "NO - see above; the next run refuses to start until fixed")"
	say "Plugin files: $_tree"
	[ -n "$HARNESS_ERRORS" ] && say "Harness errors:$HARNESS_ERRORS"
	if [ "$N_SURVIVED" -ne 0 ] || [ "$N_ERROR" -ne 0 ]; then
		say "Not caught:"
		awk -F '\t' '$2 != "CAUGHT" { printf "  #%-3s %-8s %s -> %s\n", $1, $2, $3, $4 }' "$RESULTS" | tee -a "$MAIN_LOG"
	fi
	say "Logs:         $(winpath "$LOG_DIR")"
	say "Result:       $_result"

	if [ -n "$INTERRUPTED" ]; then exit "$_code"; fi
	[ "$_result" = PASS ] && exit 0
	[ "$_code" -ne 0 ] && [ "$_code" -ne 1 ] && exit "$_code"
	exit 1
}

# ---------------------------------------------------------------- main

[ "$RECOVER" = 1 ] && { [ "$SELECT" = all ] && [ "$LIST" = 0 ] || usage; recover; }

MODE=count ID=0
define_mutations
if [ "$N_SELECTED" -eq 0 ]; then
	echo "No mutation selected." >&2
	exit 2
fi
if [ "$SELECT" = scheduler ]; then
	if [ -n "${SCHED_MISMATCH:-}" ] || [ "$SCHED_FILE" -ne "$SCHED_LABEL" ]; then
		echo "Scheduler mutations must be exactly those of class-scheduler.php, labelled 'scheduler: '. Mismatch: ${SCHED_MISMATCH:-counts $SCHED_FILE/$SCHED_LABEL}" >&2
		exit 2
	fi
	if [ "$N_SELECTED" -ne "$EXPECTED_SCHEDULER" ]; then
		echo "Expected $EXPECTED_SCHEDULER scheduler mutations, found $N_SELECTED: update EXPECTED_SCHEDULER only if that is intended." >&2
		exit 2
	fi
fi
if [ "$LIST" = 1 ]; then
	MODE=list ID=0
	define_mutations
	echo "$N_SELECTED of $N_TOTAL mutations selected."
	exit 0
fi

refuse_if_legacy_backups
if ! mkdir "$STATE" 2>/dev/null; then
	if lock_owner_alive; then
		echo "Another mutation check is running (pid $(cat "$STATE/pid")): runs share one database." >&2
	else
		echo "A previous run ended without cleanup (killed, crashed, or power loss): $STATE exists." >&2
		[ -f "$STATE/target" ] && echo "It was mutating $(cat "$STATE/target")." >&2
		echo "Run: sh dev/mutation-check.sh --recover" >&2
	fi
	exit 2
fi
echo $$ >"$STATE/pid"
new_log_dir
IN_RUN=1
trap finish EXIT
trap 'on_signal INT 130' INT
trap 'on_signal TERM 143' TERM
trap 'on_signal HUP 129' HUP

say "Mutation check: $N_SELECTED of $N_TOTAL mutations ($SELECT${MATCH:+: $MATCH}), CLI_SERVICE=${CLI_SERVICE:-cli}, replacements: $PYTHON_WHERE"
say "Logs: $(winpath "$LOG_DIR")"
if ! project_name; then
	harness_error "docker compose is not usable (see $(winpath "$MAIN_LOG"))"
	exit 1
fi
ONEOFF_BEFORE=$(oneoff_containers | tr '\n' ' ')
if [ -n "$ONEOFF_BEFORE" ]; then
	harness_error "test containers of project '$PROJECT' are already running ($ONEOFF_BEFORE): another test run uses the database"
	ONEOFF_BEFORE= # Not ours: never removed by this run.
	PROJECT=
	exit 1
fi
snapshot >"$LOG_DIR/tree-before"
if [ -n "$(git status --porcelain -- tablerefresh 2>/dev/null)" ]; then
	say "Note: the plugin has uncommitted changes; mutations apply to the working tree as it is."
fi

say "Baseline: the selected tests without mutation"
CUR_LOG="$LOG_DIR/000-baseline.log"
run_tests "$FILTERS" "$CUR_LOG"
_brc=$?
[ -f "$JUNIT_HOST" ] && mv "$JUNIT_HOST" "$LOG_DIR/000-baseline.junit.xml"
classify "$_brc" "$LOG_DIR/000-baseline.junit.xml" "$CUR_LOG"
case $STATUS in
	SURVIVED) BASELINE="passed ($DETAIL)" ;; # Everything passed, nothing skipped.
	ERROR)
		case $DETAIL in
			*"skipped or incomplete: inconclusive") BASELINE="passed (${DETAIL%%; passed*})" ;;
			*) BASELINE="FAILED ($DETAIL)" ;;
		esac
		;;
	*) BASELINE="FAILED ($DETAIL)" ;;
esac
say "         $BASELINE"
case $BASELINE in
	passed*) ;;
	*)
		tail -n 15 "$CUR_LOG" | sed 's/^/         | /'
		say "         log: $(winpath "$CUR_LOG")"
		harness_error "the selected tests do not pass without mutation; no mutation was run"
		exit 1
		;;
esac

MODE=run ID=0
define_mutations
exit 0
