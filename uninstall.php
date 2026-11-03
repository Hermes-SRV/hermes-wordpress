<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'hermes_connector' );
delete_option( 'hermes_connector_api_token' );
delete_transient( 'hermes_connector_inboxes' );
delete_transient( 'hermes_connector_release' );
