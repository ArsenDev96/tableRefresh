<?php
/**
 * Hardened HTTP download of CSV sources.
 *
 * @package TableRefresh
 */

namespace TableRefresh;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads a CSV source through the WordPress HTTP API with SSRF protection.
 *
 * - Every hop of a redirect chain is validated by URL_Guard; redirects are followed manually.
 * - The cURL connection is pinned (CURLOPT_RESOLVE) to the address URL_Guard checked, and the
 *   connected address is verified afterwards, which closes the DNS-rebinding window.
 * - Transfers are aborted on the wire once they exceed the size limit. WordPress's own
 *   `limit_response_size` only truncates the kept body and keeps downloading.
 * - One overall time budget covers the whole redirect chain.
 *
 * - Decompressed content is limited too: the transfer is aborted as soon as the decoded body
 *   exceeds the size limit, independent of the libcurl version.
 * - Proxies: with a WordPress HTTP proxy (WP_PROXY_HOST), the proxy resolves host names and
 *   connects, so the address pinning cannot be guaranteed. Such downloads are refused unless
 *   `tablerefresh_allow_proxy` allows them. Proxies from environment variables (http_proxy,
 *   https_proxy), which libcurl would otherwise use silently, are always ignored.
 *
 * Only the cURL transport provides these guarantees, so responses from any other transport
 * (or from a `pre_http_request` short-circuit) are rejected.
 */
final class Http_Fetcher {

	/**
	 * Redirect status codes that are followed.
	 */
	const REDIRECT_CODES = array( 301, 302, 303, 307, 308 );

	/**
	 * Content types that may contain CSV. An empty Content-Type header is accepted too.
	 */
	const ACCEPTED_TYPES = array(
		'text/csv',
		'text/plain',
		'text/comma-separated-values',
		'text/tab-separated-values',
		'application/csv',
		'application/vnd.ms-excel',
		'application/octet-stream',
	);

	/**
	 * The request that is currently in flight, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $target = null;

	/**
	 * Whether configure_curl() ran for the current request.
	 *
	 * @var bool
	 */
	private bool $pinned = false;

	/**
	 * The address cURL actually connected to, if reported.
	 *
	 * @var string
	 */
	private string $connected_ip = '';

	/**
	 * Whether the decoded body of the current request exceeded the size limit.
	 *
	 * @var bool
	 */
	private bool $decoded_too_large = false;

