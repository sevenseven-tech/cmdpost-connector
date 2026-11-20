<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'cmdpost_connector' );
delete_option( 'cmdpost_connector_last_report' );
delete_option( 'cmdpost_connector_update_permission' );
delete_option( 'cmdpost_connector_theme_update_permission' );
delete_option( 'cmdpost_connector_core_update_permission' );
delete_option( 'cmdpost_connector_update_result' );
wp_clear_scheduled_hook( 'cmdpost_connector_report' );
wp_clear_scheduled_hook( 'cmdpost_connector_check_updates' );
