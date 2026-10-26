#!/bin/sh
# Clean WordPress site for release-candidate checks (http://localhost:8090, admin / admin).
#
#   sh dev/rc-env.sh up [<wp-version>]   # fresh site; TablePress from wordpress.org; no TableRefresh
#   sh dev/rc-env.sh wp <args>           # WP-CLI in that site; ZIPs from dist/ are under /dist
#   sh dev/rc-env.sh down                # remove the site and its volumes
#
# Example: sh dev/rc-env.sh wp plugin install /dist/tablerefresh-1.0.0-rc1.zip --activate
set -eu
cd "$(dirname "$0")"
export MSYS_NO_PATHCONV=1
DC="docker compose -f docker-compose.rc.yml"

case "${1:-}" in
	up)
		$DC down -v >/dev/null 2>&1 || true
		$DC up -d --wait db wordpress
		until $DC exec -T wordpress test -f /var/www/html/wp-config.php; do sleep 2; done
		if [ -n "${2:-}" ]; then
			$DC run --rm -T cli wp core download --version="$2" --force --skip-content
		fi
		$DC run --rm -T cli wp core install --url=http://localhost:8090 --title="TableRefresh RC" \
			--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
		$DC run --rm -T cli wp plugin install tablepress --activate
		$DC run --rm -T cli wp core version --extra
		;;
	wp)
		shift
		$DC run --rm -T cli wp "$@"
		;;
	down)
		$DC down -v
		;;
	*)
		echo "usage: $0 up [<wp-version>] | wp <args> | down" >&2
		exit 1
		;;
esac
