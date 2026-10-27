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
sh dev/mutation-check.sh                 # disables each protection; the tests must notice (Python: host, else fixtures container)
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
