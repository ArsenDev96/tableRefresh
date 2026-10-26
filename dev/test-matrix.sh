#!/bin/sh
# Runs the full test suite against other database servers and TablePress versions, each in a
# fresh, separate Docker project (the main "tablerefresh" project is stopped meanwhile).
#
#   sh dev/test-matrix.sh db                     # all database servers below
#   sh dev/test-matrix.sh tablepress 3.0 3.4.1   # TablePress versions from wordpress.org
#
# Results are appended to dev/matrix-results.txt.
set -u
cd "$(dirname "$0")"
export MSYS_NO_PATHCONV=1
RESULTS=matrix-results.txt
MYSQL_HC='mysqladmin ping -h 127.0.0.1 -uroot -proot --silent'

run_project() { # project, label
	export COMPOSE_PROJECT_NAME="$1"
	docker compose down -v >/dev/null 2>&1
	if ! sh setup.sh >"/tmp/$1-setup.log" 2>&1; then
		echo "$2: SETUP FAILED (see /tmp/$1-setup.log)" | tee -a "$RESULTS"
	else
		server=$(docker compose run --rm -T cli wp eval 'global $wpdb; echo $wpdb->db_server_info();' 2>/dev/null | tail -1)
		tp=$(docker compose run --rm -T cli wp plugin get tablepress --field=version 2>/dev/null | tail -1)
		main=$(sh run-tests.sh 2>&1 | grep -E "^(OK|Tests:)" | tail -1)
		engine=$(sh run-tests.sh --group engine 2>&1 | grep -E "^(OK|Tests:)" | tail -1)
		echo "$2 [server: $server, TablePress $tp]: suite: $main | engine group: $engine" | tee -a "$RESULTS"
	fi
	docker compose down -v >/dev/null 2>&1
	unset COMPOSE_PROJECT_NAME
}

(unset COMPOSE_PROJECT_NAME; docker compose stop >/dev/null 2>&1)
echo "== $(date -u '+%Y-%m-%d %H:%M UTC') $*" >>"$RESULTS"

case "${1:-}" in
	db)
		for spec in "mariadb:10.11|healthcheck.sh --connect --innodb_initialized" \
		            "mariadb:11.4|healthcheck.sh --connect --innodb_initialized" \
		            "mysql:5.7.22|$MYSQL_HC" \
		            "mysql:8.0|$MYSQL_HC" \
		            "mysql:8.4|$MYSQL_HC"; do
			export DB_IMAGE="${spec%%|*}" DB_HEALTHCHECK="${spec#*|}"
			run_project "tr-$(echo "$DB_IMAGE" | tr -c 'a-z0-9\n' '-')" "$DB_IMAGE"
		done
		unset DB_IMAGE DB_HEALTHCHECK
		;;
	tablepress)
		shift
		for version in "$@"; do
			dir="../.cache/tablepress-versions/$version"
			if [ ! -f "$dir/tablepress/tablepress.php" ]; then
				mkdir -p "$dir"
				curl -sSfL -o "$dir/tablepress.zip" "https://downloads.wordpress.org/plugin/tablepress.$version.zip" && unzip -q -o "$dir/tablepress.zip" -d "$dir"
			fi
			export TABLEPRESS_DIR="$dir/tablepress"
			run_project "tr-tp-$(echo "$version" | tr -c 'a-z0-9\n' '-')" "TablePress $version"
		done
		unset TABLEPRESS_DIR
		;;
	*)
		echo "usage: $0 db | tablepress <version>..." >&2
		exit 1
		;;
esac

(unset COMPOSE_PROJECT_NAME; docker compose up -d --wait db wordpress fixtures >/dev/null 2>&1)
