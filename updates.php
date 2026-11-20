<?php

namespace CmdPost\Connector;

defined( 'ABSPATH' ) || exit;

const UPDATE_PERMISSION_OPTION = 'cmdpost_connector_update_permission';
const THEME_UPDATE_PERMISSION_OPTION = 'cmdpost_connector_theme_update_permission';
const CORE_UPDATE_PERMISSION_OPTION = 'cmdpost_connector_core_update_permission';
const UPDATE_RESULT_OPTION = 'cmdpost_connector_update_result';
const UPDATE_HOOK = 'cmdpost_connector_check_updates';

require_once __DIR__ . '/core-updates.php';

add_filter( 'cron_schedules', __NAMESPACE__ . '\\add_update_interval' );
add_action( UPDATE_HOOK, __NAMESPACE__ . '\\check_for_update' );
add_action( REPORT_HOOK, __NAMESPACE__ . '\\retry_pending_update_result', 9 );

function add_update_interval( $schedules ) {
	$schedules['cmdpost_five_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every five minutes for CmdPost', 'cmdpost-connector' ),
	);
	return $schedules;
}

function schedule_update_check() {
	return wp_next_scheduled( UPDATE_HOOK )
		|| wp_schedule_event( time() + MINUTE_IN_SECONDS, 'cmdpost_five_minutes', UPDATE_HOOK );
}

function update_permission_option( $kind ) {
	return array( 'theme' => THEME_UPDATE_PERMISSION_OPTION, 'core' => CORE_UPDATE_PERMISSION_OPTION )[ $kind ] ?? UPDATE_PERMISSION_OPTION;
}

function update_grant( $connection, $kind = 'plugin' ) {
	$saved = get_option( update_permission_option( $kind ), array() );
	if ( empty( $connection['token'] ) || ! isset( $saved['token_hash'], $saved['grant'] )
		|| ! hash_equals( $saved['token_hash'], hash( 'sha256', $connection['token'] ) ) ) {
		return null;
	}
	return $saved['grant'];
}

function has_update_grant( $connection ) {
	return null !== update_grant( $connection ) || null !== update_grant( $connection, 'theme' ) || null !== update_grant( $connection, 'core' );
}

function enable_remote_updates( $connection, $kind = 'plugin' ) {
	if ( empty( $connection['token'] ) ) {
		return 'disconnected';
	}
	$capability = 'core' === $kind ? 'update_core' : "update_{$kind}s";
	if ( ! current_user_can( $capability ) ) {
		return 'updates-blocked';
	}
	if ( ! schedule_update_check() ) {
		return 'updates-schedule';
	}

	$current_grant = update_grant( $connection, $kind );
	$grant = $current_grant ?? str_replace( '-', '', wp_generate_uuid4() );
	$permission = array( 'grant' => $grant, 'token_hash' => hash( 'sha256', $connection['token'] ) );
	$route = 'plugin' === $kind ? 'permission' : "$kind-permission";
	$response = update_request( $connection, $route, array( 'grant' => $grant ) );
	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		if ( ! has_update_grant( $connection ) ) {
			wp_clear_scheduled_hook( UPDATE_HOOK );
		}
		return 'updates-enable-failed';
	}
	if ( null === $current_grant && ! update_option( update_permission_option( $kind ), $permission, false ) ) {
		disable_remote_updates( $connection, $kind );
		return 'updates-enable-failed';
	}
	return 'updates-enabled';
}

function disable_remote_updates( $connection, $kind = 'plugin' ) {
	delete_option( update_permission_option( $kind ) );
	if ( ! has_update_grant( $connection ) ) {
		wp_clear_scheduled_hook( UPDATE_HOOK );
	}
	if ( empty( $connection['token'] ) ) {
		return 'updates-disabled';
	}
	$route = 'plugin' === $kind ? 'permission' : "$kind-permission";
	$response = update_request( $connection, $route, array( 'grant' => null ) );
	return in_array( wp_remote_retrieve_response_code( $response ), array( 200, 401 ), true )
		? 'updates-disabled' : 'updates-disable-unsynced';
}

