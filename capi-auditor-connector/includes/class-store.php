<?php
/**
 * Diagnostic log storage: one row per CAPI event, purged after the retention window.
 */

defined( 'ABSPATH' ) || exit;

class CAPI_Auditor_Store {

	const DB_VERSION = '1';
	const CRON_HOOK  = 'capi_auditor_purge';
	const MAX_ROWS   = 5000;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'capi_auditor_log';
	}

	public static function defaults() {
		return array(
			'enabled'         => true,
			'retention_hours' => 48,
			'key'             => '',
		);
	}

	public static function settings() {
		$s = wp_parse_args( get_option( 'capi_auditor_settings', array() ), self::defaults() );
		if ( empty( $s['key'] ) ) {
			$s['key'] = self::new_key();
			update_option( 'capi_auditor_settings', $s, false );
		}
		return $s;
	}

	public static function new_key() {
		return 'capia_' . bin2hex( random_bytes( 24 ) );
	}

	public static function activate() {
		self::create_table();
		self::settings();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'capi_auditor_db_version' ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	private static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			logged_at bigint(20) unsigned NOT NULL,
			pixel_id varchar(32) NOT NULL DEFAULT '',
			event_name varchar(100) NOT NULL DEFAULT '',
			event_id varchar(191) NOT NULL DEFAULT '',
			fbp varchar(191) NOT NULL DEFAULT '',
			source varchar(100) NOT NULL DEFAULT '',
			http_code smallint(5) unsigned NOT NULL DEFAULT 0,
			ok tinyint(1) NOT NULL DEFAULT 0,
			payload longtext NOT NULL,
			PRIMARY KEY  (id),
			KEY logged_at (logged_at),
			KEY fbp (fbp),
			KEY event_id (event_id)
		) {$charset};" );
		update_option( 'capi_auditor_db_version', self::DB_VERSION, false );
	}

	/**
	 * @param array $row Keys: logged_at (ms), pixel_id, event_name, event_id, fbp, source, http_code, ok, payload (array).
	 */
	public static function insert( $row ) {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'logged_at'  => (int) $row['logged_at'],
				'pixel_id'   => substr( (string) $row['pixel_id'], 0, 32 ),
				'event_name' => substr( (string) $row['event_name'], 0, 100 ),
				'event_id'   => substr( (string) $row['event_id'], 0, 191 ),
				'fbp'        => substr( (string) $row['fbp'], 0, 191 ),
				'source'     => substr( (string) $row['source'], 0, 100 ),
				'http_code'  => (int) $row['http_code'],
				'ok'         => $row['ok'] ? 1 : 0,
				'payload'    => wp_json_encode( $row['payload'] ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		// Sites with WP-Cron disabled still get cleaned up.
		if ( 1 === wp_rand( 1, 200 ) ) {
			self::purge();
		}
	}

	/**
	 * @param array $q since (ms), fbp (string[]), event_ids (string[]), limit (int)
	 */
	public static function query( $q ) {
		global $wpdb;
		$table  = self::table();
		$where  = array( 'logged_at >= %d' );
		$params = array( (int) $q['since'] );

		$or = array();
		if ( ! empty( $q['fbp'] ) ) {
			$or[]   = 'fbp IN (' . implode( ',', array_fill( 0, count( $q['fbp'] ), '%s' ) ) . ')';
			$params = array_merge( $params, $q['fbp'] );
		}
		if ( ! empty( $q['event_ids'] ) ) {
			$or[]   = 'event_id IN (' . implode( ',', array_fill( 0, count( $q['event_ids'] ), '%s' ) ) . ')';
			$params = array_merge( $params, $q['event_ids'] );
		}
		if ( $or ) {
			$where[] = '(' . implode( ' OR ', $or ) . ')';
		}
		$params[] = max( 1, min( 500, (int) $q['limit'] ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal, values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY logged_at DESC LIMIT %d', $params ), ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	public static function recent( $limit = 50 ) {
		return self::query( array( 'since' => 0, 'limit' => $limit ) );
	}

	private static function hydrate( $row ) {
		$payload = json_decode( $row['payload'], true );
		return array_merge(
			is_array( $payload ) ? $payload : array(),
			array(
				'id'        => (int) $row['id'],
				'logged_at' => (int) $row['logged_at'],
				'source'    => $row['source'],
				'http_code' => (int) $row['http_code'],
				'ok'        => (bool) $row['ok'],
			)
		);
	}

	public static function stats() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Rows with http_code 0 and ok = 0 come from v1.0.0 logging non-blocking sends as failures; don't count them.
		$r = $wpdb->get_row( "SELECT COUNT(*) AS n, MAX(logged_at) AS last_at, SUM(ok = 0 AND (http_code > 0 OR payload LIKE '%\"error\"%')) AS failed FROM {$table}", ARRAY_A );
		return array(
			'count'   => (int) ( $r['n'] ?? 0 ),
			'last_at' => $r['last_at'] ? (int) $r['last_at'] : null,
			'failed'  => (int) ( $r['failed'] ?? 0 ),
		);
	}

	public static function purge() {
		global $wpdb;
		$table  = self::table();
		$hours  = max( 1, (int) self::settings()['retention_hours'] );
		$cutoff = (int) round( ( microtime( true ) - $hours * 3600 ) * 1000 );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE logged_at < %d", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Hard cap so a busy store never grows the table without bound.
		$keep_from = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $keep_from ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", (int) $keep_from ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	public static function clear() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
