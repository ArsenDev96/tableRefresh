<?php
/**
 * Plugin Name:       TableRefresh – Auto Sync for TablePress
 * Description:       Synchronizes existing TablePress tables with CSV URLs and public Google Sheets. Phase 1 prototype: WP-CLI only.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Requires Plugins:  tablepress
 * Author:            TableRefresh contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tablerefresh
 *
 * @package TableRefresh
 */

defined( 'ABSPATH' ) || exit;

define( 'TABLEREFRESH_VERSION', '0.1.0' );
define( 'TABLEREFRESH_FILE', __FILE__ );
define( 'TABLEREFRESH_DIR', __DIR__ . '/' );

require_once TABLEREFRESH_DIR . 'includes/class-tablepress-adapter.php';
require_once TABLEREFRESH_DIR . 'includes/class-sync-state.php';
require_once TABLEREFRESH_DIR . 'includes/class-editor-guard.php';
require_once TABLEREFRESH_DIR . 'includes/class-revision-policy.php';
require_once TABLEREFRESH_DIR . 'includes/class-url-guard.php';
require_once TABLEREFRESH_DIR . 'includes/class-http-fetcher.php';
require_once TABLEREFRESH_DIR . 'includes/class-google-sheets.php';
require_once TABLEREFRESH_DIR . 'includes/class-csv-parser.php';
require_once TABLEREFRESH_DIR . 'includes/class-table-lock.php';
require_once TABLEREFRESH_DIR . 'includes/class-journal.php';
require_once TABLEREFRESH_DIR . 'includes/class-table-updater.php';
require_once TABLEREFRESH_DIR . 'includes/class-sync-service.php';
require_once TABLEREFRESH_DIR . 'includes/class-sync-config.php';
require_once TABLEREFRESH_DIR . 'includes/class-sync-log.php';
require_once TABLEREFRESH_DIR . 'includes/class-sync-runner.php';
require_once TABLEREFRESH_DIR . 'includes/class-scheduler.php';
require_once TABLEREFRESH_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'TableRefresh\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TableRefresh\Plugin', 'deactivate' ) );

TableRefresh\Plugin::init();
