<?php
/**
 * Plugin Name: CmdPost Connector
 * Description: Reports installed versions and applies approved WordPress core patches, plugin updates, and theme updates from CmdPost.
 * Version: 0.6.1
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: cmdpost-connector
 * Update URI: https://sevenseven.tech/cmdpost-connector/
 */

namespace CmdPost\Connector;

defined( 'ABSPATH' ) || exit;

const OPTION = 'cmdpost_connector';
const LAST_REPORT_OPTION = 'cmdpost_connector_last_report';
const REPORT_HOOK = 'cmdpost_connector_report';

require_once __DIR__ . '/updates.php';

add_action( 'admin_menu', __NAMESPACE__ . '\\register_settings_page' );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\\add_settings_link' );
add_action( 'admin_post_cmdpost_connector', __NAMESPACE__ . '\\handle_settings_action' );
add_action( 'template_redirect', __NAMESPACE__ . '\\serve_verification_challenge', 0 );
add_action( REPORT_HOOK, __NAMESPACE__ . '\\send_report' );
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\clear_schedule' );

function activate() {
	if ( is_multisite() ) {
		wp_die( esc_html__( 'CmdPost Connector currently supports single-site WordPress installations.', 'cmdpost-connector' ), '', array( 'back_link' => true ) );
	}
	$connection = get_option( OPTION, array() );
	if ( ! empty( $connection['token'] ) ) {
		schedule_report();
		if ( has_update_grant( $connection ) ) {
			schedule_update_check();
		}
	}
}

function schedule_report() {
	if ( ! wp_next_scheduled( REPORT_HOOK ) ) {
		wp_schedule_event( time() + wp_rand( 300, HOUR_IN_SECONDS ), 'hourly', REPORT_HOOK );
	}
}

function clear_schedule() {
	wp_clear_scheduled_hook( REPORT_HOOK );
	wp_clear_scheduled_hook( UPDATE_HOOK );
}

function register_settings_page() {
	if ( ! is_multisite() ) {
		add_options_page( 'CmdPost Connector', 'CmdPost Connector', 'manage_options', 'cmdpost-connector', __NAMESPACE__ . '\\settings_page' );
	}
}

function add_settings_link( $links ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return $links;
	}
	$url = menu_page_url( 'cmdpost-connector', false );
	if ( '' !== $url ) {
		$links = array( 'settings' => '<a href="' . $url . '">' . esc_html__( 'Settings', 'cmdpost-connector' ) . '</a>' ) + $links;
	}
	return $links;
}

function settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$connection = get_option( OPTION, array() );
	$version = get_file_data( __FILE__, array( 'version' => 'Version' ) )['version'];
	$result = isset( $_GET['cmdpost_result'] ) && is_string( $_GET['cmdpost_result'] )
		? sanitize_key( wp_unslash( $_GET['cmdpost_result'] ) ) : '';
	$messages = array(
		'paired'     => __( 'Connected to CmdPost.', 'cmdpost-connector' ),
		'reported'   => __( 'CmdPost received your report.', 'cmdpost-connector' ),
		'cleared'    => __( 'The saved connection was removed from WordPress.', 'cmdpost-connector' ),
		'invalid'    => __( 'WordPress did not receive a connector action. Reload this settings page and submit the form again.', 'cmdpost-connector' ),
		'already-connected' => __( 'A CmdPost connection is already saved. Clear it here before pairing again.', 'cmdpost-connector' ),
		'disconnected' => __( 'Pair this WordPress site with CmdPost before sending a report.', 'cmdpost-connector' ),
		'invalid-url' => __( 'Use a public HTTPS URL for the CmdPost instance that generated the code. For a local CmdPost instance, use its HTTPS tunnel URL.', 'cmdpost-connector' ),
		'invalid-code' => __( 'Copy the full pairing code from CmdPost. It starts with cwp1.', 'cmdpost-connector' ),
		'rejected'   => __( 'Check that the code is current and that the site URL in CmdPost matches your WordPress Site Address.', 'cmdpost-connector' ),
		'proof'      => __( 'CmdPost could not verify this site. Make sure its HTTPS homepage is publicly accessible, or use DNS verification in CmdPost before trying again.', 'cmdpost-connector' ),
		'revoked'    => __( 'CmdPost rejected the connection. Disconnect it in CmdPost, clear the saved connection here, then pair again.', 'cmdpost-connector' ),
		'paused'     => __( 'CmdPost could not accept this report. Check whether the site is paused and try again.', 'cmdpost-connector' ),
		'recent'     => __( 'CmdPost already received a report in the last minute.', 'cmdpost-connector' ),
		'payload'    => __( 'CmdPost could not accept this inventory. Check the size of your plugin and theme inventory or contact support.', 'cmdpost-connector' ),
		'network'    => __( 'Could not reach CmdPost. Check the CmdPost URL and outbound HTTPS access, then try again.', 'cmdpost-connector' ),
		'save'       => __( 'WordPress could not save the connection. Disconnect it in CmdPost and generate a new code before trying again.', 'cmdpost-connector' ),
		'updates-enabled' => __( 'Remote updates are enabled for the selected type.', 'cmdpost-connector' ),
		'updates-disabled' => __( 'Remote updates are disabled for the selected type.', 'cmdpost-connector' ),
		'updates-blocked' => __( 'Your WordPress account or hosting configuration does not allow these updates.', 'cmdpost-connector' ),
		'updates-schedule' => __( 'WordPress could not schedule update checks. Check that WP-Cron is working.', 'cmdpost-connector' ),
		'updates-enable-failed' => __( 'CmdPost could not enable remote updates. Check the connection and try again.', 'cmdpost-connector' ),
		'updates-disable-unsynced' => __( 'WordPress stopped accepting updates. CmdPost could not be reached to confirm the change.', 'cmdpost-connector' ),
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'CmdPost Connector', 'cmdpost-connector' ); ?></h1>
		<p class="description"><?php echo esc_html( sprintf( __( 'Version %s', 'cmdpost-connector' ), $version ) ); ?></p>
		<?php if ( isset( $messages[ $result ] ) ) : ?>
			<?php $success = in_array( $result, array( 'paired', 'reported', 'cleared', 'recent', 'updates-enabled', 'updates-disabled' ), true ); ?>
			<div class="notice <?php echo $success ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( $messages[ $result ] ); ?></p></div>
		<?php endif; ?>
		<?php if ( empty( $connection['token'] ) ) : ?>
			<p><?php esc_html_e( 'Get a pairing code from the site’s page in CmdPost. The connector can verify ownership of a publicly reachable HTTPS site. Pairing shares your WordPress and PHP versions, installed plugins and themes, and the updates WordPress offers.', 'cmdpost-connector' ); ?></p>
			<p><?php esc_html_e( 'WordPress Site Address', 'cmdpost-connector' ); ?> <code><?php echo esc_html( site_address() ); ?></code></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cmdpost_connector' ); ?>
				<input type="hidden" name="action" value="cmdpost_connector">
				<input type="hidden" name="task" value="pair">
				<table class="form-table"><tbody>
					<tr><th scope="row"><label for="cmdpost-url"><?php esc_html_e( 'CmdPost URL', 'cmdpost-connector' ); ?></label></th>
						<td><input class="regular-text" id="cmdpost-url" name="endpoint" type="url" placeholder="https://" maxlength="2048" aria-describedby="cmdpost-url-help" required>
							<p class="description" id="cmdpost-url-help"><?php esc_html_e( 'Use the public HTTPS URL of the CmdPost instance that generated your code.', 'cmdpost-connector' ); ?></p></td></tr>
					<tr><th scope="row"><label for="cmdpost-code"><?php esc_html_e( 'Pairing code', 'cmdpost-connector' ); ?></label></th>
						<td><input class="regular-text" id="cmdpost-code" name="code" type="text" autocomplete="off" spellcheck="false" maxlength="115" required></td></tr>
				</tbody></table>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Connect to CmdPost', 'cmdpost-connector' ); ?></button>
			</form>
		<?php else : ?>
			<p><?php esc_html_e( 'CmdPost URL', 'cmdpost-connector' ); ?> <code><?php echo esc_html( $connection['endpoint'] ); ?></code></p>
			<?php $last_report = (int) get_option( LAST_REPORT_OPTION, 0 ); ?>
			<p><?php esc_html_e( 'Last successful report', 'cmdpost-connector' ); ?>
				<strong><?php echo esc_html( ! $last_report ? __( 'Waiting for first report', 'cmdpost-connector' ) : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_report ) ); ?></strong></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cmdpost_connector' ); ?>
				<input type="hidden" name="action" value="cmdpost_connector">
				<button class="button button-primary" name="task" value="report"><?php esc_html_e( 'Send status now', 'cmdpost-connector' ); ?></button>
				<button class="button" name="task" value="clear"><?php esc_html_e( 'Clear saved connection', 'cmdpost-connector' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'Use Disconnect WordPress in CmdPost to revoke this connection before clearing it here.', 'cmdpost-connector' ); ?></p>
			<h2><?php esc_html_e( 'Remote WordPress updates', 'cmdpost-connector' ); ?></h2>
			<p><?php esc_html_e( 'CmdPost can queue one approved update at a time. WordPress checks for queued updates every five minutes when WP-Cron runs.', 'cmdpost-connector' ); ?></p>
			<?php
			$update_permissions = array(
				'plugin' => array( 'updates', __( 'Enable remote plugin updates', 'cmdpost-connector' ), __( 'Disable remote plugin updates', 'cmdpost-connector' ) ),
				'theme'  => array( 'theme_updates', __( 'Enable remote theme updates', 'cmdpost-connector' ), __( 'Disable remote theme updates', 'cmdpost-connector' ) ),
				'core'   => array( 'core_updates', __( 'Enable remote core patches', 'cmdpost-connector' ), __( 'Disable remote core patches', 'cmdpost-connector' ) ),
			);
			foreach ( $update_permissions as $kind => list( $task_prefix, $enable_label, $disable_label ) ) :
				$updates_enabled = null !== update_grant( $connection, $kind );
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'cmdpost_connector', '_cmdpost_' . $kind . '_nonce' ); ?>
					<input type="hidden" name="action" value="cmdpost_connector">
					<input type="hidden" name="task" value="<?php echo esc_attr( $task_prefix . ( $updates_enabled ? '_disable' : '_enable' ) ); ?>">
					<button type="submit" class="button"><?php echo esc_html( $updates_enabled ? $disable_label : $enable_label ); ?></button>
				</form>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
}

