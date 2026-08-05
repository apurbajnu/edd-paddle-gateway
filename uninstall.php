<?php
/**
 * Uninstall handler for EDD Paddle Billing Gateway.
 *
 * Fires only when the user clicks "Delete" on the Plugins screen (not on
 * deactivation). Strategy:
 *   - DELETE: all gateway credentials and configuration stored in EDD's
 *     settings array. Live API keys and webhook secrets must not linger
 *     after the plugin is gone.
 *   - PRESERVE: every payment record, transaction ID, and Paddle customer
 *     mapping. These are tax/accounting history and may still be needed
 *     for refunds, audits, or re-installation.
 *
 * @package EDD_Paddle_Gateway
 */

// Exit if uninstall is not triggered by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Keys this plugin writes into EDD's settings array (via edd_get_option).
 */
$edd_paddle_option_keys = [
	'edd_paddle_mode',
	'edd_paddle_checkout_type',
	'edd_paddle_live_api_key',
	'edd_paddle_live_client_token',
	'edd_paddle_live_webhook_secret',
	'edd_paddle_sandbox_api_key',
	'edd_paddle_sandbox_client_token',
	'edd_paddle_sandbox_webhook_secret',
];

if ( function_exists( 'edd_get_option' ) ) {
	foreach ( $edd_paddle_option_keys as $key ) {
		edd_delete_option( $key );
	}
}
