<?php
/**
 * Google Sheets URL handling.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

defined( 'ABSPATH' ) || exit;

/**
 * Converts the URLs users typically copy from Google Sheets into CSV export URLs.
 *
 * Only public sheets work: "Anyone with the link can view" (export URL), or
 * "File > Share > Publish to web" (pub URL). No Google API or credentials are used.
 */
final class Google_Sheets {

	/**
	 * Returns the CSV export URL for a Google Sheets URL, or the URL unchanged.
	 *
	 * Examples:
	 * - https://docs.google.com/spreadsheets/d/{ID}/edit?usp=sharing#gid=123
	 *   → https://docs.google.com/spreadsheets/d/{ID}/export?format=csv&gid=123
	 * - https://docs.google.com/spreadsheets/d/e/{PUB_ID}/pubhtml?gid=123&single=true
	 *   → https://docs.google.com/spreadsheets/d/e/{PUB_ID}/pub?output=csv&gid=123&single=true
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize_url( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || 'docs.google.com' !== strtolower( $parts['host'] ) ) {
			return $url;
		}

		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		$query = array();
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}

		// The sheet (tab) ID can be in the query string or in the fragment ("#gid=123").
		$gid = isset( $query['gid'] ) ? (string) $query['gid'] : '';
		if ( '' === $gid && isset( $parts['fragment'] ) && preg_match( '/(?:^|&)gid=(\d+)/', $parts['fragment'], $match ) ) {
			$gid = $match[1];
		}
		$gid = preg_match( '/^\d+$/', $gid ) ? $gid : '';

		// Published to the web.
		if ( preg_match( '#^/spreadsheets(?:/u/\d+)?/d/e/([A-Za-z0-9_-]+)/(?:pubhtml|pub)(?:/.*)?$#', $path, $match ) ) {
			$args = array( 'output' => 'csv' );
			if ( '' !== $gid ) {
				$args['gid']    = $gid;
				$args['single'] = 'true';
			}
			return 'https://docs.google.com/spreadsheets/d/e/' . $match[1] . '/pub?' . http_build_query( $args );
		}

		// Shared with "Anyone with the link".
		if ( preg_match( '#^/spreadsheets(?:/u/\d+)?/d/([A-Za-z0-9_-]+)(?:/(?:edit|view|htmlview|preview|export|copy))?/?$#', $path, $match ) ) {
			$args = array( 'format' => 'csv' );
			if ( '' !== $gid ) {
				$args['gid'] = $gid;
			}
			return 'https://docs.google.com/spreadsheets/d/' . $match[1] . '/export?' . http_build_query( $args );
		}

		return $url; // E.g. gviz/tq?tqx=out:csv URLs already return CSV.
	}
}
