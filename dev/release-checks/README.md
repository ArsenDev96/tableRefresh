# Release-candidate checks

Scripts used to verify a release candidate. They are not part of the plugin ZIP.

- `verify-rc.sh`: the complete verification of a release candidate, strictly sequential
  (suites, Redis, mutations, repetitions, MyISAM, live Google, db/wp/php/TablePress matrices;
  about 4.5 hours). Results go to `.cache/verify-rc/` (override with `OUT=`); copy the summary
  into `rc1-results/` or `dev/verification-log.txt` afterwards. Edit the step list to run a part.
- `browser/`: puppeteer-core 22 + axe-core 4.10 against local Chrome (`npm ci` here first).
  - `workflow.js`: the complete admin workflow on the clean site of `dev/rc-env.sh up`
    (http://localhost:8090), with the plugin installed from a ZIP in `dist/`; uses a real
    public Google sample sheet.
  - `a11y.js`, `a11y2.js`: axe, keyboard order and visible focus, RTL, on the same site.
  - `shots.js`: screenshots of the dev site (http://localhost:8089).
  - `store-shots.js <dir>`: the WordPress.org screenshots, on a fresh site with TablePress and the
    plugin active and no tables (default http://localhost:8090; `TR_BASE` and `TR_WP` select another).
  - `assets.js`: renders `release/wordpress-org-assets/` icons and banners.
  - Chrome and Git Bash are expected at `C:/Program Files/Google/Chrome/...` and
    `C:/Program Files/Git/bin/bash.exe`; the repository path is derived from the script location.
  - `workflow.js` uploads `tablerefresh.zip` from this folder: copy the ZIP from `dist/` first.
- `rc1-results/`: results for v1.0.0-rc1 (472d940): `summary.txt` (first run, stopped partway) and
  `focused-verification.txt` (focused checks of 2026-10-27).

Coding standards and Plugin Check (no host PHP needed):

```sh
# once: WPCS 3.1 + PHPCompatibilityWP into .cache/tools/phpcs
mkdir -p .cache/tools/phpcs && MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W 2>/dev/null || pwd)/.cache/tools/phpcs:/app" -w /app composer:2 \
  sh -c 'composer init -n --name=tr/phpcs >/dev/null && composer config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true && composer require -n --quiet "wp-coding-standards/wpcs:^3.1" "phpcompatibility/phpcompatibility-wp:^2.1" "phpcompatibility/php-compatibility:^9.3"'
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W 2>/dev/null || pwd):/repo" -w /repo/tablerefresh composer:2 \
  php /repo/.cache/tools/phpcs/vendor/bin/phpcs --standard=.phpcs.xml.dist --report=summary

# Plugin Check 2.1 on the clean site, with the ZIP installed
sh dev/build-zip.sh HEAD rc1 && sh dev/rc-env.sh up
sh dev/rc-env.sh wp plugin install /dist/tablerefresh-1.0.0-rc1.zip --force --activate
sh dev/rc-env.sh wp plugin install plugin-check --activate
sh dev/rc-env.sh wp plugin check tablerefresh --format=table --include-experimental --require=./wp-content/plugins/plugin-check/cli.php

# translation template
cd dev && MSYS_NO_PATHCONV=1 docker compose run --rm -T cli wp i18n make-pot /var/www/html/wp-content/plugins/tablerefresh /fixtures-data/tablerefresh.pot --domain=tablerefresh
```
