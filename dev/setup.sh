#!/bin/sh
# Creates the isolated WordPress + TablePress test site (http://localhost:8089, admin/admin).
#
# Expects TablePress (free, from wordpress.org) in .cache/tablepress-dl/tablepress and
# PHPUnit 9.6 in .cache/tools/phpunit.phar; downloads them if missing.
set -e
cd "$(dirname "$0")"
export MSYS_NO_PATHCONV=1

mkdir -p ../.cache/tablepress-dl ../.cache/tools fixtures/data
if [ ! -f ../.cache/tablepress-dl/tablepress/tablepress.php ]; then
	curl -sSfL -o ../.cache/tablepress-dl/tablepress.zip https://downloads.wordpress.org/plugin/tablepress.latest-stable.zip
	unzip -q -o ../.cache/tablepress-dl/tablepress.zip -d ../.cache/tablepress-dl
fi
[ -f ../.cache/tools/phpunit.phar ] || curl -sSfL -o ../.cache/tools/phpunit.phar https://phar.phpunit.de/phpunit-9.6.phar

docker compose up -d --wait db wordpress fixtures
until docker compose exec -T wordpress test -f /var/www/html/wp-config.php; do sleep 2; done

WP="docker compose run --rm -T cli wp"
$WP core is-installed 2>/dev/null || $WP core install --url=http://localhost:8089 --title="TableRefresh Dev" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
$WP plugin activate tablepress tablerefresh
$WP plugin list