	/**
	 * Downloads a URL.
	 *
	 * @param string               $url  Source URL.
	 * @param array<string, mixed> $args {
	 *     Optional. Limits.
	 *
	 *     @type int $timeout       Total seconds for all requests incl. redirects. Default 15.
	 *     @type int $max_bytes            Maximum (decompressed) response size in bytes. Default 5 MB.
	 *     @type int $max_compressed_bytes Maximum bytes on the wire. Default: max_bytes.
	 *     @type int $max_redirects        Maximum number of redirects. Default 5.
	 * }
	 * @return array{body: string, final_url: string, content_type: string, redirects: int, bytes: int, via_proxy: bool}|WP_Error
	 */
	public function fetch( string $url, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'timeout'       => 15,
				'max_bytes'     => 5 * MB_IN_BYTES,
				'max_redirects' => 5,
			)
		);
		if ( empty( $args['max_compressed_bytes'] ) ) {
			$args['max_compressed_bytes'] = $args['max_bytes'];
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'tablerefresh_http_transport', __( 'The PHP cURL extension is required for secure downloads.', 'tablerefresh' ) );
		}

		$deadline  = microtime( true ) + (float) $args['timeout'];
		$redirects = 0;
		$current   = $url;

		while ( true ) {
			$target = URL_Guard::check( $current );
			if ( is_wp_error( $target ) ) {
				return $target;
			}

			$remaining = $deadline - microtime( true );
			if ( $remaining < 0.5 ) {
				return new WP_Error( 'tablerefresh_http_timeout', __( 'The source did not respond in time.', 'tablerefresh' ) );
			}

			$response = $this->request( $target, $remaining, (int) $args['max_bytes'], (int) $args['max_compressed_bytes'] );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( in_array( $code, self::REDIRECT_CODES, true ) ) {
				$location = self::header( $response, 'location' );
				if ( '' === $location ) {
					return new WP_Error( 'tablerefresh_http_redirect', __( 'The source sent a redirect without a target.', 'tablerefresh' ) );
				}
				if ( ++$redirects > (int) $args['max_redirects'] ) {
					return new WP_Error( 'tablerefresh_http_redirect', __( 'The source redirected too many times.', 'tablerefresh' ) );
				}
				$current = \WP_Http::make_absolute_url( $location, $target['url'] );

				$host = strtolower( (string) wp_parse_url( $current, PHP_URL_HOST ) );
				if ( 'accounts.google.com' === $host ) {
					return new WP_Error( 'tablerefresh_google_sheet_not_public', __( 'Google redirected to a sign-in page. The sheet is not shared publicly ("Anyone with the link") or not published to the web.', 'tablerefresh' ) );
				}
				continue;
			}

			if ( 200 !== $code ) {
				return new WP_Error(
					'tablerefresh_http_status',
					/* translators: %d: HTTP status code. */
					sprintf( __( 'The source responded with HTTP status %d.', 'tablerefresh' ), $code ),
					array( 'status' => $code )
				);
			}

			$body = (string) wp_remote_retrieve_body( $response );
			if ( strlen( $body ) > (int) $args['max_bytes'] ) {
				return self::too_large_error();
			}

			$content_type = strtolower( trim( explode( ';', self::header( $response, 'content-type' ) )[0] ) );
			if ( false !== strpos( $content_type, 'html' ) || false !== strpos( $content_type, 'xml' ) || self::looks_like_html( $body ) ) {
				return new WP_Error( 'tablerefresh_http_html', __( 'The source returned a web page (HTML) instead of CSV data. Check that the URL is a direct CSV export link and that the sheet is public.', 'tablerefresh' ) );
			}
			if ( '' !== $content_type && ! in_array( $content_type, self::ACCEPTED_TYPES, true ) ) {
				return new WP_Error(
					'tablerefresh_http_content_type',
					/* translators: %s: MIME type. */
					sprintf( __( 'The source returned unsupported content (%s) instead of CSV data.', 'tablerefresh' ), $content_type )
				);
			}

			return array(
				'body'         => $body,
				'final_url'    => $target['url'],
				'content_type' => $content_type,
				'redirects'    => $redirects,
				'bytes'        => strlen( $body ),
				'via_proxy'    => ! empty( $response['tablerefresh_via_proxy'] ),
			);
		}
	}

	/**
	 * Performs one pinned request without following redirects.
	 *
	 * @param array<string, mixed> $target         Result of URL_Guard::check().
	 * @param float                $timeout        Seconds.
	 * @param int                  $max_bytes      Decoded size limit.
	 * @param int                  $max_wire_bytes Size limit on the wire.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( array $target, float $timeout, int $max_bytes, int $max_wire_bytes ) {
		$proxy     = new \WP_HTTP_Proxy();
		$via_proxy = $proxy->is_enabled() && $proxy->send_through_proxy( $target['url'] );
		/**
		 * Filters whether sources may be downloaded through the WordPress HTTP proxy.
		 *
		 * Off by default: through a proxy, TableRefresh can still validate the URL and every
		 * redirect, but cannot pin or verify the address that is connected to, so DNS-rebinding
		 * protection depends on the proxy. Enable this only if the proxy itself blocks requests
		 * to private and internal networks.
		 *
		 * @param bool   $allow Default false.
		 * @param string $url   URL.
		 */
		if ( $via_proxy && ! apply_filters( 'tablerefresh_allow_proxy', false, $target['url'] ) ) {
			return new WP_Error( 'tablerefresh_http_proxy_unsupported', __( 'WordPress is configured to use an HTTP proxy for this URL. Downloads through a proxy cannot be protected against requests to internal networks, so the source was not downloaded.', 'tablerefresh' ) );
		}

		$this->target            = $target + array(
			'max_bytes'      => $max_bytes,
			'max_wire_bytes' => $max_wire_bytes,
			'via_proxy'      => $via_proxy,
		);
		$this->pinned            = false;
		$this->connected_ip      = '';
		$this->decoded_too_large = false;

		$host_is_external = static function ( $is_external, $host ) use ( $target ) {
			return $is_external || ( $target['private'] && strtolower( (string) $host ) === $target['host'] );
		};

		add_action( 'http_api_curl', array( $this, 'configure_curl' ), PHP_INT_MAX, 3 );
		add_action( 'requests-curl.after_request', array( $this, 'record_connection' ), 10, 2 );
		add_action( 'requests-request.progress', array( $this, 'track_decoded_size' ), 10, 2 );
		add_filter( 'http_request_host_is_external', $host_is_external, 10, 2 );
		try {
			// wp_safe_remote_get() additionally runs wp_http_validate_url() (defense in depth).
			$response = wp_safe_remote_get(
				$target['url'],
				array(
					'timeout'             => max( 1, $timeout ),
					'redirection'         => 0,
					'limit_response_size' => $max_bytes + 1, // One extra byte to detect oversized bodies.
					'user-agent'          => 'TableRefresh/' . TABLEREFRESH_VERSION . ' (WordPress)',
					'headers'             => array(
						'Accept'          => 'text/csv, text/plain;q=0.9, */*;q=0.1',
						'Accept-Encoding' => 'identity',
					),
					'cookies'             => array(),
					'sslverify'           => true,
				)
			);
		} finally {
			remove_action( 'http_api_curl', array( $this, 'configure_curl' ), PHP_INT_MAX );
			remove_action( 'requests-curl.after_request', array( $this, 'record_connection' ), 10 );
			remove_action( 'requests-request.progress', array( $this, 'track_decoded_size' ), 10 );
			remove_filter( 'http_request_host_is_external', $host_is_external, 10 );
			$pinned       = $this->pinned;
			$connected_ip = $this->connected_ip;
			$this->target = null;
		}

		if ( $this->decoded_too_large ) {
			return self::too_large_error();
		}
		if ( is_wp_error( $response ) ) {
			return self::map_transport_error( $response );
		}

		if ( ! $pinned ) {
			return new WP_Error( 'tablerefresh_http_transport', __( 'The download did not use the cURL transport (or was intercepted by another plugin), so it could not be secured and was discarded.', 'tablerefresh' ) );
		}

		if ( $via_proxy ) {
			$response['tablerefresh_via_proxy'] = true; // Opted in above; the proxy chose the address.
		} elseif ( '' === $connected_ip || @inet_pton( $connected_ip ) !== @inet_pton( $target['ip'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'tablerefresh_url_blocked', __( 'The connection was not made to the verified address and was discarded.', 'tablerefresh' ) );
		}

		return $response;
	}

	/**
	 * Pins the connection to the verified IP and enforces the size limit on the wire.
	 *
	 * @param resource|\CurlHandle $handle      cURL handle.
	 * @param array<string, mixed> $parsed_args Request arguments.
	 * @param string               $url         Request URL.
	 */
	public function configure_curl( $handle, $parsed_args, $url ): void {
		if ( null === $this->target || $url !== $this->target['url'] ) {
			return;
		}

		$ip    = $this->target['ip'];
		$ip    = false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip;
		$limit = (int) $this->target['max_wire_bytes'];
		$self  = $this;

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Hardening the handle the HTTP API created.
		if ( ! $this->target['via_proxy'] ) {
			curl_setopt( $handle, CURLOPT_PROXY, '' ); // Ignore http_proxy/https_proxy environment variables.
			curl_setopt( $handle, CURLOPT_RESOLVE, array( sprintf( '%s:%d:%s', $this->target['host'], $this->target['port'], $ip ) ) );
		}
		curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
		curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
		curl_setopt( $handle, CURLOPT_MAXFILESIZE, (int) $this->target['max_bytes'] ); // Content-Length check (and, with libcurl 8.4+, during the transfer).
		curl_setopt( $handle, CURLOPT_NOPROGRESS, false );
		curl_setopt(
			$handle,
			defined( 'CURLOPT_XFERINFOFUNCTION' ) ? CURLOPT_XFERINFOFUNCTION : CURLOPT_PROGRESSFUNCTION, // XFERINFO constant: PHP 8.2+.
			static function ( $ch, $download_total, $downloaded ) use ( $limit, $self ) {
				return ( $downloaded > $limit || $self->decoded_too_large() ) ? 1 : 0; // Non-zero aborts the transfer.
			}
		);
		// phpcs:enable

		$this->pinned = true;
	}

	/**
	 * Tracks the decompressed size of the body as Requests receives it.
	 *
	 * Requests reports each decoded chunk before storing it; its own byte limit only truncates
	 * the stored body, and older libcurl versions keep decompressing until the end of the stream.
	 *
	 * @param string $data           Decoded chunk.
	 * @param int    $received_bytes Decoded bytes stored before this chunk.
	 */
	public function track_decoded_size( $data, $received_bytes = 0 ): void {
		if ( null !== $this->target && (int) $received_bytes + strlen( (string) $data ) > (int) $this->target['max_bytes'] ) {
			$this->decoded_too_large = true;
		}
	}

	/**
	 * Whether the decoded body exceeded the size limit (read by the cURL progress callback).
	 *
	 * @return bool
	 */
	public function decoded_too_large(): bool {
		return $this->decoded_too_large;
	}

	/**
	 * Records the address that cURL connected to.
	 *
	 * WordPress forwards only the Requests hook parameters, not the URL. Requests are
	 * synchronous and the hook is registered only around our own request.
	 *
	 * @param string               $headers Raw headers (unused).
	 * @param array<string, mixed> $info    curl_getinfo() result.
	 */
	public function record_connection( $headers, $info = array() ): void {
		if ( null !== $this->target && is_array( $info ) && isset( $info['primary_ip'] ) ) {
			$this->connected_ip = (string) $info['primary_ip'];
		}
	}

	/**
	 * Heuristically detects HTML error/login pages served with a CSV content type.
	 *
	 * @param string $body Response body.
	 * @return bool
	 */
	public static function looks_like_html( string $body ): bool {
		$start = strtolower( ltrim( substr( $body, 0, 1024 ), "\xEF\xBB\xBF \t\r\n" ) );
		foreach ( array( '<!doctype', '<html', '<?xml', '<head', '<body', '<script', '<meta', '<title' ) as $marker ) {
			if ( 0 === strpos( $start, $marker ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns a single response header (the last one, if repeated).
	 *
	 * @param array<string, mixed> $response Response.
	 * @param string               $name     Header name.
	 * @return string
	 */
	private static function header( $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );
		if ( is_array( $value ) ) {
			$value = end( $value );
		}
		return (string) $value;
	}

	/**
	 * Translates transport errors into stable error codes.
	 *
	 * @param WP_Error $error Error from the HTTP API.
	 * @return WP_Error
	 */
	private static function map_transport_error( WP_Error $error ): WP_Error {
		$message = $error->get_error_message();
		$curl    = preg_match( '/cURL error (\d+)/', $message, $match ) ? (int) $match[1] : 0;

		switch ( $curl ) {
			case 28:
				return new WP_Error( 'tablerefresh_http_timeout', __( 'The source did not respond in time.', 'tablerefresh' ), $message );
			case 42: // Aborted by the progress callback.
			case 63: // CURLOPT_MAXFILESIZE.
				return self::too_large_error();
			case 18: // Body shorter than announced, or chunked stream not terminated.
			case 56: // Connection reset while receiving.
				return new WP_Error( 'tablerefresh_http_incomplete', __( 'The download was interrupted before the source was received completely.', 'tablerefresh' ), $message );
			case 6:
			case 7:
				return new WP_Error( 'tablerefresh_http_unreachable', __( 'The source server could not be reached.', 'tablerefresh' ), $message );
			case 35:
			case 51:
			case 58:
			case 60:
				return new WP_Error( 'tablerefresh_http_tls', __( 'A secure (TLS) connection to the source could not be established.', 'tablerefresh' ), $message );
		}

		if ( 'http_request_failed' === $error->get_error_code() && false !== stripos( $message, 'valid URL' ) ) {
			return new WP_Error( 'tablerefresh_url_blocked', __( 'WordPress rejected the URL as unsafe.', 'tablerefresh' ), $message );
		}

		return new WP_Error( 'tablerefresh_http_failed', __( 'The source could not be downloaded.', 'tablerefresh' ), $message );
	}

	/**
	 * Error for oversized responses.
	 *
	 * @return WP_Error
	 */
	private static function too_large_error(): WP_Error {
		return new WP_Error( 'tablerefresh_http_too_large', __( 'The source is larger than the allowed maximum size.', 'tablerefresh' ) );
	}
}
