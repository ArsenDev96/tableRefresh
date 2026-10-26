#!/bin/sh
# Enables or disables the Redis persistent object cache in the test site.
#
#   sh dev/redis.sh enable    # Redis Object Cache plugin (Predis client) + object-cache.php drop-in
#   sh dev/redis.sh disable
set -e
cd "$(dirname "$0")"
export MSYS_NO_PATHCONV=1
WP="docker compose run --rm -T cli wp"

case "$1" in
	enable)
		$WP config set WP_REDIS_HOST redis >/dev/null
		$WP config set WP_REDIS_CLIENT predis >/dev/null
		$WP plugin activate redis-cache >/dev/null
		$WP redis enable --force
		docker compose restart wordpress >/dev/null # Fresh OPcache for the drop-in.
		$WP redis status | grep -E "^(Status|Client|Drop-in):"
		;;
	disable)
		$WP redis disable || true
		$WP plugin deactivate redis-cache >/dev/null || true
		docker compose restart wordpress >/dev/null
		;;
	*)
		echo "usage: $0 enable|disable" >&2
		exit 1
		;;
esac