function update_request( $connection, $action, $data ) {
	$data['siteUrl'] = site_address();
	return request( $connection['endpoint'], 'updates/' . $action, $data, $connection['token'] );
}

function retry_pending_update_result() {
	if ( ! get_option( UPDATE_RESULT_OPTION ) ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! \WP_Upgrader::create_lock( 'auto_updater' ) ) {
		return;
	}
	try {
		send_pending_update_result();
	} finally {
		\WP_Upgrader::release_lock( 'auto_updater' );
	}
}

function send_pending_update_result() {
	wp_cache_delete( UPDATE_RESULT_OPTION, 'options' );
	$pending = get_option( UPDATE_RESULT_OPTION, array() );
	if ( empty( $pending ) ) {
		return true;
	}
	$connection = get_option( OPTION, array() );
	if ( empty( $connection['token'] ) || ! hash_equals( $pending['token_hash'], hash( 'sha256', $connection['token'] ) ) ) {
		delete_option( UPDATE_RESULT_OPTION );
		return true;
	}

	$result = $pending['result'];
	$expected_core = $pending['verify_core'] ?? null;
	if ( null !== $expected_core ) {
		if ( ( $pending['request_id'] ?? '' ) === core_request_id() ) {
			return false;
		}
		$result = verify_running_core( $expected_core ) + array( 'id' => $result['id'] );
	}
	$response = update_request( $connection, 'result', $result );
	$status = wp_remote_retrieve_response_code( $response );
	if ( ! in_array( $status, array( 200, 401, 403 ), true ) ) {
		return false;
	}
	// CmdPost treats retries as idempotent. A closed/reviewed job is also
	// safe to discard locally, even if the original response was lost.
	if ( get_option( UPDATE_RESULT_OPTION ) === $pending ) {
		delete_option( UPDATE_RESULT_OPTION );
	}
	if ( 200 === $status && null !== $expected_core && 'updated' === $result['result'] ) {
		wp_version_check();
		send_report();
	}
	return true;
}

