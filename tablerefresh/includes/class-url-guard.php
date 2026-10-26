<?php
/**
 * SSRF protection for source URLs.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a URL and resolves it to IP addresses that are safe to connect to.
 *
 * WordPress's wp_http_validate_url() is used as well (via wp_safe_remote_get()), but it
 * only checks a single IPv4 address, rejects IPv6 literals outright, and resolves DNS
 * separately from the actual connection. This class checks every A and AAAA record,
 * and Http_Fetcher pins the connection to the address that was checked here.
 */
final class URL_Guard {

	/**
	 * Address ranges that must never be fetched (CIDR notation).
	 *
	 * @var string[]
	 */
	const BLOCKED_RANGES = array(
		// IPv4.
		'0.0.0.0/8',          // "This" network.
		'10.0.0.0/8',         // Private.
		'100.64.0.0/10',      // Carrier-grade NAT.
		'127.0.0.0/8',        // Loopback.
		'169.254.0.0/16',     // Link-local, cloud metadata (169.254.169.254).
		'172.16.0.0/12',      // Private.
		'192.0.0.0/24',       // IETF protocol assignments (incl. 192.0.0.192 Oracle metadata).
		'192.0.2.0/24',       // TEST-NET-1.
		'192.88.99.0/24',     // 6to4 relay anycast.
		'192.168.0.0/16',     // Private.
		'198.18.0.0/15',      // Benchmarking.
		'198.51.100.0/24',    // TEST-NET-2.
		'203.0.113.0/24',     // TEST-NET-3.
		'224.0.0.0/4',        // Multicast.
		'240.0.0.0/4',        // Reserved, broadcast.
		// IPv6.
		'::/128',             // Unspecified.
		'::1/128',            // Loopback.
		'::/96',              // IPv4-compatible (deprecated).
		'64:ff9b:1::/48',     // Local-use NAT64.
		'100::/64',           // Discard.
		'2001::/23',          // IETF protocol assignments (incl. Teredo 2001::/32).
		'2001:db8::/32',      // Documentation.
		'fc00::/7',           // Unique local.
		'fe80::/10',          // Link-local.
		'fec0::/10',          // Site-local (deprecated).
		'ff00::/8',           // Multicast.
	);

