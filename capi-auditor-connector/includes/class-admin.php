<?php
/**
 * Settings → CAPI Auditor: connection key, logging options and the recent event log.
 */

defined( 'ABSPATH' ) || exit;

class CAPI_Auditor_Admin {

	const SLUG = 'capi-auditor';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_capi_auditor_save', array( __CLASS__, 'save' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CAPI_AUDITOR_FILE ), array( __CLASS__, 'links' ) );
	}

	public static function menu() {
		add_options_page( 'CAPI Auditor', 'CAPI Auditor', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">Settings</a>' );
		return $links;
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'capi_auditor_save' );
		$s  = CAPI_Auditor_Store::settings();
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : 'save';

		if ( 'regenerate' === $do ) {
			$s['key'] = CAPI_Auditor_Store::new_key();
			$msg      = 'key';
		} elseif ( 'clear' === $do ) {
			CAPI_Auditor_Store::clear();
			$msg = 'cleared';
		} else {
			$s['enabled']         = ! empty( $_POST['enabled'] );
			$s['retention_hours'] = max( 1, min( 720, isset( $_POST['retention_hours'] ) ? absint( $_POST['retention_hours'] ) : 48 ) );
			$msg                  = 'saved';
		}
		update_option( 'capi_auditor_settings', $s, false );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG . '&msg=' . $msg ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = CAPI_Auditor_Store::settings();
		$stats  = CAPI_Auditor_Store::stats();
		$recent = CAPI_Auditor_Store::recent( 50 );
		$msg    = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notes  = array( 'saved' => 'Settings saved.', 'key' => 'New connection key created. Update it in the Chrome extension.', 'cleared' => 'Event log cleared.' );
		?>
		<div class="wrap">
			<h1>CAPI Auditor Connector</h1>
			<?php if ( isset( $notes[ $msg ] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notes[ $msg ] ); ?></p></div>
			<?php endif; ?>

			<p>Records a sanitized copy of every Conversions API event this site sends to Meta, so the <strong>CAPI &amp; Match Quality Auditor</strong> Chrome extension can compare them with browser pixel events. Personal data is never stored: hashed fields keep only a 10-character hash preview, unhashed values are flagged and discarded, IP addresses are masked and access tokens are removed.</p>

			<h2>Connect the Chrome extension</h2>
			<p>Open the extension on this site → <em>CAPI &amp; Match</em> → <em>Server event log</em>, and paste this key:</p>
			<p>
				<input type="text" readonly class="large-text code" id="capi-auditor-key" value="<?php echo esc_attr( $s['key'] ); ?>" onclick="this.select()" style="max-width:560px">
				<button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('capi-auditor-key').value);this.textContent='Copied'">Copy</button>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'capi_auditor_save' ); ?>
				<input type="hidden" name="action" value="capi_auditor_save">
				<button class="button" name="do" value="regenerate" onclick="return confirm('Create a new key? Connected browsers will need the new key.')">Regenerate key</button>
			</form>

			<h2>Settings</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'capi_auditor_save' ); ?>
				<input type="hidden" name="action" value="capi_auditor_save">
				<table class="form-table" role="presentation">
					<tr><th scope="row">Logging</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> Record outgoing Conversions API events</label></td></tr>
					<tr><th scope="row"><label for="capi-ret">Keep logs for</label></th><td><input id="capi-ret" type="number" min="1" max="720" name="retention_hours" value="<?php echo esc_attr( $s['retention_hours'] ); ?>" class="small-text"> hours <p class="description">Older entries are deleted automatically (max <?php echo esc_html( number_format_i18n( CAPI_Auditor_Store::MAX_ROWS ) ); ?> entries).</p></td></tr>
				</table>
				<p><button class="button button-primary" name="do" value="save">Save settings</button>
					<button class="button" name="do" value="clear" onclick="return confirm('Delete all logged events?')">Clear log</button></p>
			</form>

			<h2>Recent server events <span style="font-weight:400;color:#646970">(<?php echo esc_html( number_format_i18n( $stats['count'] ) ); ?> logged<?php echo $stats['failed'] ? ', <span style="color:#b32d2e">' . esc_html( $stats['failed'] ) . ' rejected</span>' : ''; ?>)</span></h2>
			<?php if ( ! $recent ) : ?>
				<p>No Conversions API events captured yet. Place a test order or trigger an event, then reload this page.</p>
				<p class="description">Captured automatically: integrations that send through the WordPress HTTP API (for example Facebook for WooCommerce). Others can call <code>do_action( 'capi_auditor_record', $request_body, $response, $pixel_id );</code> after sending.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th>Time</th><th>Event</th><th>event_id</th><th>Pixel</th><th>Sent by</th><th>Match keys</th><th>Meta response</th></tr></thead>
					<tbody>
					<?php foreach ( $recent as $r ) :
						$ev   = isset( $r['event'] ) ? $r['event'] : array();
						$ud   = isset( $ev['user_data'] ) ? $ev['user_data'] : array();
						$keys = array();
						foreach ( $ud as $k => $u ) {
							if ( ! empty( $u['present'] ) ) {
								$keys[] = $k . ( ! empty( $u['issue'] ) ? ' ⚠' : '' );
							}
						}
						$resp = isset( $r['response'] ) ? $r['response'] : array();
						?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) ( $r['logged_at'] / 1000 ) ) ); ?></td>
							<td><strong><?php echo esc_html( isset( $ev['event_name'] ) ? $ev['event_name'] : '' ); ?></strong></td>
							<td><code><?php echo esc_html( isset( $ev['event_id'] ) ? $ev['event_id'] : '—' ); ?></code></td>
							<td><code><?php echo esc_html( isset( $r['pixel_id'] ) ? $r['pixel_id'] : '' ); ?></code></td>
							<td><?php echo esc_html( $r['source'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', $keys ) ); ?></td>
							<td><?php
							if ( ! empty( $resp['unknown'] ) || ( empty( $resp['code'] ) && empty( $resp['error'] ) ) ) {
								echo '<span style="color:#646970" title="Sent without waiting for Meta\'s reply (non-blocking request)">sent · no reply awaited</span>';
							} elseif ( ! empty( $resp['ok'] ) ) {
								echo '<span style="color:#008a20">' . esc_html( ( $resp['code'] ? $resp['code'] : 'sent' ) . ( isset( $resp['events_received'] ) ? ' · received ' . $resp['events_received'] : '' ) ) . '</span>';
							} else {
								echo '<span style="color:#b32d2e">' . esc_html( ( $resp['code'] ? $resp['code'] . ' · ' : '' ) . ( isset( $resp['error']['message'] ) ? $resp['error']['message'] : 'failed' ) ) . '</span>';
							}
							?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
