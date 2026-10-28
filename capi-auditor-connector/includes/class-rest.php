<?php
/**
 * Read-only REST API for the Chrome extension, protected by the connection key
 * shown in Settings → CAPI Auditor. Send it as the X-CAPI-Auditor-Key header.
 *
 *   GET ?rest_route=/capi-auditor/v1/status
 *   GET ?rest_route=/capi-auditor/v1/events&since=<ms>&fbp=<fbp1,fbp2>&event_id=<id1,id2>&limit=100
 */

defined( 'ABSPATH' ) || exit;

class CAPI_Auditor_REST {

	const NS = 'capi-auditor/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( self::NS, '/status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'status' ),
			'permission_callback' => array( __CLASS__, 'authorized' ),
		) );
		register_rest_route( self::NS, '/events', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'events' ),
			'permission_callback' => array( __CLASS__, 'authorized' ),
			'args'                => array(
				'since'    => array( 'type' => 'integer', 'default' => 0 ),
				'fbp'      => array( 'type' => 'string', 'default' => '' ),
				'event_id' => array( 'type' => 'string', 'default' => '' ),
				'limit'    => array( 'type' => 'integer', 'default' => 100 ),
			),
		) );
	}

	/** @param WP_REST_Request $request */
	public static function authorized( $request ) {
		$given = (string) $request->get_header( 'x_capi_auditor_key' );
		$key   = CAPI_Auditor_Store::settings()['key'];
		if ( '' === $given || ! hash_equals( $key, $given ) ) {
			return new WP_Error( 'capi_auditor_forbidden', 'Invalid connection key.', array( 'status' => 401 ) );
		}
		return true;
	}

	public static function status() {
		$s = CAPI_Auditor_Store::settings();
		return self::no_cache( array_merge(
			array(
				'plugin'          => 'capi-auditor-connector',
				'version'         => CAPI_AUDITOR_VERSION,
				'enabled'         => (bool) $s['enabled'],
				'retention_hours' => (int) $s['retention_hours'],
				'server_time'     => (int) round( microtime( true ) * 1000 ),
				'site'            => home_url( '/' ),
			),
			CAPI_Auditor_Store::stats()
		) );
	}

	/** @param WP_REST_Request $request */
	public static function events( $request ) {
		$list = function ( $v ) {
			return array_slice( array_values( array_filter( array_map( 'trim', explode( ',', (string) $v ) ) ) ), 0, 50 );
		};
		$rows = CAPI_Auditor_Store::query( array(
			'since'     => (int) $request['since'],
			'fbp'       => $list( $request['fbp'] ),
			'event_ids' => $list( $request['event_id'] ),
			'limit'     => (int) $request['limit'],
		) );
		return self::no_cache( array(
			'server_time' => (int) round( microtime( true ) * 1000 ),
			'events'      => $rows,
		) );
	}

	private static function no_cache( $data ) {
		$res = new WP_REST_Response( $data );
		$res->header( 'Cache-Control', 'no-store, private' );
		return $res;
	}
}
