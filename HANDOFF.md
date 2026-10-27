# Handoff – 2026-10-27 (home computer, after RC2)

## Where things stand

- Branch `main`; RC2 = `06ddaa2`, results committed after it. Plugin version 1.0.0 (header, readme Stable tag, constant).
- Tag `v1.0.0-rc1` = `472d940` (RC1). Its ZIP: `sh dev/build-zip.sh v1.0.0-rc1 rc1`, 25 files,
  SHA-256 `4079166f6d4d822ed12dcb5f97ea4c4007bee25cbb80028ee78dcabeeda33072` with Git for Windows 2.45
  (another Git/zlib version may compress differently: then compare the files, not the hash).
- Plugin code changed after RC1 (`0626e32`, admin wording and remove confirmation), so RC2 was built (see below).
- GitHub repository is public; no Actions workflows or releases. GitHub has a lightweight `v1.0.0-rc1`
  tag on `472d940`; this machine has an annotated tag of the same name and commit (fetch reports
  "would clobber existing tag"; harmless). Checked 2026-10-27.

## Completed

- Sync engine: CSV and public Google Sheets, atomic updates with recovery, conflict detection for
  edits in TablePress, editor guard, SSRF/proxy/size limits, revision limits, WP-CLI.
- Admin (top-level TableRefresh menu, below TablePress): list, setup, settings and history, Sync now, keep/replace decision,
  WP-Cron schedule (hourly/daily/weekly), pause after 5 failures.
- RC1 verification: `dev/release-checks/rc1-results/focused-verification.txt` (suites, 29/39 mutations,
  TablePress 3.0, browser workflow 36/36, Plugin Check). Automatic sync with the owner's editable Google
  Sheet on the RC site: updated through WP-Cron on a normal page visit (25 → 58), 2026-10-27.
- Dev: reproducible ZIP builds (LF, pinned mtime); RC site WP-Cron loopback (`dev/rc-loopback.conf`).

## Latest UI changes (after RC1)

- Setup page heading "Set up table sync"; table page "Settings and history: <name> (ID <id>)".
- "Remove sync setup" replaces "Remove automatic sync"; needs an "I understand" checkbox (also checked
  on the server). Tests and mutation "admin: remove without confirmation" added.
- New WordPress.org screenshots (`release/wordpress-org-assets/`, made with `store-shots.js`).

## RC2 (2026-10-27, home computer)

- RC2 = `06ddaa2` (not tagged). ZIP: `sh dev/build-zip.sh 06ddaa2 rc2`, 25 files,
  SHA-256 `5d9f23ffcbc1e1cbe5b3de50df1f18294759f398932788d3b08ba23e862a2e28` (Git for Windows 2.46.2;
  RC1 rebuilds to its recorded hash with 2.46.2 too).
- `ConcurrencyTest` timing tests now observe lock state instead of wall-clock limits (tests only).
- Focused checks passed: `dev/release-checks/rc2-results/focused-verification.txt` (PHP 8.3 + 7.4
  suites, admin mutations 5/5, PHPCS, Plugin Check, browser workflow 36/36).
- Contributors set to `arsenmanukyan` in `faaac1f`. Final ZIP: `sh dev/build-zip.sh faaac1f rc2-final`,
  SHA-256 `defbb9fadef2bfd9dd2f3dbcb7c92b5b985d8b992152f516f14acef576e85263`; Plugin Check: no errors.
  Only readme.txt line 2 differs from the `06ddaa2` RC2 ZIP. No RC2 tag yet (owner's decision).

## After RC2: top-level menu (9d79fae)

- TableRefresh is a top-level admin menu directly below TablePress (was Tools > TableRefresh), with an
  original table-and-sync icon. Position follows TablePress (top/middle/bottom); `menu_order` keeps it
  adjacent on collisions; without a TablePress top-level entry it goes after the content menus. The
  former `tools.php?page=tablerefresh` redirects to `admin.php?page=tablerefresh`. No sync engine change.
- Checked on a test ZIP (`sh dev/build-zip.sh 9d79fae menu`): PHP 8.3 full suite OK (242 tests),
  AdminScreenTest on PHP 7.4 OK, PHPCS clean, 5 menu mutations caught (2 of them added to mutation-check.sh),
  browser workflow 38/38 (2 new menu checks), Plugin Check no errors. New WordPress.org screenshots.
- The plugin changed after RC2, so the next release build is RC3.

## Remaining

1. Build RC3 from the menu change and re-run focused checks before submission.
2. Optional: deferred matrices (TablePress 3.1–3.4, MySQL 8.x); screen-reader pass.
3. WordPress.org submission only with the owner's explicit approval.

## Tools

Git (Git Bash on Windows), Docker Desktop with Compose v2, Node.js 18+ and npm, Google Chrome (browser
checks; path in the scripts: `C:/Program Files/Google/Chrome/...`), internet for the first setup.
Host PHP and Python are optional. Details: `dev/README.md`, `dev/release-checks/README.md`.

## Continue on another computer

```sh
git clone https://github.com/ArsenDev96/tableRefresh.git && cd tableRefresh
git log --oneline -1 && git tag -l           # HEAD = the handoff commit; v1.0.0-rc1 once it was pushed
sh dev/setup.sh                               # dev site http://localhost:8089 (admin / admin)
sh dev/run-tests.sh                           # PHP 8.3 suite; CLI_SERVICE=cli74 for PHP 7.4
sh dev/build-zip.sh v1.0.0-rc1 rc1            # optional: compare with the RC1 hash above
sh dev/rc-env.sh up                           # clean site http://localhost:8090, install ZIPs from dist/
(cd dev/release-checks/browser && npm ci)     # browser checks
```

`.cache/`, `dist/`, `node_modules/` and Docker volumes are not in Git; the commands above recreate them.
The office RC site's data (test tables) is not transferred and not needed.