function check_for_update() {
	if ( is_multisite() || ! wp_doing_cron() ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';

	// Share WordPress’s background updater lock. Claim only after we have it.
	if ( ! \WP_Upgrader::create_lock( 'auto_updater' ) ) {
		return;
	}
	try {
		if ( ! send_pending_update_result() ) {
			return;
		}
		$connection = get_option( OPTION, array() );
		$grants = array_filter(
			array(
				'grant' => update_grant( $connection ), 'themeGrant' => update_grant( $connection, 'theme' ),
				'coreGrant' => update_grant( $connection, 'core' ),
			),
			static function ( $grant ) { return null !== $grant; }
		);
		if ( ! $grants ) {
			return;
		}

		$response = update_request( $connection, 'claim', $grants );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$job = $data['job'] ?? null;
		if ( ! valid_update_job( $job ) ) {
			return;
		}

		// CmdPost never delivers a claimed job again. Only the result is retried.
		$kind = isset( $job['coreId'] ) ? 'core' : ( isset( $job['themeId'] ) ? 'theme' : 'plugin' );
		$grant = $grants[ 'plugin' === $kind ? 'grant' : $kind . 'Grant' ] ?? null;
		$result = null === $grant ? update_outcome( 'permission-disabled' )
			: ( 'core' === $kind ? run_core_update( $job, $connection, $grant ) : run_component_update( $job, $connection, $grant, $kind ) );
		$verify_core = 'core' === $kind && 'updated' === $result['result'];
		$wait = $verify_core || ! empty( $result['wait_for_rollback'] );
		unset( $result['wait_for_rollback'] );
		$result['id'] = $job['id'];
		$pending = array( 'result' => $result, 'token_hash' => hash( 'sha256', $connection['token'] ) );
		if ( $verify_core ) {
			$pending['verify_core'] = $job['toVersion'];
			$pending['request_id'] = core_request_id();
		}
		$stored = update_option( UPDATE_RESULT_OPTION, $pending, false );
		if ( ! $wait ) {
			if ( $stored ) {
				send_pending_update_result();
			} else {
				update_request( $connection, 'result', $result );
			}
		}
		if ( 'core' !== $kind && 'updated' === $result['result'] ) {
			refresh_component_update_offers( $kind );
			send_report();
		}
	} finally {
		\WP_Upgrader::release_lock( 'auto_updater' );
	}
}

function valid_update_job( $job ) {
	if ( ! is_array( $job ) ) {
		return false;
	}
	$target = $job['coreId'] ?? $job['themeId'] ?? $job['pluginId'] ?? null;
	$target_count = (int) isset( $job['pluginId'] ) + (int) isset( $job['themeId'] ) + (int) isset( $job['coreId'] );
	return is_string( $job['id'] ?? null )
		&& 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z#[0-9a-f]{24}$/D', $job['id'] )
		&& 1 === $target_count && is_string( $target ) && strlen( $target ) <= 1000
		&& ( ! isset( $job['coreId'] ) || 'wordpress' === $target )
		&& is_string( $job['fromVersion'] ?? null ) && is_string( $job['toVersion'] ?? null )
		&& '' !== $job['fromVersion'] && '' !== $job['toVersion'];
}

function read_component_header( $kind, $path ) {
	clearstatcache( true, $path );
	if ( ! is_file( $path ) ) {
		return array();
	}
	if ( 'plugin' === $kind ) {
		return get_plugin_data( $path, false, false );
	}
	return get_file_data( $path, array(
		'Name'        => 'Theme Name',
		'Version'     => 'Version',
		'Template'    => 'Template',
		'RequiresPHP' => 'Requires PHP',
		'RequiresWP'  => 'Requires at least',
	), 'theme' );
}

function refresh_component_update_offers( $kind ) {
	if ( 'theme' === $kind ) {
		wp_update_themes();
	} else {
		wp_update_plugins();
	}
}

function update_installation( $kind, $id ) {
	if ( 0 !== validate_file( $id ) ) {
		return null;
	}
	if ( 'theme' === $kind ) {
		if ( '' === $id || '.' === $id || '..' === $id || false !== strpbrk( $id, '/\\' ) || ! wp_get_theme( $id )->exists() ) {
			return null;
		}
		return array(
			'root' => get_theme_root( $id ), 'folder' => $id, 'entry' => 'style.css',
			'active' => in_array( $id, array( get_template(), get_stylesheet() ), true ),
		);
	}
	$parts = explode( '/', $id, 2 );
	if ( count( $parts ) !== 2 || plugin_basename( __DIR__ . '/cmdpost-connector.php' ) === $id || ! isset( get_plugins()[ $id ] ) ) {
		return null;
	}
	return array( 'root' => WP_PLUGIN_DIR, 'folder' => $parts[0], 'entry' => $parts[1], 'active' => is_plugin_active( $id ) );
}

trait Package_Diagnostics {
	public $download_failure;

	public function download_package( $package, $check_signatures = false, $hook_extra = array() ) {
		$this->download_failure = null;
		$status = null;
		$observe_response = static function ( $response, $args, $url ) use ( $package, &$status ) {
			if ( $url === $package && ! empty( $args['stream'] ) ) {
				$status = (int) wp_remote_retrieve_response_code( $response );
			}
			return $response;
		};
		add_filter( 'http_response', $observe_response, PHP_INT_MAX, 3 );
		try {
			$result = parent::download_package( $package, $check_signatures, $hook_extra );
		} finally {
			remove_filter( 'http_response', $observe_response, PHP_INT_MAX );
		}
		if ( is_wp_error( $result ) ) {
			$this->download_failure = 'no_package' === $result->get_error_code() ? 'package-unavailable'
				: ( in_array( $status, array( 401, 403 ), true ) ? 'download-denied' : 'download-failed' );
		}
		return $result;
	}
}

function updater_failure( $upgrader, $error, $approved_package = false ) {
	$failures = array(
		'cmdpost_unapproved_package'         => 'package-mismatch',
		'cmdpost_incompatible_package'       => 'incompatible-package',
		'incompatible_php_required_version'  => 'incompatible-package',
		'incompatible_wp_required_version'   => 'incompatible-package',
		'incompatible_archive'               => 'unpack-failed',
		'incompatible_archive_empty'         => 'unpack-failed',
		'incompatible_archive_no_plugins'    => 'package-mismatch',
		'incompatible_archive_theme_no_style' => 'package-mismatch',
		'incompatible_archive_theme_no_name' => 'package-mismatch',
		'incompatible_archive_theme_no_index' => 'package-mismatch',
		'empty_archive_pclzip'                => 'unpack-failed',
		'stat_failed_ziparchive'              => 'unpack-failed',
		'extract_failed_ziparchive'           => 'unpack-failed',
		'no_package'                         => 'package-unavailable',
		'download_failed'                    => 'download-failed',
	);
	return $upgrader->download_failure ?? $failures[ $error ] ?? ( $approved_package ? 'install-failed' : null );
}

function component_upgrader( $kind, $skin ) {
	// Maintenance begins only after the downloaded package passes approval.
	if ( 'theme' === $kind ) {
		return new class( $skin ) extends \Theme_Upgrader {
			use Package_Diagnostics;
			public function current_before( $response, $theme ) { return $response; }
			public function current_after( $response, $theme ) { return $response; }
		};
	}
	return new class( $skin ) extends \Plugin_Upgrader {
		use Package_Diagnostics;
		public function active_before( $response, $plugin ) { return $response; }
		public function active_after( $response, $plugin ) { return $response; }
	};
}

function run_component_update( $job, $connection, $grant, $kind ) {
	$id = $job[ 'theme' === $kind ? 'themeId' : 'pluginId' ];
	$component = update_installation( $kind, $id );
	if ( null === $component ) {
		return update_outcome( 'version-changed' );
	}
	$installed_dir = trailingslashit( $component['root'] ) . $component['folder'];
	$installed_file = trailingslashit( $installed_dir ) . $component['entry'];
	$header = read_component_header( $kind, $installed_file );
	$installed = version_text( $header['Version'] ?? null );
	if ( $installed !== $job['fromVersion'] ) {
		return update_outcome( 'version-changed', $installed );
	}
	$automatic = new \WP_Automatic_Updater();
	if ( $automatic->is_disabled() || 'direct' !== get_filesystem_method( array(), $component['root'] ) || is_link( $installed_dir ) ) {
		return update_outcome( 'filesystem-blocked', $installed );
	}

	refresh_component_update_offers( $kind );
	$updates = get_site_transient( 'theme' === $kind ? 'update_themes' : 'update_plugins' );
	$offer = (array) ( $updates->response[ $id ] ?? array() );
	if ( version_text( $offer['new_version'] ?? null ) !== $job['toVersion']
		|| ! version_compare( $job['toVersion'], $installed, '>' ) ) {
		return update_outcome( 'offer-changed', $installed );
	}
	if ( empty( $offer['package'] ) ) {
		return update_outcome( 'upgrade-failed', $installed, 'package-unavailable' );
	}
	if ( ! update_still_allowed( $connection, $grant, $kind ) ) {
		return update_outcome( 'permission-disabled', $installed );
	}

	$skin = new \Automatic_Upgrader_Skin();
	$upgrader = component_upgrader( $kind, $skin );
	$approved_package = false;
	$maintenance = false;
	$validate_package = static function ( $source, $remote_source, $instance, $extra ) use ( $upgrader, $id, $kind, $component, $job, $installed_file, $connection, $grant, &$approved_package, &$maintenance ) {
		if ( $instance !== $upgrader || ( $extra[ $kind ] ?? '' ) !== $id || is_wp_error( $source ) ) {
			return $source;
		}
		if ( ! update_still_allowed( $connection, $grant, $kind ) ) {
			return new \WP_Error( 'cmdpost_permission_disabled' );
		}
		$current = read_component_header( $kind, $installed_file );
		if ( version_text( $current['Version'] ?? null ) !== $job['fromVersion'] ) {
			return new \WP_Error( 'cmdpost_version_changed' );
		}
		$directory = untrailingslashit( $source );
		$path = trailingslashit( $directory ) . $component['entry'];
		$real_dir = realpath( $directory );
		$real_file = realpath( $path );
		if ( basename( $directory ) !== $component['folder'] || false === $real_dir || false === $real_file
			|| 0 !== strpos( wp_normalize_path( $real_file ), trailingslashit( wp_normalize_path( $real_dir ) ) ) ) {
			return new \WP_Error( 'cmdpost_unapproved_package' );
		}
		$header = read_component_header( $kind, $path );
		if ( empty( $header['Name'] ) || version_text( $header['Version'] ?? null ) !== $job['toVersion']
			|| ( $header['Template'] ?? '' ) !== ( $current['Template'] ?? '' ) ) {
			return new \WP_Error( 'cmdpost_unapproved_package' );
		}
		if ( ! is_php_version_compatible( $header['RequiresPHP'] ?? '' ) || ! is_wp_version_compatible( $header['RequiresWP'] ?? '' ) ) {
			return new \WP_Error( 'cmdpost_incompatible_package' );
		}
		$approved_package = true;
		if ( $component['active'] && ! file_exists( ABSPATH . '.maintenance' ) ) {
			$upgrader->maintenance_mode( true );
			$maintenance = true;
			add_action( 'shutdown', static function () use ( $upgrader, &$maintenance ) {
				if ( $maintenance ) {
					$upgrader->maintenance_mode( false );
				}
			}, 11 );
		}
		return $source;
	};

	// WordPress calls this before moving the installed folder to its temporary backup.
	add_filter( 'upgrader_source_selection', $validate_package, PHP_INT_MAX, 4 );
	try {
		$updated = $upgrader->upgrade( $id );
	} finally {
		remove_filter( 'upgrader_source_selection', $validate_package, PHP_INT_MAX );
	}

	$observed = version_text( read_component_header( $kind, $installed_file )['Version'] ?? null );
	if ( true === $updated ) {
		if ( $maintenance ) {
			$upgrader->maintenance_mode( false );
			$maintenance = false;
		}
		return update_outcome( $observed === $job['toVersion'] ? 'updated' : 'version-unconfirmed', $observed );
	}
	$errors = array(
		'cmdpost_unapproved_package'   => 'offer-changed',
		'cmdpost_permission_disabled' => 'permission-disabled',
		'cmdpost_version_changed'     => 'version-changed',
	);
	// Failures before installation can appear only in the updater skin.
	$failure = is_wp_error( $updated ) ? $updated : $skin->result;
	$error = is_wp_error( $failure ) ? $failure->get_error_code() : '';
	$outcome = update_outcome( $errors[ $error ] ?? 'upgrade-failed', $approved_package ? null : $observed,
		updater_failure( $upgrader, $error, $approved_package ) );
	if ( $approved_package ) {
		// The native upgrader may still restore its backup on shutdown. Wait for
		// a later request before CmdPost checks external reachability.
		$outcome['wait_for_rollback'] = true;
	}
	return $outcome;
}

function update_still_allowed( $connection, $grant, $kind ) {
	// Refresh the non-autoloaded options in a long-running worker so an
	// administrator can revoke permission while a package downloads.
	wp_cache_delete( update_permission_option( $kind ), 'options' );
	wp_cache_delete( OPTION, 'options' );
	$current = get_option( OPTION, array() );
	return ( $current['token'] ?? null ) === $connection['token'] && update_grant( $current, $kind ) === $grant;
}

function update_outcome( $result, $installed = null, $failure = null ) {
	$outcome = array( 'result' => $result, 'installedVersion' => $installed );
	if ( null !== $failure ) {
		$outcome['failure'] = $failure;
	}
	return $outcome;
}
