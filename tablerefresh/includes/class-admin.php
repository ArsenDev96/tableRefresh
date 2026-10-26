<?php
/**
 * Administration screen.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

defined( 'ABSPATH' ) || exit;

/**
 * Tools > TableRefresh: set up, review, and run the automatic sync of TablePress tables.
 *
 * Every change is a POST to admin-post.php with a nonce for the action and table, and requires
 * both the TableRefresh capability (default `manage_options`: a source makes the server download
 * a URL on a schedule) and TablePress's `tablepress_edit_table` for the table. Results are shown
 * once, through a short-lived notice stored per user, so that no message or URL travels in links.
 */
final class Admin {

	const PAGE = 'tablerefresh';

	/**
	 * Screen hook suffix of the page.
	 *
	 * @var string
	 */
	private static string $hook = '';

	/**
	 * Registers the hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_notices', array( __CLASS__, 'attention_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TABLEREFRESH_FILE ), array( __CLASS__, 'action_links' ) );
		foreach ( array( 'save', 'sync', 'replace', 'keep', 'remove' ) as $action ) {
			add_action( 'admin_post_tablerefresh_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * Capability needed to use TableRefresh, in addition to `tablepress_edit_table` per table.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to set up and run automatic sync.
		 *
		 * @param string $capability Default `manage_options`.
		 */
		return (string) apply_filters( 'tablerefresh_capability', 'manage_options' );
	}

	/**
	 * Whether the current user may manage the sync of a table.
	 *
	 * @param string $table_id Table ID.
	 * @return bool
	 */
	public static function can_manage( string $table_id ): bool {
		return '' !== $table_id && current_user_can( self::capability() ) && current_user_can( 'tablepress_edit_table', $table_id );
	}

	/**
	 * URL of the page.
	 *
	 * @param array<string, string|int> $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * URL of the settings and history of a table.
	 *
	 * @param int $post_id Table post ID.
	 * @return string
	 */
	private static function edit_url( int $post_id ): string {
		return self::url(
			array(
				'action' => 'edit',
				'table'  => $post_id,
			)
		);
	}

	/**
	 * Adds Tools > TableRefresh.
	 */
	public static function add_page(): void {
		$hook = add_management_page(
			__( 'TableRefresh – automatic sync for TablePress tables', 'tablerefresh' ),
			__( 'TableRefresh', 'tablerefresh' ),
			self::capability(),
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);

		self::$hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Adds "Set up" to the plugin's row on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		if ( current_user_can( self::capability() ) ) {
			array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Set up', 'tablerefresh' ) . '</a>' );
		}
		return $links;
	}

	/**
	 * Loads the screen's styles and the "Syncing…" button state.
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( '' === self::$hook || self::$hook !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'tablerefresh-admin', plugins_url( 'assets/admin.css', TABLEREFRESH_FILE ), array(), TABLEREFRESH_VERSION );
		wp_enqueue_script( 'tablerefresh-admin', plugins_url( 'assets/admin.js', TABLEREFRESH_FILE ), array(), TABLEREFRESH_VERSION, true );
		wp_localize_script( 'tablerefresh-admin', 'tablerefreshAdmin', array( 'syncing' => __( 'Syncing…', 'tablerefresh' ) ) );
	}

	/**
	 * Tells administrators on the Dashboard and TablePress screens that a table needs a decision.
	 */
	public static function attention_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! current_user_can( self::capability() ) || ( 'dashboard' !== $screen->id && false === strpos( $screen->id, 'tablepress' ) ) ) {
			return;
		}
		$count = count( self::attention_tables() );
		if ( 0 === $count ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of tables. */
					_n( 'TableRefresh: automatic sync is paused for %d table and needs your decision.', 'TableRefresh: automatic sync is paused for %d tables and needs your decision.', $count, 'tablerefresh' ),
					$count
				)
			),
			esc_url( self::url() ),
			esc_html__( 'Review', 'tablerefresh' )
		);
	}

	/**
	 * Configured tables that the current user manages and that need a decision.
	 *
	 * @return int[] Post IDs.
	 */
	private static function attention_tables(): array {
		if ( ! TablePress_Adapter::is_available() ) {
			return array();
		}
		$post_ids = array();
		foreach ( Sync_Config::all() as $post_id => $config ) {
			if ( '' !== $config['attention'] && self::can_manage( TablePress_Adapter::get_table_id( $post_id ) ) ) {
				$post_ids[] = $post_id;
			}
		}
		return $post_ids;
	}

	/*
	 * ---------------------------------------------------------------------------------------
	 * Screens.
	 * ---------------------------------------------------------------------------------------
	 */

	/**
	 * Renders the page.
	 */
	public static function render_page(): void {
		echo '<div class="wrap tablerefresh">';
		if ( ! TablePress_Adapter::is_available() ) {
			echo '<h1>' . esc_html__( 'TableRefresh', 'tablerefresh' ) . '</h1>';
			echo '<div class="notice notice-error inline"><p>' . esc_html( TablePress_Adapter::availability_error()->get_error_message() ) . '</p></div></div>';
			return;
		}
		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$post_id = isset( $_GET['table'] ) ? absint( $_GET['table'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		if ( 'add' === $action ) {
			self::render_form( 0 );
		} elseif ( 'edit' === $action && null !== Sync_Config::get( $post_id ) && self::can_manage( TablePress_Adapter::get_table_id( $post_id ) ) ) {
			self::render_form( $post_id );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	/**
	 * The list of configured tables.
	 */
	private static function render_list(): void {
		Scheduler::reconcile(); // Repairs lost or duplicate events (e.g. after an update or a reset cron option).
		$configs = array();
		foreach ( Sync_Config::all() as $post_id => $config ) {
			$table_id = TablePress_Adapter::get_table_id( $post_id );
			if ( self::can_manage( $table_id ) ) {
				$configs[ $post_id ] = $config + array( 'table_id' => $table_id );
			}
		}
		$names = TablePress_Adapter::list_tables();

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'TableRefresh', 'tablerefresh' ) . '</h1>';
		echo ' <a href="' . esc_url( self::url( array( 'action' => 'add' ) ) ) . '" class="page-title-action">' . esc_html__( 'Set up a table', 'tablerefresh' ) . '</a>';
		echo '<hr class="wp-header-end">';
		self::print_notice();
		echo '<p>' . esc_html__( 'Keep TablePress tables up to date with a CSV file or a public Google Sheet. TableRefresh replaces the table data with the data from the source; the table name, description, options, and shortcode stay the same.', 'tablerefresh' ) . '</p>';

		if ( empty( $configs ) ) {
			echo '<div class="tablerefresh-empty">';
			echo '<h2>' . esc_html__( 'Get started', 'tablerefresh' ) . '</h2><ol>';
			echo '<li>' . esc_html__( 'Create the table in TablePress, if it does not exist yet.', 'tablerefresh' ) . '</li>';
			echo '<li>' . esc_html__( 'Copy the link to your CSV file or Google Sheet.', 'tablerefresh' ) . '</li>';
			echo '<li>' . esc_html__( 'Choose "Set up a table", pick the table, paste the link, and choose how often to sync.', 'tablerefresh' ) . '</li>';
			echo '</ol><p><a href="' . esc_url( self::url( array( 'action' => 'add' ) ) ) . '" class="button button-primary">' . esc_html__( 'Set up a table', 'tablerefresh' ) . '</a></p></div>';
		} else {
			echo '<table class="widefat striped tablerefresh-tables"><caption class="screen-reader-text">' . esc_html__( 'Tables with automatic sync', 'tablerefresh' ) . '</caption><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Table', 'tablerefresh' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Source', 'tablerefresh' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Automatic sync', 'tablerefresh' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Last sync', 'tablerefresh' ) . '</th>';
			echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'tablerefresh' ) . '</span></th>';
			echo '</tr></thead><tbody>';
			foreach ( $configs as $post_id => $config ) {
				$name = isset( $names[ $config['table_id'] ] ) && '' !== $names[ $config['table_id'] ] ? $names[ $config['table_id'] ] : __( '(no name)', 'tablerefresh' );
				echo '<tr>';
				echo '<td data-label="' . esc_attr__( 'Table', 'tablerefresh' ) . '"><strong><a href="' . esc_url( self::edit_url( $post_id ) ) . '">' . esc_html( $name ) . '</a></strong>';
				/* translators: %s: table ID. */
				echo '<br><span class="description">' . esc_html( sprintf( __( 'Table ID %s', 'tablerefresh' ), $config['table_id'] ) ) . '</span></td>';
				echo '<td data-label="' . esc_attr__( 'Source', 'tablerefresh' ) . '">' . esc_html( Sync_Config::source_label( $config['url'] ) ) . '</td>';
				echo '<td data-label="' . esc_attr__( 'Automatic sync', 'tablerefresh' ) . '">' . self::schedule_summary( $post_id, $config ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in schedule_summary().
				echo '<td data-label="' . esc_attr__( 'Last sync', 'tablerefresh' ) . '">' . self::last_sync_summary( $post_id ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in last_sync_summary().
				echo '<td class="tablerefresh-actions">';
				if ( Sync_Config::ATTENTION_CONFLICT === $config['attention'] ) {
					// "Sync now" would stop at the edits again; the decision is on the settings screen.
					echo '<a href="' . esc_url( self::edit_url( $post_id ) ) . '" class="button button-primary">' . esc_html__( 'Review changes', 'tablerefresh' ) . '</a>';
				} else {
					self::sync_button( $post_id, 'list' );
				}
				echo ' <a href="' . esc_url( self::edit_url( $post_id ) ) . '" class="button">' . esc_html__( 'Settings and history', 'tablerefresh' ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		self::cron_note();
	}

	/**
	 * The form to set up (post ID 0) or edit the sync of a table, with history and decisions.
	 *
	 * @param int $post_id Table post ID, or 0 to set up a new table.
	 */
	private static function render_form( int $post_id ): void {
		$config   = $post_id > 0 ? Sync_Config::get( $post_id ) : null;
		$table_id = $post_id > 0 ? TablePress_Adapter::get_table_id( $post_id ) : '';
		$names    = TablePress_Adapter::list_tables();
		$form     = self::take_form_state();
		$values   = array(
			'table_id'  => isset( $form['values']['table_id'] ) ? (string) $form['values']['table_id'] : $table_id,
			'url'       => isset( $form['values']['url'] ) ? (string) $form['values']['url'] : ( $config ? $config['url'] : '' ),
			'frequency' => isset( $form['values']['frequency'] ) ? (string) $form['values']['frequency'] : ( $config ? $config['frequency'] : 'daily' ),
			'enabled'   => isset( $form['values']['enabled'] ) ? (bool) $form['values']['enabled'] : ( $config ? $config['enabled'] : true ),
		);
		$name     = isset( $names[ $table_id ] ) ? $names[ $table_id ] : '';

		/* translators: %s: table name. */
		echo '<h1>' . esc_html( $post_id > 0 ? sprintf( __( 'Automatic sync: %s', 'tablerefresh' ), '' !== $name ? $name : $table_id ) : __( 'Set up automatic sync', 'tablerefresh' ) ) . '</h1>';
		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'All tables', 'tablerefresh' ) . '</a></p>';
		self::print_notice();
		if ( ! empty( $form['error'] ) ) {
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html( (string) $form['error'] ) . '</p></div>';
		}
		if ( $config && '' !== $config['attention'] ) {
			self::render_attention( $post_id, $config );
		}

		$choices = array();
		if ( 0 === $post_id ) {
			$configured = Sync_Config::all();
			foreach ( $names as $id => $table_name ) {
				$id = (string) $id;
				if ( ! isset( $configured[ TablePress_Adapter::get_post_id( $id ) ] ) && self::can_manage( $id ) ) {
					$choices[ $id ] = $table_name;
				}
			}
			if ( empty( $choices ) ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html( empty( $names ) ? __( 'There are no TablePress tables yet. Create a table in TablePress first; TableRefresh fills existing tables.', 'tablerefresh' ) : __( 'All your TablePress tables are already set up for automatic sync. To sync another table, create it in TablePress first.', 'tablerefresh' ) ) . '</p>';
				echo '<p><a class="button" href="' . esc_url( TablePress_Adapter::admin_url( array( 'action' => 'add' ) ) ) . '">' . esc_html__( 'Add a table in TablePress', 'tablerefresh' ) . '</a></p></div>';
				return;
			}
		}
		$needs_confirm = 0 === $post_id || null === Sync_State::read( $post_id );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tablerefresh-form">';
		echo '<input type="hidden" name="action" value="tablerefresh_save">';
		echo '<input type="hidden" name="table" value="' . esc_attr( (string) $post_id ) . '">';
		wp_nonce_field( 'tablerefresh_save_' . $post_id );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . ( 0 === $post_id ? '<label for="tablerefresh-table">' . esc_html__( 'Table', 'tablerefresh' ) . '</label>' : esc_html__( 'Table', 'tablerefresh' ) ) . '</th><td>';
		if ( 0 === $post_id ) {
			echo '<select id="tablerefresh-table" name="table_id" required><option value="">' . esc_html__( '— Select a table —', 'tablerefresh' ) . '</option>';
			foreach ( $choices as $id => $table_name ) {
				/* translators: 1: table name, 2: table ID. */
				echo '<option value="' . esc_attr( $id ) . '"' . selected( $values['table_id'], $id, false ) . '>' . esc_html( sprintf( __( '%1$s (ID %2$s)', 'tablerefresh' ), '' !== $table_name ? $table_name : __( '(no name)', 'tablerefresh' ), $id ) ) . '</option>';
			}
			echo '</select>';
		} else {
			/* translators: 1: table name, 2: table ID. */
			echo '<strong>' . esc_html( sprintf( __( '%1$s (ID %2$s)', 'tablerefresh' ), '' !== $name ? $name : __( '(no name)', 'tablerefresh' ), $table_id ) ) . '</strong>';
			$editor_url = TablePress_Adapter::admin_url(
				array(
					'action'   => 'edit',
					'table_id' => $table_id,
				)
			);
			echo ' <a href="' . esc_url( $editor_url ) . '">' . esc_html__( 'Open in TablePress', 'tablerefresh' ) . '</a>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="tablerefresh-url">' . esc_html__( 'Data source', 'tablerefresh' ) . '</label></th><td>';
		echo '<input type="url" id="tablerefresh-url" name="url" class="large-text code" required maxlength="2048" value="' . esc_attr( $values['url'] ) . '" placeholder="https://" aria-describedby="tablerefresh-url-help">';
		echo '<div id="tablerefresh-url-help" class="description"><p>' . esc_html__( 'The link to a CSV file, or a Google Sheets link.', 'tablerefresh' ) . '</p>';
		echo '<p>' . esc_html__( 'Google Sheets: click Share, set General access to "Anyone with the link", then click Copy link and paste it here. To use a tab other than the first one, open that tab before copying the link from the address bar.', 'tablerefresh' ) . '</p>';
		echo '<p class="tablerefresh-warning"><strong>' . esc_html__( 'Private Google Sheets are not supported.', 'tablerefresh' ) . '</strong> ' . esc_html__( 'Anyone who has the link can see the data of the sheet.', 'tablerefresh' ) . '</p></div>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'How often', 'tablerefresh' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'How often', 'tablerefresh' ) . '</legend>';
		foreach ( Sync_Config::FREQUENCIES as $frequency ) {
			echo '<label><input type="radio" name="frequency" value="' . esc_attr( $frequency ) . '"' . checked( $values['frequency'], $frequency, false ) . '> ' . esc_html( Sync_Config::frequency_label( $frequency ) ) . '</label><br>';
		}
		echo '<p class="description">' . esc_html__( 'WordPress runs scheduled tasks when someone visits the site. On sites with few visitors, a sync can run later than planned.', 'tablerefresh' ) . '</p>';
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Automatic sync', 'tablerefresh' ) . '</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( $values['enabled'], true, false ) . ' aria-describedby="tablerefresh-enabled-help"> ' . esc_html__( 'Sync automatically', 'tablerefresh' ) . '</label>';
		echo '<p class="description" id="tablerefresh-enabled-help">' . esc_html__( 'Turn this off to keep the settings and sync only when you click "Sync now".', 'tablerefresh' ) . '</p></td></tr>';

		if ( $needs_confirm ) {
			echo '<tr><th scope="row">' . esc_html__( 'Replace table data', 'tablerefresh' ) . '</th><td><label><input type="checkbox" name="confirm_replace" value="1" required> ' . esc_html__( 'Replace the current content of this table with the data from the source.', 'tablerefresh' ) . '</label>';
			echo '<p class="description">' . esc_html__( 'The rows and columns of the table are overwritten. After that, TableRefresh never overwrites changes that someone makes to the table in TablePress without asking you.', 'tablerefresh' ) . '</p></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( 0 === $post_id ? __( 'Save and sync now', 'tablerefresh' ) : __( 'Save changes', 'tablerefresh' ) );
		echo '</form>';

		if ( 0 === $post_id ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Status', 'tablerefresh' ) . '</h2>';
		echo '<p>' . self::schedule_summary( $post_id, (array) $config ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in schedule_summary().
		if ( Sync_Config::ATTENTION_CONFLICT !== $config['attention'] ) {
			echo '<p>';
			self::sync_button( $post_id, 'edit' );
			echo '</p>';
		}
		self::render_history( $post_id );

		echo '<h2>' . esc_html__( 'Stop syncing this table', 'tablerefresh' ) . '</h2>';
		echo '<p>' . esc_html__( 'Removes the sync settings and history. The table and its current data stay as they are.', 'tablerefresh' ) . '</p>';
		self::action_form( 'remove', $post_id, __( 'Remove automatic sync', 'tablerefresh' ), 'button tablerefresh-button-delete' );
	}

	/**
	 * Explains why automatic sync is paused and offers the decisions.
	 *
	 * @param int                  $post_id Table post ID.
	 * @param array<string, mixed> $config  Configuration.
	 */
	private static function render_attention( int $post_id, array $config ): void {
		echo '<div class="notice notice-warning inline tablerefresh-attention">';
		if ( Sync_Config::ATTENTION_CONFLICT === $config['attention'] ) {
			echo '<h2>' . esc_html__( 'This table was changed in TablePress', 'tablerefresh' ) . '</h2>';
			echo '<p>' . esc_html__( 'Someone changed the table data in TablePress after the last sync. To keep those changes, TableRefresh did not update the table and paused automatic sync. Choose what to do:', 'tablerefresh' ) . '</p>';
			echo '<div class="tablerefresh-choices"><div>';
			echo '<h3>' . esc_html__( 'Keep the changes', 'tablerefresh' ) . '</h3><p>' . esc_html__( 'The table stays as it is and automatic sync is turned off.', 'tablerefresh' ) . '</p>';
			self::action_form( 'keep', $post_id, __( 'Keep the changes', 'tablerefresh' ), 'button' );
			echo '</div><div>';
			echo '<h3>' . esc_html__( 'Use the source data', 'tablerefresh' ) . '</h3><p>' . esc_html__( 'The changes made in TablePress are replaced with the current data from the source, and automatic sync continues.', 'tablerefresh' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="tablerefresh_replace"><input type="hidden" name="table" value="' . esc_attr( (string) $post_id ) . '">';
			wp_nonce_field( 'tablerefresh_replace_' . $post_id );
			echo '<p><label><input type="checkbox" name="confirm_replace" value="1" required> ' . esc_html__( 'I understand that the changes made in TablePress will be lost.', 'tablerefresh' ) . '</label></p>';
			echo '<p><button type="submit" class="button">' . esc_html__( 'Replace with source data', 'tablerefresh' ) . '</button></p></form>';
			echo '</div></div>';
		} else {
			echo '<h2>' . esc_html__( 'Automatic sync is paused', 'tablerefresh' ) . '</h2>';
			/* translators: %d: number of attempts. */
			echo '<p>' . esc_html( sprintf( __( 'The last %d syncs failed, so automatic sync was paused. The table was not changed. Check the details below and the data source, then click "Sync now". A successful sync turns automatic sync back on.', 'tablerefresh' ), (int) $config['failures'] ) ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * The recent attempts of a table.
	 *
	 * @param int $post_id Table post ID.
	 */
	private static function render_history( int $post_id ): void {
		$log = Sync_Log::get( $post_id );
		echo '<h2>' . esc_html__( 'Recent syncs', 'tablerefresh' ) . '</h2>';
		if ( $log['last_success'] > 0 ) {
			/* translators: %s: date and time. */
			echo '<p>' . esc_html( sprintf( __( 'Last successful sync: %s', 'tablerefresh' ), self::format_time( $log['last_success'] ) ) ) . '</p>';
		}
		if ( empty( $log['entries'] ) ) {
			echo '<p>' . esc_html__( 'No syncs yet.', 'tablerefresh' ) . '</p>';
			return;
		}
		$triggers = array(
			'manual'    => __( 'Sync now', 'tablerefresh' ),
			'scheduled' => __( 'Automatic', 'tablerefresh' ),
			'setup'     => __( 'Setup', 'tablerefresh' ),
			'replace'   => __( 'Replaced changes', 'tablerefresh' ),
		);
		echo '<table class="widefat striped tablerefresh-history"><caption class="screen-reader-text">' . esc_html__( 'Recent syncs', 'tablerefresh' ) . '</caption><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'When', 'tablerefresh' ) . '</th><th scope="col">' . esc_html__( 'Started by', 'tablerefresh' ) . '</th><th scope="col">' . esc_html__( 'Result', 'tablerefresh' ) . '</th><th scope="col">' . esc_html__( 'Table data changed', 'tablerefresh' ) . '</th><th scope="col">' . esc_html__( 'Details', 'tablerefresh' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $log['entries'] as $entry ) {
			echo '<tr>';
			echo '<td data-label="' . esc_attr__( 'When', 'tablerefresh' ) . '">' . esc_html( self::format_time( (int) $entry['time'] ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Started by', 'tablerefresh' ) . '">' . esc_html( isset( $triggers[ $entry['trigger'] ] ) ? $triggers[ $entry['trigger'] ] : $entry['trigger'] ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Result', 'tablerefresh' ) . '">' . self::outcome_badge( (string) $entry['outcome'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in outcome_badge().
			echo '<td data-label="' . esc_attr__( 'Table data changed', 'tablerefresh' ) . '">' . esc_html( 'updated' === $entry['outcome'] ? __( 'Yes', 'tablerefresh' ) : __( 'No', 'tablerefresh' ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Details', 'tablerefresh' ) . '">' . esc_html( (string) $entry['message'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Schedule column: frequency and next run, off, or paused.
	 *
	 * @param int                  $post_id Table post ID.
	 * @param array<string, mixed> $config  Configuration.
	 * @return string HTML.
	 */
	private static function schedule_summary( int $post_id, array $config ): string {
		if ( '' !== $config['attention'] ) {
			return '<span class="tablerefresh-status tablerefresh-status-warning">' . esc_html__( 'Paused: needs your decision', 'tablerefresh' ) . '</span>';
		}
		if ( ! $config['enabled'] ) {
			return esc_html__( 'Off (only "Sync now")', 'tablerefresh' );
		}
		$html = esc_html( Sync_Config::frequency_label( $config['frequency'] ) );
		$next = Scheduler::next_run( $post_id );
		if ( null !== $next ) {
			$html .= '<br><span class="description">' . esc_html(
				$next > time()
					/* translators: %s: time span, e.g. "5 hours". */
					? sprintf( __( 'Next sync in about %s', 'tablerefresh' ), human_time_diff( time(), $next ) )
					: __( 'Next sync: waiting for WordPress to run scheduled tasks', 'tablerefresh' )
			) . '</span>';
		}
		return $html;
	}

	/**
	 * Last-sync column: outcome, time, and message of the latest attempt.
	 *
	 * @param int $post_id Table post ID.
	 * @return string HTML.
	 */
	private static function last_sync_summary( int $post_id ): string {
		$log  = Sync_Log::get( $post_id );
		$last = isset( $log['entries'][0] ) ? $log['entries'][0] : null;
		if ( null === $last ) {
			return esc_html__( 'Not synced yet', 'tablerefresh' );
		}
		/* translators: %s: time span, e.g. "5 minutes". */
		$html = self::outcome_badge( (string) $last['outcome'] ) . ' <span class="description">' . esc_html( sprintf( __( '%s ago', 'tablerefresh' ), human_time_diff( (int) $last['time'] ) ) ) . '</span>';
		if ( ! in_array( $last['outcome'], array( 'updated', 'unchanged' ), true ) ) {
			$html .= '<br>' . esc_html( (string) $last['message'] );
			if ( $log['last_success'] > 0 ) {
				/* translators: %s: time span, e.g. "5 minutes". */
				$html .= '<br><span class="description">' . esc_html( sprintf( __( 'Last successful sync %s ago', 'tablerefresh' ), human_time_diff( $log['last_success'] ) ) ) . '</span>';
			}
		}
		return $html;
	}

	/**
	 * A status label (text, not only color).
	 *
	 * @param string $outcome Outcome.
	 * @return string HTML.
	 */
	private static function outcome_badge( string $outcome ): string {
		$badges = array(
			'updated'   => array( 'success', __( 'Updated', 'tablerefresh' ) ),
			'unchanged' => array( 'neutral', __( 'No changes', 'tablerefresh' ) ),
			'conflict'  => array( 'warning', __( 'Not updated', 'tablerefresh' ) ),
			'failed'    => array( 'error', __( 'Failed', 'tablerefresh' ) ),
		);
		$badge  = isset( $badges[ $outcome ] ) ? $badges[ $outcome ] : array( 'neutral', $outcome );
		return '<span class="tablerefresh-status tablerefresh-status-' . esc_attr( $badge[0] ) . '">' . esc_html( $badge[1] ) . '</span>';
	}

	/**
	 * A "Sync now" button.
	 *
	 * @param int    $post_id Table post ID.
	 * @param string $back    Screen to return to: "list" or "edit".
	 */
	private static function sync_button( int $post_id, string $back ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tablerefresh-inline-form tablerefresh-sync-form">';
		echo '<input type="hidden" name="action" value="tablerefresh_sync"><input type="hidden" name="table" value="' . esc_attr( (string) $post_id ) . '"><input type="hidden" name="back" value="' . esc_attr( $back ) . '">';
		wp_nonce_field( 'tablerefresh_sync_' . $post_id );
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Sync now', 'tablerefresh' ) . '</button></form>';
	}

	/**
	 * A form with one button for an action on a table.
	 *
	 * @param string $action  Action: "keep" or "remove".
	 * @param int    $post_id Table post ID.
	 * @param string $label   Button label.
	 * @param string $classes Button classes.
	 */
	private static function action_form( string $action, int $post_id, string $label, string $classes ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tablerefresh-inline-form">';
		echo '<input type="hidden" name="action" value="tablerefresh_' . esc_attr( $action ) . '"><input type="hidden" name="table" value="' . esc_attr( (string) $post_id ) . '">';
		wp_nonce_field( 'tablerefresh_' . $action . '_' . $post_id );
		echo '<button type="submit" class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Explains how WordPress runs scheduled tasks.
	 */
	private static function cron_note(): void {
		echo '<p class="description tablerefresh-cron-note">';
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			esc_html_e( 'This site runs WordPress scheduled tasks through a server cron job (DISABLE_WP_CRON). Automatic syncs run when that job calls wp-cron.php.', 'tablerefresh' );
		} else {
			esc_html_e( 'Automatic syncs use WordPress scheduled tasks, which run when someone visits the site. On sites with few visitors, a sync can run later than planned.', 'tablerefresh' );
		}
		echo '</p>';
	}

	/**
	 * Date and time in the site's format and time zone.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private static function format_time( int $time ): string {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/*
	 * ---------------------------------------------------------------------------------------
	 * Form handlers (admin-post.php).
	 * ---------------------------------------------------------------------------------------
	 */

	/**
	 * Saves the settings of a table; the first setup also runs the first sync.
	 */
	public static function handle_save(): void {
		$post_id = isset( $_POST['table'] ) ? absint( $_POST['table'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified next.
		check_admin_referer( 'tablerefresh_save_' . $post_id );

		$is_new   = 0 === $post_id;
		$table_id = $is_new ? ( isset( $_POST['table_id'] ) ? sanitize_text_field( wp_unslash( $_POST['table_id'] ) ) : '' ) : TablePress_Adapter::get_table_id( $post_id );
		$values   = array(
			'table_id'  => $table_id,
			'url'       => isset( $_POST['url'] ) ? trim( (string) wp_unslash( $_POST['url'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by Sync_Config::validate_url(); sanitizers would alter valid URLs (e.g. %20).
			'frequency' => isset( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : '',
			'enabled'   => ! empty( $_POST['enabled'] ),
		);
		$back     = $is_new ? self::url( array( 'action' => 'add' ) ) : self::edit_url( $post_id );

		self::require_tablepress();
		if ( $is_new && ( '' === $table_id || ! TablePress_Adapter::table_exists( $table_id ) ) ) {
			self::redirect_with_error( $back, $values, __( 'Select a table.', 'tablerefresh' ) );
		}
		self::require_permission( $table_id );
		if ( $is_new ) {
			$post_id = TablePress_Adapter::get_post_id( $table_id );
			if ( $post_id <= 0 ) {
				self::redirect_with_error( $back, $values, __( 'The table could not be found in TablePress.', 'tablerefresh' ) );
			}
			if ( null !== Sync_Config::get( $post_id ) ) {
				self::redirect_with_error( $back, $values, __( 'This table is already set up for automatic sync.', 'tablerefresh' ) );
			}
		} elseif ( null === Sync_Config::get( $post_id ) ) {
			self::redirect_with_error( self::url(), array(), __( 'This table is no longer set up for automatic sync.', 'tablerefresh' ) );
		}

		$url = Sync_Config::validate_url( $values['url'] );
		if ( is_wp_error( $url ) ) {
			self::redirect_with_error( $back, $values, $url->get_error_message() );
		}
		if ( ! in_array( $values['frequency'], Sync_Config::FREQUENCIES, true ) ) {
			self::redirect_with_error( $back, $values, __( 'Choose how often the table is synchronized.', 'tablerefresh' ) );
		}
		if ( ( $is_new || null === Sync_State::read( $post_id ) ) && empty( $_POST['confirm_replace'] ) ) {
			self::redirect_with_error( $back, $values, __( 'Confirm that the current content of the table may be replaced with the data from the source.', 'tablerefresh' ) );
		}

		$old    = Sync_Config::get( $post_id );
		$config = array(
			'url'       => $url,
			'frequency' => $values['frequency'],
			'enabled'   => $values['enabled'],
			// A new source gets a fresh start; edits in TablePress still need a decision.
			'attention' => $old && Sync_Config::ATTENTION_CONFLICT === $old['attention'] ? $old['attention'] : ( $old && $old['url'] === $url ? $old['attention'] : '' ),
			'failures'  => $old && $old['url'] === $url ? $old['failures'] : 0,
			'created'   => $old ? $old['created'] : time(),
		);
		Sync_Config::save( $post_id, $config );
		Scheduler::apply( $post_id, Sync_Config::get( $post_id ), null !== $old && $old['frequency'] !== $config['frequency'] );

		if ( $is_new ) {
			$result = ( new Sync_Runner() )->run( $post_id, 'setup' );
			self::set_notice( $result['outcome'], self::result_message( $result, $table_id ) );
			self::redirect( self::url() );
		}
		self::set_notice( 'saved', __( 'Settings saved.', 'tablerefresh' ) );
		self::redirect( $back );
	}

	/**
	 * "Sync now".
	 */
	public static function handle_sync(): void {
		list( $post_id, $table_id ) = self::verify_table_request( 'sync' );
		$result                     = ( new Sync_Runner() )->run( $post_id, 'manual' );
		self::set_notice( $result['outcome'], self::result_message( $result, $table_id ) );
		$back = isset( $_POST['back'] ) && 'edit' === $_POST['back'] ? self::edit_url( $post_id ) : self::url(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_table_request().
		self::redirect( $back );
	}

	/**
	 * Replaces edits made in TablePress with the source data, after explicit confirmation.
	 */
	public static function handle_replace(): void {
		list( $post_id, $table_id ) = self::verify_table_request( 'replace' );
		$back                       = self::edit_url( $post_id );
		if ( empty( $_POST['confirm_replace'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verify_table_request().
			self::set_notice( 'failed', __( 'Nothing was changed. Confirm that the changes made in TablePress may be replaced.', 'tablerefresh' ) );
			self::redirect( $back );
		}
		$result = ( new Sync_Runner() )->run( $post_id, 'replace' );
		self::set_notice( $result['outcome'], self::result_message( $result, $table_id ) );
		self::redirect( $back );
	}

	/**
	 * Keeps edits made in TablePress and turns automatic sync off.
	 */
	public static function handle_keep(): void {
		list( $post_id ) = self::verify_table_request( 'keep' );
		$config          = Sync_Config::get( $post_id );
		if ( null !== $config ) {
			$config['enabled']   = false;
			$config['attention'] = '';
			Sync_Config::save( $post_id, $config );
			Scheduler::apply( $post_id, Sync_Config::get( $post_id ) );
		}
		self::set_notice( 'saved', __( 'The changes made in TablePress are kept, and automatic sync is turned off. "Sync now" will again stop at these changes until you choose to replace them.', 'tablerefresh' ) );
		self::redirect( self::edit_url( $post_id ) );
	}

	/**
	 * Removes the sync settings and history of a table. The table is not changed.
	 */
	public static function handle_remove(): void {
		list( $post_id ) = self::verify_table_request( 'remove' );
		Sync_Config::delete( $post_id );
		Scheduler::apply( $post_id, null );
		self::set_notice( 'saved', __( 'Automatic sync was removed. The table was not changed.', 'tablerefresh' ) );
		self::redirect( self::url() );
	}

	/**
	 * Verifies nonce and permission of a request for a configured table.
	 *
	 * @param string $action Action.
	 * @return array{0: int, 1: string} Post ID and table ID.
	 */
	private static function verify_table_request( string $action ): array {
		$post_id = isset( $_POST['table'] ) ? absint( $_POST['table'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified next.
		check_admin_referer( 'tablerefresh_' . $action . '_' . $post_id );
		self::require_tablepress();
		$table_id = TablePress_Adapter::get_table_id( $post_id );
		self::require_permission( $table_id );
		if ( null === Sync_Config::get( $post_id ) ) {
			self::set_notice( 'failed', __( 'This table is no longer set up for automatic sync.', 'tablerefresh' ) );
			self::redirect( self::url() );
		}
		return array( $post_id, $table_id );
	}

	/**
	 * Stops unless TablePress is available.
	 */
	private static function require_tablepress(): void {
		$error = TablePress_Adapter::availability_error();
		if ( $error ) {
			wp_die( esc_html( $error->get_error_message() ), '', array( 'response' => 503 ) );
		}
	}

	/**
	 * Stops unless the current user may manage the sync of the table.
	 *
	 * @param string $table_id Table ID.
	 */
	private static function require_permission( string $table_id ): void {
		if ( ! self::can_manage( $table_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the automatic sync of this table.', 'tablerefresh' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Notice text for a sync result.
	 *
	 * @param array{outcome: string, message: string} $result   Result from Sync_Runner::run().
	 * @param string                                  $table_id Table ID.
	 * @return string
	 */
	private static function result_message( array $result, string $table_id ): string {
		$names = TablePress_Adapter::list_tables();
		$name  = isset( $names[ $table_id ] ) && '' !== $names[ $table_id ] ? $names[ $table_id ] : $table_id;
		/* translators: 1: table name, 2: result. */
		return sprintf( __( '%1$s: %2$s', 'tablerefresh' ), $name, $result['message'] );
	}

	/**
	 * Stores a one-time notice for the current user.
	 *
	 * @param string $type    Outcome or "saved".
	 * @param string $message Message.
	 */
	private static function set_notice( string $type, string $message ): void {
		set_transient(
			'tablerefresh_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Prints and removes the one-time notice of the current user.
	 */
	private static function print_notice(): void {
		$key    = 'tablerefresh_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$classes = array(
			'updated'   => 'notice-success',
			'unchanged' => 'notice-success',
			'saved'     => 'notice-success',
			'conflict'  => 'notice-warning',
			'busy'      => 'notice-warning',
		);
		$class   = isset( $classes[ $notice['type'] ] ) ? $classes[ $notice['type'] ] : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible tablerefresh-notice" role="status"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
	}

	/**
	 * Remembers the submitted form and an error, and returns to the form.
	 *
	 * @param string               $url    Form URL.
	 * @param array<string, mixed> $values Submitted values.
	 * @param string               $error  Error.
	 */
	private static function redirect_with_error( string $url, array $values, string $error ): void {
		set_transient(
			'tablerefresh_form_' . get_current_user_id(),
			array(
				'values' => $values,
				'error'  => $error,
			),
			5 * MINUTE_IN_SECONDS
		);
		self::redirect( $url );
	}

	/**
	 * Returns and removes the form state stored by redirect_with_error().
	 *
	 * @return array<string, mixed>
	 */
	private static function take_form_state(): array {
		$key   = 'tablerefresh_form_' . get_current_user_id();
		$state = get_transient( $key );
		delete_transient( $key );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Redirects within the admin area and stops.
	 *
	 * @param string $url URL.
	 */
	private static function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