	/**
	 * Validates a URL and resolves its host.
	 *
	 * @param string $url URL.
	 * @return array{url: string, scheme: string, host: string, port: int, ip: string, private: bool}|WP_Error
	 */
	public static function check( string $url ) {
		if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return self::error( 'tablerefresh_url_invalid', __( 'The URL is empty, too long, or contains whitespace or control characters.', 'tablerefresh' ) );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return self::error( 'tablerefresh_url_invalid', __( 'The URL could not be parsed.', 'tablerefresh' ) );
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return self::error( 'tablerefresh_url_scheme', __( 'Only http:// and https:// URLs are allowed.', 'tablerefresh' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return self::error( 'tablerefresh_url_credentials', __( 'URLs with embedded user names or passwords are not allowed.', 'tablerefresh' ) );
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		/**
		 * Filters the TCP ports that source URLs may use.
		 *
		 * @param int[]  $ports Allowed ports. Default 80 and 443.
		 * @param string $url   URL.
		 */
		$allowed_ports = array_map( 'intval', (array) apply_filters( 'tablerefresh_allowed_ports', array( 80, 443 ), $url ) );
		if ( ! in_array( $port, $allowed_ports, true ) ) {
			return self::error( 'tablerefresh_url_port', __( 'The URL uses a port that is not allowed.', 'tablerefresh' ) );
		}

		$host = self::normalize_host( $parts['host'] );
		if ( '' === $host ) {
			return self::error( 'tablerefresh_url_host', __( 'The URL host name is not allowed.', 'tablerefresh' ) );
		}

		$ips = self::resolve( $host );
		if ( empty( $ips ) ) {
			return self::error( 'tablerefresh_url_dns', __( 'The host name could not be resolved.', 'tablerefresh' ) );
		}

		$private = false;
		foreach ( $ips as $ip ) {
			if ( self::is_blocked_ip( $ip ) ) {
				/**
				 * Filters whether a host that resolves to a private or reserved address may be fetched.
				 *
				 * Off by default to prevent server-side request forgery. Only enable this for
				 * a specific, trusted intranet host.
				 *
				 * @param bool   $allowed Whether to allow the host. Default false.
				 * @param string $host    Host name.
				 * @param string $ip      Blocked IP address the host resolved to.
				 */
				if ( ! apply_filters( 'tablerefresh_allow_private_host', false, $host, $ip ) ) {
					return self::error( 'tablerefresh_url_blocked', __( 'The URL points to a private, local, or reserved network address.', 'tablerefresh' ) );
				}
				$private = true;
			}
		}

		// Rebuild the URL from the normalized host, so that cURL's CURLOPT_RESOLVE entry
		// (which matches on the host string) is guaranteed to apply. Fragments are dropped.
		$rebuilt = $scheme . '://' . ( false !== strpos( $host, ':' ) ? '[' . $host . ']' : $host );
		if ( isset( $parts['port'] ) ) {
			$rebuilt .= ':' . $port;
		}
		$rebuilt .= isset( $parts['path'] ) ? $parts['path'] : '/';
		if ( isset( $parts['query'] ) ) {
			$rebuilt .= '?' . $parts['query'];
		}

		return array(
			'url'     => $rebuilt,
			'scheme'  => $scheme,
			'host'    => $host,
			'port'    => $port,
			'ip'      => $ips[0],
			'private' => $private,
		);
	}

	/**
	 * Lower-cases the host and rejects ambiguous forms.
	 *
	 * Hosts such as "2130706433", "0x7f.1", "0177.0.0.1" or "127.1" are interpreted
	 * as IPv4 addresses by some resolvers and by cURL, so only canonical dotted quads
	 * are accepted as IPv4 literals.
	 *
	 * @param string $host Raw host from wp_parse_url().
	 * @return string Normalized host, or '' if not allowed.
	 */
	public static function normalize_host( string $host ): string {
		$host = strtolower( rtrim( $host, '.' ) );

		if ( '[' === substr( $host, 0, 1 ) && ']' === substr( $host, -1 ) ) {
			$ip = substr( $host, 1, -1 );
			return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? $ip : '';
		}

		if ( ! preg_match( '/^[a-z0-9_-]+(\.[a-z0-9_-]+)*$/', $host ) || strlen( $host ) > 253 ) {
			return '';
		}

		$labels = explode( '.', $host );
		$last   = end( $labels );
		if ( preg_match( '/^(0x[0-9a-f]*|[0-9]+)$/', $last ) ) {
			$octet = '(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])';
			if ( ! preg_match( "/^{$octet}(\\.{$octet}){3}$/", $host ) ) {
				return '';
			}
		}

		return $host;
	}

	/**
	 * Resolves a host to all of its IPv4 and IPv6 addresses (IPv4 first).
	 *
	 * @param string $host Normalized host.
	 * @return string[]
	 */
	public static function resolve( string $host ): array {
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$ips  = array();
		$ipv4 = gethostbynamel( $host );
		if ( is_array( $ipv4 ) ) {
			$ips = $ipv4;
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Warns on lookup failure.
			foreach ( is_array( $records ) ? $records : array() as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		return array_values( array_unique( $ips ) );
	}

	/**
	 * Whether an IP address is private, local, reserved, or otherwise unsafe.
	 *
	 * IPv6 addresses that embed an IPv4 address (IPv4-mapped, NAT64, 6to4) are judged
	 * by the embedded address.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_blocked_ip( string $ip ): bool {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Warns on invalid input.
		if ( false === $packed ) {
			return true;
		}

		if ( 16 === strlen( $packed ) ) {
			$embedded = null;
			if ( self::in_range( $packed, '::ffff:0:0/96' ) || self::in_range( $packed, '64:ff9b::/96' ) ) {
				$embedded = substr( $packed, 12, 4 );
			} elseif ( self::in_range( $packed, '2002::/16' ) ) {
				$embedded = substr( $packed, 2, 4 );
			}
			if ( null !== $embedded ) {
				return self::is_blocked_ip( (string) inet_ntop( $embedded ) );
			}
		}

		foreach ( self::BLOCKED_RANGES as $range ) {
			if ( self::in_range( $packed, $range ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a packed address lies within a CIDR range of the same family.
	 *
	 * @param string $packed Packed address from inet_pton().
	 * @param string $cidr   Range in CIDR notation.
	 * @return bool
	 */
	private static function in_range( string $packed, string $cidr ): bool {
		list( $network, $bits ) = explode( '/', $cidr );
		$network                = inet_pton( $network );
		if ( strlen( $network ) !== strlen( $packed ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( $bytes > 0 && 0 !== substr_compare( $packed, $network, 0, $bytes ) ) {
			return false;
		}
		$remainder = $bits % 8;
		if ( 0 === $remainder ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $remainder ) ) & 0xff;
		return ( ord( $packed[ $bytes ] ) & $mask ) === ( ord( $network[ $bytes ] ) & $mask );
	}

	/**
	 * Builds an error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function error( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message );
	}
}
