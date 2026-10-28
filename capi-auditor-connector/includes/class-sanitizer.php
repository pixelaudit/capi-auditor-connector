<?php
/**
 * Turns a raw Conversions API event into a diagnostic copy that is safe to store:
 * every field is kept, but personal data is reduced to "present / hashed / well-formed"
 * plus a short hash preview. Raw personal values are never written to the database.
 */

defined( 'ABSPATH' ) || exit;

class CAPI_Auditor_Sanitizer {

	/** user_data keys Meta requires to be SHA-256 hashed. */
	const HASHED_KEYS = array( 'em', 'ph', 'fn', 'ln', 'ge', 'db', 'ct', 'st', 'zp', 'country' );

	/** Hashing recommended, not required. */
	const HASH_RECOMMENDED = array( 'external_id' );

	/** Pseudonymous identifiers Meta requires in plain text; kept so the extension can match the visitor. */
	const PLAIN_KEEP = array( 'fbc', 'fbp', 'client_user_agent', 'subscription_id', 'fb_login_id', 'lead_id', 'anon_id', 'madid', 'page_id', 'page_scoped_user_id', 'ctwa_clid', 'ig_account_id', 'ig_sid' );

	const PII_PARAM_KEY = '/^(email|e-mail|mail|user_email|customer_email|billing_email|phone|tel|mobile|billing_phone|first_?name|last_?name|address|street|address_1|address_2)$/i';
	const EMAIL_VALUE   = '/[^\s@"\']+@[^\s@"\']+\.[a-z]{2,}/i';

	/** URL query keys that carry secrets, e.g. the WooCommerce order key that unlocks the order-received page. */
	const SECRET_QUERY_KEY = '/^(key|order_key|token|access_token|auth|password|pass|pwd|nonce|_wpnonce|session|sid|code|hash|signature|sig)$/i';
	const SECRET_VALUE     = '/^wc_order_/i';

	/**
	 * @param array $event Raw event from the request body "data" array.
	 * @return array Sanitized event.
	 */
	public static function event( $event ) {
		$event = is_array( $event ) ? $event : array();
		$out   = array();

		foreach ( $event as $key => $value ) {
			switch ( $key ) {
				case 'user_data':
					$out['user_data'] = self::user_data( is_array( $value ) ? $value : array() );
					break;
				case 'custom_data':
					$out['custom_data'] = self::scrub( $value );
					break;
				case 'event_source_url':
				case 'referrer_url':
					$out[ $key ] = self::url( (string) $value );
					break;
				default:
					$out[ $key ] = self::scrub( $value );
			}
		}
		return $out;
	}

	/**
	 * Each user_data key becomes:
	 *  { present, count, hashed, valid, preview, issue }
	 * preview is the first 10 hex chars of a hash (never a raw value).
	 */
	public static function user_data( $ud ) {
		$out = array();
		foreach ( $ud as $key => $value ) {
			$key    = strtolower( (string) $key );
			$values = is_array( $value ) ? array_values( $value ) : array( $value );
			$values = array_filter( $values, function ( $v ) { return null !== $v && '' !== $v; } );

			$entry = array( 'present' => count( $values ) > 0, 'count' => count( $values ) );

			if ( in_array( $key, self::HASHED_KEYS, true ) || in_array( $key, self::HASH_RECOMMENDED, true ) ) {
				$hashed           = $values && count( array_filter( $values, array( __CLASS__, 'is_sha256' ) ) ) === count( $values );
				$entry['hashed']  = $hashed;
				$entry['preview'] = $hashed ? substr( (string) reset( $values ), 0, 10 ) . '…' : null;
				if ( $values && ! $hashed && in_array( $key, self::HASHED_KEYS, true ) ) {
					$entry['issue'] = 'not_hashed'; // raw value deliberately not stored
				}
			} elseif ( 'client_ip_address' === $key ) {
				$entry['value'] = self::mask_ip( (string) reset( $values ) );
			} elseif ( in_array( $key, self::PLAIN_KEEP, true ) ) {
				$v              = (string) reset( $values );
				$entry['value'] = $v;
				if ( self::is_sha256( $v ) ) {
					$entry['issue'] = 'hashed_but_must_be_plain';
				} elseif ( in_array( $key, array( 'fbc', 'fbp' ), true ) && ! preg_match( '/^fb\.\d\.\d{10,13}\..+$/', $v ) ) {
					$entry['issue'] = 'malformed';
				}
			} else {
				$entry['type'] = gettype( reset( $values ) ); // unknown key: record presence only
			}
			$out[ $key ] = $entry;
		}
		return $out;
	}

	/** Recursively redact personal data inside free-form fields (custom_data etc.). */
	public static function scrub( $value, $depth = 0 ) {
		if ( $depth > 6 ) {
			return '[…]';
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = ( is_string( $k ) && preg_match( self::PII_PARAM_KEY, $k ) && '' !== $v && null !== $v )
					? '[redacted PII]'
					: self::scrub( $v, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $value ) && preg_match( self::EMAIL_VALUE, $value ) ) {
			return '[redacted PII]';
		}
		return $value;
	}

	/** Keep scheme/host/path and query keys; redact query values that look personal. */
	public static function url( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return self::scrub( $url );
		}
		$clean = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'] . ( isset( $parts['path'] ) ? $parts['path'] : '/' );
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			foreach ( $q as $k => $v ) {
				if ( preg_match( self::SECRET_QUERY_KEY, (string) $k ) || ( is_string( $v ) && preg_match( self::SECRET_VALUE, $v ) ) ) {
					$q[ $k ] = '[redacted]';
				}
			}
			$q      = self::scrub( $q );
			$clean .= '?' . http_build_query( $q );
		}
		return $clean;
	}

	public static function is_sha256( $v ) {
		return is_string( $v ) && (bool) preg_match( '/^[a-f0-9]{64}$/i', $v );
	}

	public static function mask_ip( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return preg_replace( '/\.\d+$/', '.0', $ip );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$groups = explode( ':', $ip );
			return implode( ':', array_slice( $groups, 0, 3 ) ) . '::';
		}
		return $ip ? '[invalid]' : '';
	}
}
