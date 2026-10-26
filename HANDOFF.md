# Handoff – 2026-10-27 (end of day)

## Where things stand

- Branch `main`, HEAD = this handoff commit on top of `472d940` (tag `v1.0.0-rc1`, local only).
- Plugin code (`tablerefresh/`) is identical to `v1.0.0-rc1`. Commits after the tag change only
  docs and dev tooling.
- RC1 ZIP: `sh dev/build-zip.sh v1.0.0-rc1 rc1` rebuilds `dist/tablerefresh-1.0.0-rc1.zip`
  (25 files; SHA-256 of the original build: 5b5d393f0c06227df77ef911e10b41c84205c8c6c15302d932f60f7f598857d1).
- `.cache/` (TablePress, PHPUnit, PHPCS tools) and `dist/` are not in git; `dev/setup.sh` and the
  commands in `dev/release-checks/README.md` recreate them.

## RC1 verification (v1.0.0-rc1)

Passed:
- Full suite, PHP 8.3 and PHP 7.4: 238 tests, 2072 assertions, 4 skipped (Redis-only tests).
- Full suite + Redis object cache, PHP 8.3 and PHP 7.4: 238 tests, 2103 assertions.
- Mutations 1–15 of 39: all caught.
- Earlier on the RC1 code: PHPCS (WPCS 3.1 + PHPCompatibilityWP 7.4+) clean; Plugin Check 2.1.0
  (all checks incl. experimental and runtime) no errors; browser workflow on a clean site from the
  ZIP 36/36 PASS (`dev/release-checks/rc1-results/browser-workflow.txt`); axe 0 violations, keyboard
  and RTL checks; upgrades 0.1.0 → 0.2.0 → 1.0.0 and 0.2.0 → RC1; lifecycle (deactivate, activate,
  table delete, uninstall).

Failed: nothing.

Interrupted: mutation 16 (“no decoded-size abort”), stopped on request; no result.

Not run on RC1: mutations 16–39; concurrency/transaction-loss/recovery/scheduling repetitions;
MyISAM and live-Google groups; db (5), WordPress (5), PHP (4) and TablePress (25 releases) matrices.
The last complete run of these was on 65d09f6 (Phase 1.5, `dev/verification-log.txt`), before
the Phase 2 admin code.

## Open before a public release

1. `tablerefresh/readme.txt` line 2: `Contributors: tablerefresh` is a placeholder; needs the
   owner's WordPress.org username. Changing it means a new ZIP (rc2 or final).
2. Remaining RC verification (above), especially the TablePress matrix once on the RC.
3. Manual owner check: edit a real Google Sheet you own and sync it (the automated workflow used
   Google's read-only sample sheet, changed through a query URL).
4. Optional: a real screen-reader pass (only semantics were checked).
5. `dev/verification-log.txt`: replace with the RC results; it still lists the undo() residual
   that 548cb65 fixed.
6. WordPress.org submission needs the owner's explicit approval.

No known critical bugs.
