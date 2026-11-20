<?php

namespace CmdPost\Connector;

defined( 'ABSPATH' ) || exit;

function is_core_patch( $from, $to ) {
	$stable = '/^(0|[1-9][0-9]{0,3})\.(0|[1-9][0-9]{0,3})(?:\.(0|[1-9][0-9]{0,3}))?$/D';
	return is_string( $from ) && is_string( $to )
		&& preg_match( $stable, $from, $source ) && preg_match( $stable, $to, $target )
		&& $source[1] === $target[1] && $source[2] === $target[2]
		&& (int) ( $target[3] ?? 0 ) > (int) ( $source[3] ?? 0 );
}

function official_core_package( $url ) {
	if ( ! is_string( $url ) ) {
		return false;
	}
	$parts = wp_parse_url( $url );
	return 'https' === ( $parts['scheme'] ?? '' ) && 'downloads.wordpress.org' === strtolower( $parts['host'] ?? '' )
		&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['port'] )
		&& ! isset( $parts['query'] ) && ! isset( $parts['fragment'] )
		&& '.zip' === substr( $parts['path'] ?? '', -4 );
}

function available_core_patch( $installed, $cache, $approved = null ) {
	$selected = null;
	foreach ( (array) ( $cache->updates ?? array() ) as $offer ) {
		$target = $offer->current ?? null;
		if ( 'upgrade' !== ( $offer->response ?? '' ) || ! is_core_patch( $installed, $target )
			|| ( null !== $approved && $target !== $approved ) ) {
			continue;
		}
		$packages = $offer->packages ?? (object) array();
		$full = official_core_package( $packages->full ?? null ) ? $packages->full : false;
		$no_content = official_core_package( $packages->no_content ?? null ) ? $packages->no_content : false;
		if ( ( ! $full && ! $no_content ) || ( $selected && ! version_compare( $target, $selected->current, '>' ) ) ) {
			continue;
		}
		$selected = clone $offer;
		$selected->version = $target;
		$selected->packages = (object) array(
			'full' => $full, 'no_content' => $no_content,
			'partial' => false, 'new_bundled' => false, 'rollback' => false,
		);
	}
	return $selected;
}

function core_file_versions( $directory ) {
	$path = trailingslashit( $directory ) . 'wp-includes/version.php';
	clearstatcache( true, $path );
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		return null;
	}
	// Read the declarations as data, without executing the downloaded file.
	$text = file_get_contents( $path, false, null, 0, 16384 );
	if ( false === $text
		|| ! preg_match( '/^\s*\$wp_version\s*=\s*[\x27\x22]([0-9.]+)[\x27\x22]\s*;/m', $text, $version )
		|| ! preg_match( '/^\s*\$wp_db_version\s*=\s*([0-9]+)\s*;/m', $text, $database ) ) {
		return null;
	}
	return array( 'version' => $version[1], 'database' => (int) $database[1] );
}

function core_request_id() {
	static $id;
	$id = $id ?? wp_generate_uuid4();
	return $id;
}

function verify_running_core( $expected ) {
	$disk = core_file_versions( ABSPATH );
	$version = get_bloginfo( 'version' );
	$database_current = $disk && $disk['database'] === (int) get_option( 'db_version' )
		&& $disk['database'] === (int) ( $GLOBALS['wp_db_version'] ?? 0 );
	$verified = $version === $expected && ( $disk['version'] ?? null ) === $expected && $database_current;
	return update_outcome( $verified ? 'updated' : 'core-verification-required', version_text( $version ) )
		+ array( 'databaseCurrent' => (bool) $database_current );
}

function run_core_update( $job, $connection, $grant ) {
	$disk = core_file_versions( ABSPATH );
	$installed = $disk['version'] ?? null;
	if ( $installed !== $job['fromVersion'] || get_bloginfo( 'version' ) !== $installed ) {
		return update_outcome( 'version-changed', $installed );
	}
	if ( ! is_core_patch( $installed, $job['toVersion'] ) ) {
		return update_outcome( 'offer-changed', $installed );
	}
	if ( $disk['database'] !== (int) get_option( 'db_version' ) ) {
		return update_outcome( 'database-upgrade-required', $installed );
	}
	$automatic = new \WP_Automatic_Updater();
	if ( $automatic->is_disabled() || $automatic->is_vcs_checkout( ABSPATH )
		|| 'direct' !== get_filesystem_method( array(), ABSPATH ) ) {
		return update_outcome( 'filesystem-blocked', $installed );
	}

	wp_version_check();
	$offer = available_core_patch( $installed, get_site_transient( 'update_core' ), $job['toVersion'] );
	if ( ! $offer ) {
		return update_outcome( 'offer-changed', $installed );
	}
	if ( ! update_still_allowed( $connection, $grant, 'core' ) ) {
		return update_outcome( 'permission-disabled', $installed );
	}
	require_once ABSPATH . 'wp-admin/includes/update.php';

	$validate = static function ( $working ) use ( $job, $disk, $connection, $grant ) {
		if ( ! update_still_allowed( $connection, $grant, 'core' ) ) {
			return 'permission-disabled';
		}
		if ( core_file_versions( ABSPATH ) !== $disk ) {
			return 'version-changed';
		}
		$package = core_file_versions( trailingslashit( $working ) . 'wordpress' );
		if ( ( $package['version'] ?? null ) !== $job['toVersion'] ) {
			return 'offer-changed';
		}
		if ( $package['database'] !== $disk['database'] || $disk['database'] !== (int) get_option( 'db_version' ) ) {
			return 'database-upgrade-required';
		}
		return null;
	};
	$upgrader = new class( new \Automatic_Upgrader_Skin() ) extends \Core_Upgrader {
		use Package_Diagnostics;
		public $validate_package;
		public $package_accepted = false;
		public $rejection;

		public function unpack_package( $package, $delete_package = true ) {
			$working = parent::unpack_package( $package, $delete_package );
			if ( is_wp_error( $working ) ) {
				return $working;
			}
			$this->rejection = ( $this->validate_package )( $working );
			if ( null !== $this->rejection ) {
				$GLOBALS['wp_filesystem']->delete( $working, true );
				return new \WP_Error( 'cmdpost_core_package_rejected' );
			}
			$this->package_accepted = true;
			return $working;
		}
	};
	$upgrader->validate_package = $validate;
	$result = $upgrader->upgrade( $offer );
	if ( $result === $job['toVersion'] ) {
		return update_outcome( 'updated' );
	}
	if ( $upgrader->package_accepted ) {
		// Core files may have been replaced. Preserve the queue lock for review.
		return update_outcome( 'core-verification-required' ) + array( 'wait_for_rollback' => true );
	}
	$error = is_wp_error( $result ) ? $result->get_error_code() : '';
	return update_outcome( $upgrader->rejection ?? 'upgrade-failed', $installed, updater_failure( $upgrader, $error ) );
}
