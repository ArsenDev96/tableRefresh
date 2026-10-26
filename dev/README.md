# TableRefresh – local test environment

Isolated Docker stack: MariaDB 10.11, WordPress 7.1.3 (PHP 8.4, Apache), WP-CLI (PHP 8.3, and PHP 7.4 as `cli74`), and a fixture HTTP server. Everything binds to `127.0.0.1`. The free TablePress plugin is mounted **read-only**.

```sh
sh dev/setup.sh                          # first time: downloads TablePress + PHPUnit, installs the site
sh dev/run-tests.sh                      # all integration tests (no internet needed)
CLI_SERVICE=cli74 sh dev/run-tests.sh    # same tests on PHP 7.4
sh dev/run-tests.sh --group external     # real Google responses (needs internet)
TABLEREFRESH_LIVE_SHEET_URL='https://docs.google.com/spreadsheets/d/…/edit' sh dev/run-tests.sh --group external
docker compose -f dev/docker-compose.yml down -v   # remove everything
```

Site: http://localhost:8089 (admin / admin). Manual sync:

```sh
docker compose -f dev/docker-compose.yml run --rm cli wp tablerefresh sync 1 http://fixtures/static/simple.csv
```

## How the tests work

- `tests/` uses PHPUnit 9.6 against the live site (not `WP_UnitTestCase`, whose per-test transaction would hide the transaction behavior under test).
- Crash, `exit()`, and concurrency scenarios run as real, separate `wp` processes.
- `dev/mu-plugins/tablerefresh-test-env.php` (**dev only, never ship**) allowlists the private `fixtures` host and injects faults through environment variables.
- `dev/fixtures/server.py` serves CSV, HTML, redirects, slow/hanging, oversized, gzip, and binary responses.
