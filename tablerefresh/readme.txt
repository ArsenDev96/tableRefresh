=== TableRefresh – Auto Sync for TablePress ===
Contributors: tablerefresh
Tags: tablepress, csv, google sheets, sync, import
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps existing TablePress tables in sync with CSV URLs and public Google Sheets. Free, no external services.

== Description ==

**Pre-release – not yet for production use.**

TableRefresh replaces the data of an existing TablePress table with the contents of a CSV file at a URL, or of a public Google Sheet. The table ID, shortcode, name, description, and all table options are kept.

* The complete CSV is downloaded and validated before anything is written.
* Updates are atomic on supported databases (see below); failed updates are rolled back, interrupted updates are recovered on the next sync.
* Only one sync per table runs at a time.
* Manual edits are never overwritten silently: a sync stops if the table was edited after the last sync or is open in the TablePress editor, and the TablePress editor refuses to save an outdated copy over newer synchronized data.
* Downloads are protected against server-side request forgery (private/reserved addresses, DNS rebinding, redirects), oversized and compressed responses, interrupted downloads, and timeouts.
* Cell content from the source is filtered with KSES. Formulas and shortcodes from the source are disabled by default and shown as text.
* At most 5 revisions created by synchronization are kept per table (filter `tablerefresh_revisions_to_keep`); manually created revisions are never deleted.
* Set up under Tools > TableRefresh: pick a table, paste a CSV or Google Sheets link, and choose hourly, daily, or weekly automatic sync. "Sync now" runs a sync immediately.
* Each table shows its last result, its last successful sync, and the 10 most recent attempts.
* If someone changed a table in TablePress after the last sync, automatic sync pauses and you decide: keep the changes (automatic sync is turned off) or replace them with the source data.
* No telemetry, no external services besides the source URL you configure.

== Requirements and guarantees ==

* WordPress 6.7 or newer, PHP 7.4 or newer with the cURL extension.
* The free TablePress plugin, version 3.0 or newer.
* **Atomic updates require InnoDB** for the posts, postmeta, and options tables, on MySQL or MariaDB. On other storage engines (for example MyISAM) or when the engine cannot be determined, synchronization is refused. The filter `tablerefresh_allow_non_atomic_updates` allows best-effort updates there: failures are still undone from a backup, but a crashed process can leave a partially written table, visible to visitors, until the next sync or `wp tablerefresh recover` restores it. Results report `"atomic": false` in that case.
* If the database interrupts a write (for example a deadlock with another write, or a lost connection), TableRefresh blocks all further writes of that sync, undoes the change, and retries up to three times. If the connection is lost at the commit itself, the table is re-read to find out whether the commit took effect; a sync is reported as updated only if its data is stored. Database drop-ins that spread queries over several connections (for example HyperDB or LudicrousDB) are not tested; a change of the connection during a write is treated as an interruption, so such setups may fail to sync, but they never keep a partial write.
* SQLite (for example WordPress Playground) is not supported.
* Downloads through a WordPress HTTP proxy (`WP_PROXY_HOST`) are refused, because the connection cannot be protected against requests to internal networks. Enable them with the filter `tablerefresh_allow_proxy` only if the proxy itself blocks internal networks. Proxy environment variables are ignored.

== Known limitations ==

* Non-atomic mode (opt-in, see above): besides crashes, another write to the table during the sync's own write (for example by another plugin) is not blocked by row locks; if the sync then fails, its backup is restored over that write. A lock that expires during the write can be taken over. Neither case is covered by tests.
* Locks expire after 5 minutes (filter `tablerefresh_lock_ttl`). A sync that runs longer, for example a slow download, is refused before it writes. A crashed sync keeps its table locked until the lock expires; its backup is recovered by the next sync after that.
* Only the TablePress editor's own save is checked against newer synchronized data. Other writers (TablePress import into an existing table, other plugins) can overwrite a sync; the next sync then reports the change as a conflict instead of overwriting it.
* TableRefresh uses TablePress internals that are not a public API: the table model (`load`, `prepare_table`, `sanitize`, `save`), the table-to-post mapping (`_debug_get_tables()` or the `tablepress_tables` option), the post meta keys for options and visibility, and the editor's AJAX save action and nonce. Verified with the free TablePress 3.0 to 3.4.1; TablePress Pro/Max are not tested.
* One source per table, CSV only (or a Google Sheet exported as CSV). Sources that need signing in, including private Google Sheets, are not supported.

== Usage ==

Tools > TableRefresh > "Set up a table". The first sync replaces the current content of the table, after you confirm it.

* Google Sheets: click Share, set General access to "Anyone with the link", click Copy link, and paste the link. The link is converted to the CSV export address automatically. Private sheets are not supported. "File > Share > Publish to web" links work as well.
* Automatic sync uses WordPress scheduled tasks (WP-Cron). They run when someone visits the site, so on sites with few visitors a sync can run later than planned. With `DISABLE_WP_CRON`, syncs run when the server's cron job calls wp-cron.php.
* A failed sync is not retried before the next scheduled time. After 5 failed syncs in a row, automatic sync pauses until a "Sync now" succeeds.
* While the table is open in the TablePress editor, a sync waits for the next scheduled time instead of overwriting unsaved changes.
* Setting up and running syncs requires the `manage_options` capability (filter `tablerefresh_capability`) and TablePress's permission to edit the table.
* Deactivating TableRefresh stops all automatic syncs and keeps the settings; activating it again resumes them. Deleting the plugin removes its settings and history; TablePress tables keep their current data.

WP-CLI:

`wp tablerefresh sync <table_id> <url> [--delimiter=<comma|semicolon|tab|pipe>] [--strict-columns] [--keep-formulas] [--keep-shortcodes] [--on-conflict=<abort|overwrite>] [--format=json]`

`wp tablerefresh recover <table_id>`

Google Sheets: share the sheet with "Anyone with the link can view", or use File > Share > Publish to web. Copy the URL from the browser; it is converted to the CSV export URL automatically.

How synchronized content appears in the TablePress editor:

* A cell starting with `=` is stored with TablePress's text prefix, `'=1+1`, and shown as `=1+1` on the page, not calculated.
* A `[` that could start a shortcode is stored as `&#091;`, for example `&#091;gallery]`, and shown as `[gallery]` on the page, not executed. Other brackets, such as `[ ]` or `list[]`, are kept as they are.

== Changelog ==

= 0.2.0 =
* Tools > TableRefresh admin screen: set up, Sync now, history, conflict decisions.
* Automatic sync with WP-Cron (hourly, daily, weekly).
* A sync whose connection was lost at the commit is no longer reported as updated.

= 0.1.0 =
* Prototype: WP-CLI synchronization, conflict detection, revision retention.
