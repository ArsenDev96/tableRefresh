#!/bin/sh
# Complete verification of the release candidate. Strictly sequential: one Docker operation at a time.
set -u
cd "$(dirname "$0")/../.."
OUT="${OUT:-$PWD/.cache/verify-rc}"
mkdir -p "$OUT"
SUM="$OUT/summary.txt"
n=0

step() { # label, command...
	label="$1"; shift
	n=$((n + 1))
	log="$OUT/$(printf %02d $n)-$(echo "$label" | tr -c 'A-Za-z0-9\n' '_').log"
	start=$(date -u +%H:%M:%S)
	"$@" >"$log" 2>&1
	code=$?
	res=$(grep -E "^(OK|Tests:)" "$log" | tr '\n' ' ')
	mut=$(grep -cE "^caught " "$log")
	[ "$mut" -gt 0 ] && res="$res caught=$mut survived=$(grep -cE '^SURVIVED ' "$log") errors=$(grep -cE '^ERROR ' "$log")"
	echo "[$start-$(date -u +%H:%M:%S) UTC] $label (exit $code): $res" | tee -a "$SUM"
}

echo "== $(date -u '+%Y-%m-%d %H:%M UTC') HEAD $(git rev-parse --short HEAD) ($(git describe --tags 2>/dev/null)), working tree: $(git status --porcelain | wc -l) changed files" | tee -a "$SUM"
(cd dev && MSYS_NO_PATHCONV=1 docker compose down -v >/dev/null 2>&1)
step "fresh setup" sh dev/setup.sh

step "PHP 8.3 full suite" sh dev/run-tests.sh
step "PHP 7.4 full suite" env CLI_SERVICE=cli74 sh dev/run-tests.sh
step "redis enable" sh dev/redis.sh enable
step "PHP 8.3 + Redis full suite" sh dev/run-tests.sh
step "PHP 7.4 + Redis full suite" env CLI_SERVICE=cli74 sh dev/run-tests.sh
step "mutations (PHP 7.4 + Redis)" env CLI_SERVICE=cli74 sh dev/mutation-check.sh
step "redis disable" sh dev/redis.sh disable

step "PHP 8.3 same table x20" sh dev/run-tests.sh --filter test_only_one_of_several_parallel_processes_writes --repeat 20
step "PHP 8.3 different tables x40" sh dev/run-tests.sh --filter test_different_tables_sync_in_parallel --repeat 40
step "PHP 7.4 same table x10" env CLI_SERVICE=cli74 sh dev/run-tests.sh --filter test_only_one_of_several_parallel_processes_writes --repeat 10
step "PHP 7.4 different tables x20" env CLI_SERVICE=cli74 sh dev/run-tests.sh --filter test_different_tables_sync_in_parallel --repeat 20
step "PHP 8.3 ConcurrencyTest + EditorConflictTest x3" sh dev/run-tests.sh --filter 'ConcurrencyTest|EditorConflictTest' --repeat 3
step "PHP 8.3 TransactionLossTest x5" sh dev/run-tests.sh --filter TransactionLossTest --repeat 5
step "PHP 8.3 RecoveryTest x5" sh dev/run-tests.sh --filter RecoveryTest --repeat 5
step "PHP 7.4 TransactionLossTest + RecoveryTest x2" env CLI_SERVICE=cli74 sh dev/run-tests.sh --filter 'TransactionLossTest|RecoveryTest' --repeat 2
step "PHP 8.3 ScheduledSyncTest + AdminScreenTest x3" sh dev/run-tests.sh --filter 'ScheduledSyncTest|AdminScreenTest' --repeat 3
step "PHP 8.3 engine group (MyISAM)" sh dev/run-tests.sh --group engine
step "PHP 7.4 engine group (MyISAM)" env CLI_SERVICE=cli74 sh dev/run-tests.sh --group engine
step "PHP 8.3 external group (live Google)" sh dev/run-tests.sh --group external
step "PHP 7.4 external group (live Google)" env CLI_SERVICE=cli74 sh dev/run-tests.sh --group external

step "matrix db" sh dev/test-matrix.sh db
step "matrix wp" sh dev/test-matrix.sh wp 6.7.10 6.8.11 6.9.10 7.0.7 7.1.3
step "matrix php" sh dev/test-matrix.sh php 8.0 8.1 8.2 8.4
step "matrix tablepress" sh dev/test-matrix.sh tablepress 3.0 3.0.1 3.0.2 3.0.3 3.0.4 3.1 3.1.1 3.1.2 3.1.3 3.2 3.2.1 3.2.2 3.2.3 3.2.4 3.2.5 3.2.6 3.2.7 3.2.8 3.3 3.3.1 3.3.2 3.3.3 3.3.4 3.4 3.4.1
echo "== done $(date -u '+%H:%M UTC'), HEAD $(git rev-parse --short HEAD), working tree: $(git status --porcelain | wc -l) changed files" | tee -a "$SUM"