function post_field( $name ) {
	return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? trim( wp_unslash( $_POST[ $name ] ) ) : '';
}

function handle_settings_action() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || is_multisite() || ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You cannot manage the CmdPost connection.', 'cmdpost-connector' ), '', array( 'response' => 403 ) );
	}
	$task = post_field( 'task' );
	$kind = 0 === strpos( $task, 'core_updates_' ) ? 'core' : ( 0 === strpos( $task, 'theme_updates_' ) ? 'theme' : 'plugin' );
	$nonce_field = 0 === strpos( $task, 'updates_' ) || 'plugin' !== $kind ? '_cmdpost_' . $kind . '_nonce' : '_wpnonce';
	check_admin_referer( 'cmdpost_connector', $nonce_field );
	$result = 'invalid';
	// Older copies of the form used the submit button to send the task.
	if ( '' === $task && isset( $_POST['endpoint'], $_POST['code'] ) ) {
		$task = 'pair';
	}
	if ( 'pair' === $task ) {
		$result = empty( get_option( OPTION, array() )['token'] )
			? pair( post_field( 'endpoint' ), post_field( 'code' ) )
			: 'already-connected';
	} elseif ( 'report' === $task ) {
		retry_pending_update_result();
		$result = send_report();
	} elseif ( in_array( $task, array( 'updates_enable', 'theme_updates_enable', 'core_updates_enable' ), true ) ) {
		$result = enable_remote_updates( get_option( OPTION, array() ), $kind );
	} elseif ( in_array( $task, array( 'updates_disable', 'theme_updates_disable', 'core_updates_disable' ), true ) ) {
		$result = disable_remote_updates( get_option( OPTION, array() ), $kind );
	} elseif ( 'clear' === $task ) {
		disable_remote_updates( get_option( OPTION, array() ) );
		disable_remote_updates( get_option( OPTION, array() ), 'theme' );
		if ( null !== update_grant( get_option( OPTION, array() ), 'core' ) ) {
			disable_remote_updates( get_option( OPTION, array() ), 'core' );
		}
		delete_option( OPTION );
		delete_option( LAST_REPORT_OPTION );
		delete_option( UPDATE_RESULT_OPTION );
		clear_schedule();
		$result = 'cleared';
	}

	wp_safe_redirect( add_query_arg( 'cmdpost_result', $result, admin_url( 'options-general.php?page=cmdpost-connector' ) ) );
	exit;
}

