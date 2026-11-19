# TableRefresh – local test environment

Isolated Docker stack: MariaDB 10.11 (configurable), WordPress 7.1.3 (PHP 8.4, Apache), WP-CLI (PHP 8.3, and PHP 7.4 as `cli74`), Redis 7.4, and a fixture HTTP server with a forward proxy. Everything binds to `127.0.0.1`. The free TablePress plugin is mounted **read-only**.

```sh
sh dev/setup.sh                          # first time: downloads TablePress + PHPUnit, installs the site
sh dev/run-tests.sh                      # default suite (no internet needed)
CLI_SERVICE=cli74 sh dev/run-tests.sh    # same tests on PHP 7.4 (libcurl 7.83)
sh dev/run-tests.sh --group engine       # converts wp_posts/wp_postmeta to MyISAM and back
sh dev/run-tests.sh --group external     # live Google Sheets (internet)
sh dev/redis.sh enable                   # persistent object cache; PersistentCacheTest runs only then
sh dev/redis.sh disable
sh dev/mutation-check.sh                 # disables each protection; the tests must notice (see "Mutation check")
sh dev/mutation-check.sh --scheduler     # only the 18 scheduler mutations; --list shows a selection
sh dev/test-matrix.sh db                 # MariaDB 10.11/11.4, MySQL 5.7/8.0/8.4
sh dev/test-matrix.sh tablepress 3.0 3.4.1   # TablePress releases from wordpress.org
sh dev/test-matrix.sh wp 6.7.10 7.1.3        # WordPress versions
sh dev/test-matrix.sh php 8.1 8.2 8.4        # PHP versions of WP-CLI/PHPUnit (web server: PHP 8.4)
sh dev/build-zip.sh HEAD rc1                 # plugin ZIP from a commit, into dist/
sh dev/rc-env.sh up                          # clean site on :8090 without the checkout; install ZIPs from /dist
docker compose -f dev/docker-compose.yml down -v   # remove everything
```

Results of the matrices are appended to `dev/matrix-results.txt` (full output of each run: `/tmp/<project>-suite.log`); `dev/verification-log.txt` holds the last full verification run.

Site: http://localhost:8089 (admin / admin). Manual sync:

```sh
docker compose -f dev/docker-compose.yml run --rm cli wp tablerefresh sync 1 http://fixtures/static/simple.csv
```

## How the tests work

- `tests/` uses PHPUnit 9.6 against the live site (not `WP_UnitTestCase`, whose per-test transaction would hide the transaction behavior under test).
- Crash, `exit()`, concurrency, proxy, and concurrent-edit scenarios run as real, separate `wp` processes.
- Deadlocks and lost connections are real: a second database connection deadlocks with, or kills, the syncing process's connection during its transaction.
- Human edits go through the real TablePress edit screen and its AJAX save, served by Apache with real login cookies.
- `dev/mu-plugins/tablerefresh-test-env.php` (**dev only, never ship**) allowlists the private `fixtures` host and injects faults and settings through environment variables.
- `dev/fixtures/server.py` serves CSV, HTML, redirects, Google-shaped exports, slow/hanging, oversized, gzip-bomb, truncated, and binary responses, and runs a forward proxy on port 3128.
- On Windows bind mounts, Apache's OPcache may not notice file changes; `mutation-check.sh` restarts Apache around each mutation.
- Parallel-process tests start their commands together through `TABLEREFRESH_TEST_BARRIER`, so slow WordPress boots cannot serialize them.
- `--repeat` suits tests that run separate `wp` processes; repeating the in-process download tests exceeds PHP's 128 MB `memory_limit` (PHPUnit keeps every repetition), so loop `run-tests.sh` instead.
- Run one Docker operation at a time: the matrix stops the main project, and parallel runs share one database.
- WP-Cron: WordPress starts scheduled events by requesting its own address (`http://localhost:<port>/wp-cron.php`).
  On the dev site (:8089) that request cannot connect (Apache listens on port 80 in the container), so events never run
  by themselves; this keeps background syncs out of the tests, which call `/wp-cron.php` or `wp cron event run`
  explicitly. The clean RC site (:8090) also serves port 8090 inside the container (`rc-loopback.conf`), so its WP-Cron
  runs on page visits as on a real site. `wp cron test` fails on both: WP-CLI runs in the separate `cli` container.
  Every WP-CLI command also attempts a spawn there and sets WordPress's 60-second `doing_cron` lock, so a page visit
  right after a WP-CLI command does not start WP-Cron.

## Mutation check

`dev/mutation-check.sh` replaces one exact snippet in a plugin file at a time, runs the tests named for it, and
restores the file. Options: `--scheduler` (the scheduler mutations only; the script refuses to run if their number
is not the expected 18), `--match REGEX` (descriptions), `--list` (show the selection, run nothing), `--recover`.
Replacements run in Python: on the host if it has one, else in the fixtures container.

- First the selected tests run once without mutation (baseline). If they do not pass, no mutation runs.
- Results come from PHPUnit's JUnit report and exit status, not from the text output:
  **CAUGHT** = the tests ran and an assertion failed; **SURVIVED** = every selected test ran and passed;
  **ERROR** = inconclusive (snippet not found exactly once, Docker or PHPUnit failure, PHP fatal error, no test
  matched the filter, test errors such as exceptions instead of assertion failures, warnings, or skipped tests).
- The summary lists selected, executed, caught, survived, errors and incomplete mutations, and whether every plugin
  file is byte-identical to the start of the run. Exit status 0 (PASS) only if all selected mutations were caught;
  1 (FAIL) otherwise; 2 if the run refused to start; 130/143/129 after Ctrl+C, SIGTERM or SIGHUP.
- Logs of each run (test output, the applied diff, JUnit reports) go to `/tmp/tablerefresh-mutation-*`.
- Some mutations are caught only in a specific setup, as their descriptions say (Redis enabled, `CLI_SERVICE=cli74`).
  Elsewhere their tests are skipped or pass, which reports ERROR or SURVIVED.

Safety: only one run at a time; the lock directory `dev/.mutation-state` (ignored by Git) also holds the backup of
the file being mutated, never the plugin directory. On normal exit, errors, Ctrl+C, SIGTERM and SIGHUP the script
stops the running test, including its Docker container (killing the `docker compose run` client alone leaves the
container running against the database), and restores the file, unless the file was changed by someone else during
the run: then it leaves the file as it is and prints how to compare it with the original.

A run killed with SIGKILL (Task Manager, `taskkill /F`, a tool's timeout), a crash or a power loss cannot clean up:
the file may stay mutated and its test container may keep running. The next run refuses to start. Then run
`sh dev/mutation-check.sh --recover`: it removes the project's running `docker compose run` containers, restores the
file if it still holds exactly the original or the mutation, restarts Apache (OPcache), and removes the lock.
Backups named `*.orig` next to plugin files come from the earlier version of the script; it refuses to start
while they exist (compare with `diff -u FILE.orig FILE`, then `mv FILE.orig FILE`).
