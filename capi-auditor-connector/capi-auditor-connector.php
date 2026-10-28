<?php
/**
 * Plugin Name:       CAPI Auditor Connector
 * Description:       Records a sanitized diagnostic copy of every Meta Conversions API event this site sends, so the CAPI & Match Quality Auditor Chrome extension can compare server events with browser pixel events.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            CAPI Auditor
 * License:           GPL-2.0-or-later
 * Text Domain:       capi-auditor-connector
 */

defined( 'ABSPATH' ) || exit;

define( 'CAPI_AUDITOR_VERSION', '1.0.1' );
define( 'CAPI_AUDITOR_FILE', __FILE__ );
define( 'CAPI_AUDITOR_DIR', plugin_dir_path( __FILE__ ) );

require_once CAPI_AUDITOR_DIR . 'includes/class-sanitizer.php';
require_once CAPI_AUDITOR_DIR . 'includes/class-store.php';
require_once CAPI_AUDITOR_DIR . 'includes/class-capture.php';
require_once CAPI_AUDITOR_DIR . 'includes/class-rest.php';
require_once CAPI_AUDITOR_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'CAPI_Auditor_Store', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CAPI_Auditor_Store', 'deactivate' ) );

add_action( 'plugins_loaded', function () {
	CAPI_Auditor_Store::maybe_upgrade();
	CAPI_Auditor_Capture::init();
	CAPI_Auditor_REST::init();
	if ( is_admin() ) {
		CAPI_Auditor_Admin::init();
	}
} );

add_action( CAPI_Auditor_Store::CRON_HOOK, array( 'CAPI_Auditor_Store', 'purge' ) );

/**
 * Public helper for integrations that do not use the WordPress HTTP API
 * (e.g. the Facebook PHP Business SDK with its own curl client).
 *
 * Call it right after sending the request:
 *   do_action( 'capi_auditor_record', $request_body, $response, $pixel_id );
 *
 * @param array|string        $body     The CAPI request body ({"data":[...], "test_event_code":...}).
 * @param array|WP_Error|null $response Optional. ['code' => 200, 'body' => '{"events_received":1}'] or WP_Error.
 * @param string              $pixel_id Optional. Pixel / dataset ID.
 */
function capi_auditor_record( $body, $response = null, $pixel_id = '' ) {
	CAPI_Auditor_Capture::record( $body, $response, (string) $pixel_id, 'custom', '' );
}
add_action( 'capi_auditor_record', 'capi_auditor_record', 10, 3 );
