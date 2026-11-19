<?php
/**
 * Plugin bootstrap.
 *
 * @package TableRefresh
 */

namespace ArsenManukyan\TableRefresh;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin {

	/**
	 * Registers hooks. Runs when the plugin file is loaded.
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_dependency_notice' ) );
		Editor_Guard::init();
		Revision_Policy::init();
		Scheduler::init();

		if ( is_admin() ) {
			require_once TABLEREFRESH_DIR . 'includes/class-admin.php';
			Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once TABLEREFRESH_DIR . 'includes/class-cli-command.php';
			\WP_CLI::add_command( 'tablerefresh', CLI_Command::class );
		}
	}

	/**
	 * Activation: restores the scheduled syncs of tables that are still configured.
	 */
	public static function activate(): void {
		Scheduler::reconcile();
	}

	/**
	 * Deactivation: removes all scheduled syncs. Configurations and history are kept.
	 */
	public static function deactivate(): void {
		Scheduler::clear_all();
	}

	/**
	 * Shows an admin notice on the Plugins screen if TablePress is missing or too old.
	 *
	 * The "Requires Plugins" header already prevents activation without TablePress,
	 * but TablePress can still be deactivated, removed, or downgraded afterwards. Like
	 * WordPress's own notice about unmet plugin dependencies, it appears only where the
	 * problem can be fixed, and disappears once TablePress is active again. The TableRefresh
	 * screen shows the same message in place.
	 */
	public static function maybe_show_dependency_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'plugins' !== $screen->id || ! current_user_can( 'activate_plugins' ) || TablePress_Adapter::is_available() ) {
			return;
		}
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( self::dependency_message() ) );
	}

	/**
	 * Explains that TableRefresh needs TablePress.
	 *
	 * @return string
	 */
	public static function dependency_message(): string {
		return sprintf(
			/* translators: %s: minimum TablePress version. */
			__( 'TableRefresh – Auto Sync for TablePress requires the free TablePress plugin, version %s or newer, to be installed and active.', 'tablerefresh' ),
			TablePress_Adapter::MIN_VERSION
		);
	}
}
