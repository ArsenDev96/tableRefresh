=== TableRefresh – Auto Sync for TablePress ===
Contributors: tablerefresh
Tags: tablepress, csv, google sheets, sync, import
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps existing TablePress tables in sync with CSV URLs and public Google Sheets. Free, no external services.

== Description ==

**Prototype – not for production use.** Only a WP-CLI command is available; there is no admin screen or schedule yet.

TableRefresh replaces the data of an existing TablePress table with the contents of a CSV file at a URL, or of a public Google Sheet. The table ID, shortcode, name, description, and all table options are kept.

* The complete CSV is downloaded and validated before anything is written.
* Updates are atomic on supported databases (see below); failed updates are rolled back, interrupted updates are recovered on the next sync.
* Only one sync per table runs at a time.
* Manual edits are never overwritten silently: a sync stops if the table was edited after the last sync or is open in the TablePress editor, and the TablePress editor refuses to save an outdated copy over newer synchronized data.
* Downloads are protected against server-side request forgery (private/reserved addresses, DNS rebinding, redirects), oversized and compressed responses, interrupted downloads, and timeouts.
* Cell content from the source is filtered with KSES. Formulas and shortcodes from the source are disabled by default and shown as text.
* At most 5 revisions created by synchronization are kept per table (filter `tablerefresh_revisions_to_keep`); manually created revisions are never deleted.
* No telemetry, no external services besides the source URL you configure.

== Requirements and guarantees ==

* WordPress 6.7 or newer, PHP 7.4 or newer with the cURL extension.
* The free TablePress plugin, version 3.0 or newer.
* **Atomic updates require InnoDB** for the posts, postmeta, and options tables, on MySQL or MariaDB. On other storage engines (for example MyISAM) or when the engine cannot be determined, synchronization is refused. The filter `tablerefresh_allow_non_atomic_updates` allows best-effort updates there: failures are still undone from a backup, but a crashed process can leave a partially written table, visible to visitors, until the next sync or `wp tablerefresh recover` restores it. Results report `"atomic": false` in that case.
* If the database interrupts a write (for example a deadlock with another write, or a lost connection), TableRefresh blocks all further writes of that sync, undoes the change, and retries up to three times. Database drop-ins that spread queries over several connections (for example HyperDB or LudicrousDB) are not tested; a change of the connection during a write is treated as an interruption, so such setups may fail to sync, but they never keep a partial write.
* SQLite (for example WordPress Playground) is not supported.
* Downloads through a WordPress HTTP proxy (`WP_PROXY_HOST`) are refused, because the connection cannot be protected against requests to internal networks. Enable them with the filter `tablerefresh_allow_proxy` only if the proxy itself blocks internal networks. Proxy environment variables are ignored.

== Usage ==

`wp tablerefresh sync <table_id> <url> [--delimiter=<comma|semicolon|tab|pipe>] [--strict-columns] [--keep-formulas] [--keep-shortcodes] [--on-conflict=<abort|overwrite>] [--format=json]`

`wp tablerefresh recover <table_id>`

Google Sheets: share the sheet with "Anyone with the link can view", or use File > Share > Publish to web. Copy the URL from the browser; it is converted to the CSV export URL automatically.

How synchronized content appears in the TablePress editor:

* A cell starting with `=` is stored with TablePress's text prefix, `'=1+1`, and shown as `=1+1` on the page, not calculated.
* A `[` that could start a shortcode is stored as `&#091;`, for example `&#091;gallery]`, and shown as `[gallery]` on the page, not executed. Other brackets, such as `[ ]` or `list[]`, are kept as they are.

== Changelog ==

= 0.1.0 =
* Prototype: WP-CLI synchronization, conflict detection, revision retention.