function pair( $endpoint, $code ) {
	$endpoint = untrailingslashit( $endpoint );
	$parts    = wp_parse_url( $endpoint );
	if ( 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] )
		|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
		return 'invalid-url';
	}
	if ( ! preg_match( '/^cwp1\.[1-9][0-9]{0,15}\.[1-9][0-9]{0,15}\.[0-9a-f]{64}$/D', $code ) ) {
		return 'invalid-code';
	}

	$pairing  = array( 'code' => $code, 'siteUrl' => site_address() );
	$response = request( $endpoint, 'pair', $pairing );
	if ( 202 === wp_remote_retrieve_response_code( $response ) ) {
		$data     = json_decode( wp_remote_retrieve_body( $response ), true );
		$response = verify_ownership( $endpoint, $pairing, $data );
	}
	if ( is_wp_error( $response ) ) {
		return 'cmdpost_verification_challenge' === $response->get_error_code() ? 'proof' : 'network';
	}
	$status = wp_remote_retrieve_response_code( $response );
	if ( 401 === $status ) {
		return 'rejected';
	}
	if ( 409 === $status || 422 === $status ) {
		return 'proof';
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	$token = $data['token'] ?? '';
	if ( 200 !== $status || ! is_string( $token )
		|| ! preg_match( '/^cwt1\.[1-9][0-9]{0,15}\.[1-9][0-9]{0,15}\.[0-9a-f]{64}$/D', $token ) ) {
		return 'network';
	}

	if ( ! update_option( OPTION, array( 'endpoint' => $endpoint, 'token' => $token ), false ) ) {
		return 'save';
	}
	delete_option( LAST_REPORT_OPTION );
	schedule_report();
	$result = send_report();
	return 'reported' === $result ? $result : ( 'network' === $result ? 'paired' : $result );
}

function is_challenge_value( $value ) {
	return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{64}$/D', $value );
}

function verify_ownership( $endpoint, $pairing, $data ) {
	$id      = $data['challenge']['id'] ?? null;
	$proof   = $data['challenge']['proof'] ?? null;
	$expires = $data['expiresIn'] ?? null;
	if ( ! is_challenge_value( $id ) || ! is_challenge_value( $proof )
		|| ! is_int( $expires ) || $expires < 1 || $expires > 900 ) {
		return new \WP_Error( 'cmdpost_verification_challenge' );
	}

	$key = 'cmdpost_verify_' . $id;
	if ( ! set_transient( $key, $proof, $expires ) && get_transient( $key ) !== $proof ) {
		return new \WP_Error( 'cmdpost_verification_challenge' );
	}
	try {
		$pairing['verify'] = true;
		return request( $endpoint, 'pair', $pairing );
	} finally {
		delete_transient( $key );
	}
}

function serve_verification_challenge() {
	if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_GET['cmdpost_verify'] ) ) {
		return;
	}
	$id = $_GET['cmdpost_verify'];
	if ( ! is_challenge_value( $id ) ) {
		return;
	}
	$request_path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	$site_path    = wp_parse_url( site_address(), PHP_URL_PATH );
	if ( untrailingslashit( (string) $request_path ) !== untrailingslashit( (string) $site_path ) ) {
		return;
	}

	$proof = get_transient( 'cmdpost_verify_' . $id );
	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	if ( ! is_challenge_value( $proof ) ) {
		status_header( 404 );
		exit;
	}
	status_header( 200 );
	echo 'cmdpost-wordpress-verification-v1:' . $proof;
	exit;
}

function request( $endpoint, $route, $data, $token = '' ) {
	$body = wp_json_encode( $data );
	if ( false === $body || strlen( $body ) > 192 * 1024 ) {
		return new \WP_Error( 'cmdpost_inventory_size' );
	}

	$headers = array( 'Content-Type' => 'application/json' );
	if ( '' !== $token ) {
		$headers['Authorization'] = 'Bearer ' . $token;
	}
	return wp_safe_remote_post(
		$endpoint . '/api/wordpress/v1/' . $route,
		array(
			'headers'             => $headers,
			'body'                => $body,
			'timeout'             => 12,
			'redirection'         => 0,
			'limit_response_size' => 4096,
		)
	);
}

