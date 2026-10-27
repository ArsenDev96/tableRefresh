# Handoff – 2026-10-27 (second machine, focused verification)

## Where things stand

- Branch `main`; tag `v1.0.0-rc1` = `472d940` (local only, recreated on this machine; not pushed).
- Plugin code (`tablerefresh/`) is identical to `v1.0.0-rc1`. Later commits change only docs and dev tooling.
- RC1 ZIP: `sh dev/build-zip.sh v1.0.0-rc1 rc1` → `dist/tablerefresh-1.0.0-rc1.zip`, 25 files,
  SHA-256 `4079166f6d4d822ed12dcb5f97ea4c4007bee25cbb80028ee78dcabeeda33072` (reproducible now;
  the earlier hash 5b5d393f… came from a build stamped with its build time).
- Build script fixed: Git for Windows (`core.autocrlf=true`) made the ZIP ship CRLF files.
- `.cache/`, `dist/`, browser `node_modules/` are not in git; `dev/setup.sh` and
  `dev/release-checks/README.md` recreate them.

## Testing strategy (owner's decision, 2026-10-27)

Focused security and data-integrity checks. The full TablePress (25), database (5), WordPress,
PHP matrices and the complete 39-mutation run are deferred, not automatic.

## RC1 verification

Results: `dev/release-checks/rc1-results/focused-verification.txt` (this round) and `summary.txt` (first round).

Passed on RC1:
- PHP 7.4 full suite: 238 tests, 0 failures. PHP 8.3 full suite: 237/238 in each of two runs, a
  different wall-clock assertion in `ConcurrencyTest` each time; both tests pass 10/10 in isolation
  (timing on this machine, not a product defect). Earlier machine: 8.3, 7.4, and both with Redis clean.
- Mutations: 29 of 39 caught, none survived (1–15 earlier; 16, 17, 19, 20, 22, 25, 28–32, 36, 37,
  39 now, incl. the decoded-size abort that had been interrupted).
- TablePress 3.0 (minimum) spot check: full suite + MyISAM group clean.
- Clean site, plugin uploaded from the ZIP: browser workflow 36/36 (live public Google sample sheet
  through the admin screen); Plugin Check 2.1.0 no errors.
- Earlier on RC1: PHPCS clean; axe 0 violations, keyboard, RTL; upgrades; lifecycle.

Untested on RC1: TablePress 3.0.1–3.4 (3.4.1 is the default of every run); MySQL 5.7/8.0/8.4 and
MariaDB 11.4; WordPress 6.7–7.0; PHP 8.0–8.2/8.4 CLI; Redis; repetitions; live-Google PHPUnit
group; mutations 18, 21, 23, 24, 26, 27, 33, 34, 35, 38 (last caught on 65d09f6).

## Open before a public release

1. `tablerefresh/readme.txt` line 2: `Contributors: tablerefresh` is still a placeholder (owner's
   decision for now). Changing it means a new ZIP (rc2 or final).
2. Owner check with an editable Google Sheet they own (skipped this round).
3. Optional: deferred matrices, at least TablePress 3.1–3.4 and MySQL 8.x.
4. Optional: real screen-reader pass.
5. WordPress.org submission needs the owner's explicit approval.

No known critical bugs.
