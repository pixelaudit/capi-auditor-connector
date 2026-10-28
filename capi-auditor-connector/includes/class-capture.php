<?php
/**
 * Captures Conversions API requests leaving this site.
 *
 * Automatic: every request made through the WordPress HTTP API (wp_remote_post etc.) to
 * graph.facebook.com/<version>/<pixel>/events — this is how Facebook for WooCommerce and most
 * custom integrations send CAPI events. The http_api_debug action fires after the response,
 * so Meta's answer (events_received / error) is recorded too.
 *
 * Manual: integrations with their own HTTP client call do_action( 'capi_auditor_record', ... ).
 */

defined( 'ABSPATH' ) || exit;

class CAPI_Auditor_Capture {

	public static function init() {
		if ( ! CAPI_Auditor_Store::settings()['enabled'] ) {
			return;
		}
		add_action( 'http_api_debug', array( __CLASS__, 'on_http' ), 10, 5 );
	}

	/**
	 * @param array|WP_Error $response
	 * @param string         $context
	 * @param string         $class
	 * @param array          $args
	 * @param string         $url
	 */
	public static function on_http( $response, $context, $class, $args, $url ) {
		if ( 'response' !== $context || ! is_string( $url ) || false === strpos( $url, 'graph.facebook.com' ) ) {
			return;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( ! preg_match( '#/(?:v[\d.]+/)?(\d+)/events/?$#', $path, $m ) ) {
			return;
		}
		$body = isset( $args['body'] ) ? $args['body'] : '';
		if ( isset( $args['blocking'] ) && ! $args['blocking'] && ! is_wp_error( $response ) ) {
			// Fire-and-forget request: WordPress never waits for Meta's answer, so there is no code to judge.
			$resp = array( 'non_blocking' => true );
		} else {
			$resp = is_wp_error( $response )
				? $response
				: array( 'code' => (int) wp_remote_retrieve_response_code( $response ), 'body' => wp_remote_retrieve_body( $response ) );
		}

		self::record( $body, $resp, $m[1], self::detect_source(), $url );
	}

	/**
	 * Normalise any body shape (JSON string, form-encoded string, array with JSON "data") and log each event.
	 *
	 * @param array|string        $body
	 * @param array|WP_Error|null $response
	 */
	public static function record( $body, $response, $pixel_id, $source, $url ) {
		try {
			$request = self::decode_body( $body );
			$events  = isset( $request['data'] ) && is_array( $request['data'] ) ? $request['data'] : array();
			if ( ! $events ) {
				return;
			}

			$meta = self::response_meta( $response );
			$api  = '';
			if ( $url && preg_match( '#/(v[\d.]+)/#', (string) wp_parse_url( $url, PHP_URL_PATH ), $v ) ) {
				$api = $v[1];
			}
			$now = (int) round( microtime( true ) * 1000 );

			foreach ( $events as $raw ) {
				if ( ! is_array( $raw ) ) {
					continue;
				}
				$clean = CAPI_Auditor_Sanitizer::event( $raw );
				$fbp   = isset( $clean['user_data']['fbp']['value'] ) ? $clean['user_data']['fbp']['value'] : '';
				CAPI_Auditor_Store::insert( array(
					'logged_at'  => $now,
					'pixel_id'   => $pixel_id,
					'event_name' => isset( $raw['event_name'] ) ? (string) $raw['event_name'] : '',
					'event_id'   => isset( $raw['event_id'] ) ? (string) $raw['event_id'] : '',
					'fbp'        => $fbp,
					'source'     => $source,
					'http_code'  => $meta['code'],
					'ok'         => $meta['ok'],
					'payload'    => array(
						'pixel_id'        => (string) $pixel_id,
						'api_version'     => $api,
						'test_event_code' => isset( $request['test_event_code'] ) ? (string) $request['test_event_code'] : '',
						'partner_agent'   => isset( $request['partner_agent'] ) ? (string) $request['partner_agent'] : '',
						'batch_size'      => count( $events ),
						'event'           => $clean,
						'response'        => $meta,
					),
				) );
			}
		} catch ( \Throwable $e ) {
			// Diagnostics must never break the store's own tracking or checkout.
			return;
		}
	}

	private static function decode_body( $body ) {
		if ( is_string( $body ) ) {
			$json = json_decode( $body, true );
			if ( is_array( $json ) ) {
				$body = $json;
			} else {
				parse_str( $body, $parsed );
				$body = $parsed;
			}
		}
		if ( ! is_array( $body ) ) {
			return array();
		}
		if ( isset( $body['data'] ) && is_string( $body['data'] ) ) {
			$decoded      = json_decode( $body['data'], true );
			$body['data'] = is_array( $decoded ) ? $decoded : array();
		}
		unset( $body['access_token'], $body['appsecret_proof'] );
		return $body;
	}

	private static function response_meta( $response ) {
		if ( null === $response ) {
			return array( 'code' => 0, 'ok' => true, 'unknown' => true );
		}
		if ( is_wp_error( $response ) ) {
			return array( 'code' => 0, 'ok' => false, 'error' => array( 'message' => $response->get_error_message() ) );
		}
		$code = isset( $response['code'] ) ? (int) $response['code'] : 0;
		if ( ! empty( $response['non_blocking'] ) || 0 === $code ) {
			// No HTTP status means the response was never read — unknown, not a failure.
			return array( 'code' => 0, 'ok' => true, 'unknown' => true, 'non_blocking' => ! empty( $response['non_blocking'] ) );
		}
		$json = isset( $response['body'] ) ? json_decode( (string) $response['body'], true ) : null;
		$meta = array( 'code' => $code, 'ok' => $code >= 200 && $code < 300 );
		if ( is_array( $json ) ) {
			if ( isset( $json['events_received'] ) ) {
				$meta['events_received'] = (int) $json['events_received'];
			}
			if ( isset( $json['fbtrace_id'] ) ) {
				$meta['fbtrace_id'] = (string) $json['fbtrace_id'];
			}
			if ( ! empty( $json['messages'] ) ) {
				$meta['messages'] = array_slice( (array) $json['messages'], 0, 5 );
			}
			if ( isset( $json['error'] ) && is_array( $json['error'] ) ) {
				$e             = $json['error'];
				$meta['ok']    = false;
				$meta['error'] = array_intersect_key( $e, array_flip( array( 'message', 'type', 'code', 'error_subcode', 'error_user_title', 'error_user_msg', 'fbtrace_id' ) ) );
			}
		}
		return $meta;
	}

	/** Name the plugin/theme that sent the request (from the call stack) — helps spot duplicate senders. */
	private static function detect_source() {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ) as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );
			if ( false !== strpos( $file, '/capi-auditor-connector/' ) ) {
				continue;
			}
			// Folder plugins (plugins/<slug>/...) and single-file must-use plugins (mu-plugins/<file>.php).
			if ( preg_match( '#/(mu-plugins|plugins|themes)/([^/]+?)(?:\.php)?(?:/|$)#', $file, $m ) ) {
				return ( 'themes' === $m[1] ? 'theme:' : ( 'mu-plugins' === $m[1] ? 'mu:' : '' ) ) . $m[2];
			}
		}
		return 'unknown';
	}
}