function send_report() {
	$connection = get_option( OPTION, array() );
	if ( empty( $connection['token'] ) || is_multisite() ) {
		return 'disconnected';
	}
	$report = inventory();
	if ( count( $report['plugins'] ) > 500 || count( $report['themes'] ) > 200 ) {
		return 'payload';
	}
	$response = request( $connection['endpoint'], 'report', $report, $connection['token'] );
	if ( is_wp_error( $response ) ) {
		return 'cmdpost_inventory_size' === $response->get_error_code() ? 'payload' : 'network';
	}

	switch ( wp_remote_retrieve_response_code( $response ) ) {
		case 200:
			update_option( LAST_REPORT_OPTION, time(), false );
			return 'reported';
		case 400:
		case 413:
			return 'payload';
		case 401:
		case 403:
			return 'revoked';
		case 409:
			return 'paused';
		case 429:
			return 'recent';
		default:
			return 'network';
	}
}

function version_text( $value ) {
	if ( ! is_string( $value ) ) {
		return null;
	}
	$value = trim( wp_strip_all_tags( $value ) );
	return '' === $value ? null : wp_html_excerpt( $value, 100 );
}

function site_address() {
	return (string) get_option( 'home' );
}

function last_checked( $transient, $result_key ) {
	$checked = (int) ( $transient->last_checked ?? 0 );
	return $checked > 0 && isset( $transient->{$result_key} ) ? $checked : null;
}

function inventory() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
	$automatic     = new \WP_Automatic_Updater();
	$plugins_cache = get_site_transient( 'update_plugins' );
	$themes_cache  = get_site_transient( 'update_themes' );
	$core_cache    = get_site_transient( 'update_core' );
	$wp_version    = get_bloginfo( 'version' );
	$core_update   = null;
	foreach ( (array) ( $core_cache->updates ?? array() ) as $offer ) {
		if ( 'upgrade' === ( $offer->response ?? '' ) && ! empty( $offer->current )
			&& version_compare( $offer->current, $wp_version, '>' ) ) {
			$core_update = version_text( $offer->current );
			break;
		}
	}

	$plugins = array();
	$plugin_offers = (array) ( $plugins_cache->response ?? array() );
	foreach ( get_plugins() as $file => $plugin ) {
		$offer = (array) ( $plugin_offers[ $file ] ?? array() );
		$plugins[] = component( $file, $plugin['Name'], $plugin['Version'], $offer, is_plugin_active( $file ) );
	}

	$themes = array();
	$theme_offers = (array) ( $themes_cache->response ?? array() );
	$active_stylesheet = get_stylesheet();
	$active_template   = get_template();
	foreach ( wp_get_themes() as $stylesheet => $theme ) {
		$offer = (array) ( $theme_offers[ $stylesheet ] ?? array() );
		$themes[] = component( $stylesheet, $theme->get( 'Name' ), $theme->get( 'Version' ), $offer, $stylesheet === $active_stylesheet || $stylesheet === $active_template );
	}

	return array(
		'siteUrl'          => site_address(),
		'wordpressVersion' => $wp_version,
		'phpVersion'       => PHP_VERSION,
		'coreUpdate'       => $core_update,
		'corePatch'        => available_core_patch( $wp_version, $core_cache )->current ?? null,
		'coreAdminUrl'     => admin_url(),
		'backgroundUpdatesBlocked' => $automatic->is_disabled(),
		'checkedAt'        => array(
			'core'    => last_checked( $core_cache, 'updates' ),
			'plugins' => last_checked( $plugins_cache, 'response' ),
			'themes'  => last_checked( $themes_cache, 'response' ),
		),
		'plugins'          => $plugins,
		'themes'           => $themes,
	);
}

function component( $id, $name, $current, $offer, $active ) {
	$version   = version_text( $current );
	$available = version_text( $offer['new_version'] ?? null );
	if ( null !== $version && null !== $available && version_compare( $available, $version, '<=' ) ) {
		$available = null;
	}
	$name = trim( wp_strip_all_tags( $name ) );
	$result = array(
		'id'               => (string) $id,
		'name'             => wp_html_excerpt( '' === $name ? (string) $id : $name, 200 ),
		'version'          => $version,
		'availableVersion' => $available,
		'active'           => $active,
	);
	if ( null !== $available ) {
		$result['packageAvailable'] = ! empty( $offer['package'] );
	}
	return $result;
}
