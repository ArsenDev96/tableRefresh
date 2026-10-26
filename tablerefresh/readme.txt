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

**Phase 1 prototype – not for production use.** Only a WP-CLI command is available; there is no admin screen or schedule yet.

TableRefresh replaces the data of an existing TablePress table with the contents of a CSV file at a URL, or of a public Google Sheet. The table ID, shortcode, name, description, and all table options are kept.

* The complete CSV is downloaded and validated before anything is written.
* Failed updates are rolled back; interrupted updates are recovered on the next sync.
* Only one sync per table runs at a time.
* Downloads are protected against server-side request forgery (private/reserved addresses, DNS rebinding, redirects), oversized responses, and timeouts.
* Cell content from the source is filtered with KSES. Formulas (`=...`) and shortcodes (`[...]`) from the source are disabled by default.
* No telemetry, no external services besides the source URL you configure.

Requires the free TablePress plugin (3.0 or newer) and the PHP cURL extension.

== Usage ==

`wp tablerefresh sync <table_id> <url> [--delimiter=<comma|semicolon|tab|pipe>] [--strict-columns] [--keep-formulas] [--keep-shortcodes] [--format=json]`

`wp tablerefresh recover <table_id>`

Google Sheets: share the sheet with "Anyone with the link can view", or use File > Share > Publish to web. Copy the URL from the browser; it is converted to the CSV export URL automatically.

== Changelog ==

= 0.1.0 =
* Phase 1 technical prototype.
