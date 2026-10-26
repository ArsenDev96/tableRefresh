#!/bin/sh
# Runs the TableRefresh integration tests inside the isolated Docker environment.
#
#   sh dev/run-tests.sh                 # all tests except the "external" group
#   sh dev/run-tests.sh --group external  # live Google Sheets test (needs internet)
#   sh dev/run-tests.sh --filter SyncTest
#   CLI_SERVICE=cli74 sh dev/run-tests.sh  # same tests on PHP 7.4
set -e
cd "$(dirname "$0")"
export MSYS_NO_PATHCONV=1 # Git Bash on Windows: do not rewrite container paths.

docker compose up -d --wait db wordpress fixtures >/dev/null
docker compose run --rm -T cli wp plugin is-active tablepress >/dev/null 2>&1 || {
	echo "Run dev/setup.sh first." >&2
	exit 1
}
docker compose run --rm -T "${CLI_SERVICE:-cli}" php /tools/phpunit.phar -c /tests/phpunit.xml.dist "$@"
