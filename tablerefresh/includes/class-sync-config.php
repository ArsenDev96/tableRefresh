<?php
/**
 * Which source a table is synchronized with, and how often.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The automatic-sync configuration of a table, stored as hidden post meta on its TablePress post.
 *
 * Keyed by post ID, so a configuration survives a change of the table ID in the TablePress editor
 * and is deleted together with the table. A copy of a table (made in TablePress) has none.
 */
final class Sync_Config {

	const META_KEY = '_tablerefresh_source';

	/**
	 * Supported frequencies: WordPress's own cron schedules.
	 */
	const FREQUENCIES = array( 'hourly', 'daily', 'weekly' );

	/**
	 * Reasons why automatic sync stopped until an administrator acts.
	 */
	const ATTENTION_CONFLICT = 'conflict';
	const ATTENTION_FAILURES = 'failures';

	/**
	 * Reads the configuration of a table post.
	 *
	 * @param int $post_id Table post ID.
	 * @return array{url: string, frequency: string, enabled: bool, attention: string, failures: int, created: int}|null
	 */
	public static function get( int $post_id ): ?array {
		$value = json_decode( (string) get_post_meta( $post_id, self::META_KEY, true ), true );
		if ( ! is_array( $value ) || empty( $value['url'] ) ) {
			return null;
		}
		return self::normalize( $value );
	}

	/**
	 * Stores the configuration of a table post.
	 *
	 * @param int                  $post_id Table post ID.
	 * @param array<string, mixed> $config  Configuration (see get()).
	 */
	public static function save( int $post_id, array $config ): void {
		update_post_meta( $post_id, self::META_KEY, wp_slash( (string) wp_json_encode( self::normalize( $config ) ) ) );
	}

	/**
	 * Removes the configuration and history of a table post. The table itself is not touched.
	 *
	 * @param int $post_id Table post ID.
	 */
	public static function delete( int $post_id ): void {
		delete_post_meta( $post_id, self::META_KEY );
		Sync_Log::delete( $post_id );
	}

	/**
	 * All configurations.
	 *
	 * @return array<int, array<string, mixed>> Post ID => configuration.
	 */
	public static function all(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed query instead of a meta query per table.
		$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id", self::META_KEY ) );
		$configs  = array();
		foreach ( $post_ids as $post_id ) {
			$config = self::get( (int) $post_id );
			if ( null !== $config ) {
				$configs[ (int) $post_id ] = $config;
			}
		}
		return $configs;
	}

	/**
	 * Checks a source URL entered by a user. The address itself is checked on every download.
	 *
	 * @param string $url URL.
	 * @return string|WP_Error The trimmed URL.
	 */
	public static function validate_url( string $url ) {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		if ( '' === $url ) {
			return new WP_Error( 'tablerefresh_url_missing', __( 'Enter the address of a CSV file or a Google Sheets link.', 'tablerefresh' ) );
		}
		if ( strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $url ) || ! is_array( $parts ) || empty( $parts['host'] )
			|| ! in_array( strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'tablerefresh_url_invalid', __( 'Enter a complete web address that starts with https:// (or http://).', 'tablerefresh' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'tablerefresh_url_credentials', __( 'Addresses with a user name or password are not supported. Use a public link.', 'tablerefresh' ) );
		}
		return $url;
	}

	/**
	 * Whether a URL points to Google Sheets.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_google_sheet( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && 'docs.google.com' === strtolower( $host );
	}

	/**
	 * Short description of a source for lists: "Google Sheets" or the host name.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function source_label( string $url ): string {
		if ( self::is_google_sheet( $url ) ) {
			return __( 'Google Sheets', 'tablerefresh' );
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) ? $host : '';
	}

	/**
	 * Human-readable frequency.
	 *
	 * @param string $frequency One of FREQUENCIES.
	 * @return string
	 */
	public static function frequency_label( string $frequency ): string {
		$labels = array(
			'hourly' => __( 'Every hour', 'tablerefresh' ),
			'daily'  => __( 'Once a day', 'tablerefresh' ),
			'weekly' => __( 'Once a week', 'tablerefresh' ),
		);
		return isset( $labels[ $frequency ] ) ? $labels[ $frequency ] : $frequency;
	}

	/**
	 * Fills in defaults and drops unknown values.
	 *
	 * @param array<string, mixed> $config Configuration.
	 * @return array{url: string, frequency: string, enabled: bool, attention: string, failures: int, created: int}
	 */
	private static function normalize( array $config ): array {
		$frequency = isset( $config['frequency'] ) ? (string) $config['frequency'] : '';
		$attention = isset( $config['attention'] ) ? (string) $config['attention'] : '';
		return array(
			'url'       => isset( $config['url'] ) ? (string) $config['url'] : '',
			'frequency' => in_array( $frequency, self::FREQUENCIES, true ) ? $frequency : 'daily',
			'enabled'   => ! empty( $config['enabled'] ),
			'attention' => in_array( $attention, array( self::ATTENTION_CONFLICT, self::ATTENTION_FAILURES ), true ) ? $attention : '',
			'failures'  => isset( $config['failures'] ) ? max( 0, (int) $config['failures'] ) : 0,
			'created'   => isset( $config['created'] ) ? (int) $config['created'] : time(),
		);
	}
}
