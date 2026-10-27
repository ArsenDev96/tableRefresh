# Handoff – 2026-10-27 (office → home computer)

## Where things stand

- Branch `main`, based on `0eb2308`. Plugin version 1.0.0 (header, readme Stable tag, constant).
- Tag `v1.0.0-rc1` = `472d940` (RC1). Its ZIP: `sh dev/build-zip.sh v1.0.0-rc1 rc1`, 25 files,
  SHA-256 `4079166f6d4d822ed12dcb5f97ea4c4007bee25cbb80028ee78dcabeeda33072` with Git for Windows 2.45
  (another Git/zlib version may compress differently: then compare the files, not the hash).
- Plugin code changed after RC1 (`0626e32`, admin wording and remove confirmation), so the next ZIP is RC2.
- GitHub repository is public; no Actions workflows, releases, or tags there (checked 2026-10-27).

## Completed

- Sync engine: CSV and public Google Sheets, atomic updates with recovery, conflict detection for
  edits in TablePress, editor guard, SSRF/proxy/size limits, revision limits, WP-CLI.
- Admin (Tools > TableRefresh): list, setup, settings and history, Sync now, keep/replace decision,
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

## Remaining for RC2

1. `tablerefresh/readme.txt` line 2: `Contributors:` needs the owner's WordPress.org username.
2. PHP 8.3 timing tests in `ConcurrencyTest` fail occasionally on slow machines (wall-clock limits);
   make them robust (tests only).
3. Build RC2 (`sh dev/build-zip.sh HEAD rc2`), then focused checks on it: PHP 8.3 + 7.4 suites, admin
   mutations, browser workflow on `sh dev/rc-env.sh up`, Plugin Check.
4. Optional: deferred matrices (TablePress 3.1–3.4, MySQL 8.x); screen-reader pass.
5. WordPress.org submission only with the owner's explicit approval.

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
