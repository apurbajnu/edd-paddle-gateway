<?php
/**
 * Easy Digital Downloads - Paddle Webhook Handler
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'edd_paddle_verify_webhook_signature' ) ) {
    /**
     * Verify Paddle Billing Webhook Signature
     *
     * @param string $raw_body         Raw HTTP POST request body.
     * @param string $signature_header Value of the Paddle-Signature header.
     * @param string $secret           The webhook secret key configured in settings.
     * @return bool
     */
    function edd_paddle_verify_webhook_signature( $raw_body, $signature_header, $secret ) {
        if ( empty( $signature_header ) || empty( $secret ) ) {
            return false;
        }

        $parts = explode( ';', $signature_header );
        $ts    = '';
        $h1    = '';

        foreach ( $parts as $part ) {
            $kv = explode( '=', $part, 2 );
            if ( count( $kv ) === 2 ) {
                $key   = trim( $kv[0] );
                $value = trim( $kv[1] );
                if ( 'ts' === $key ) {
                    $ts = $value;
                } elseif ( 'h1' === $key ) {
                    $h1 = $value;
                }
            }
        }

        if ( empty( $ts ) || empty( $h1 ) ) {
            return false;
        }

        // Allow a clock drift window of 10 minutes (600 seconds)
        if ( abs( time() - (int) $ts ) > 600 ) {
            return false;
        }

        $payload  = $ts . ':' . $raw_body;
        $computed = hash_hmac( 'sha256', $payload, $secret );

        $is_valid = hash_equals( $h1, $computed );

        if ( ! $is_valid ) {
            edd_paddle_log( 'Webhook signature verification failed.' );
            // Don't log HMAC values at operational level — they're secrets
            // even though they're computed locally. Verbose-only.
            edd_paddle_log_verbose( 'Webhook signature mismatch. Computed: ' . $computed . ' | Received: ' . $h1 );
        }

        return $is_valid;
    }
}


if ( ! function_exists( 'edd_paddle_process_webhook_payload' ) ) {
    /**
     * Process verified webhook payload
     *
     * @param string $raw_body Raw JSON body of the webhook.
     * @return void
     */
    function edd_paddle_process_webhook_payload( $raw_body ) {
        $payload = json_decode( $raw_body, true );

        if ( empty( $payload ) || empty( $payload['event_type'] ) ) {
            edd_paddle_log( 'Webhook: Empty or invalid payload JSON.' );
            return;
        }

        edd_paddle_log( 'Webhook ======================================' );
        edd_paddle_log( 'Webhook Processing event: ' . $payload['event_type'] );
        // Raw body includes customer email + name + potentially address — verbose only, redacted.
        edd_paddle_log_verbose( 'Webhook Raw payload: ' . edd_paddle_redact_pii( $raw_body ) );

        // Events free handles inline + fires the edd_paddle_webhook_event action for.
        // Pro add-on extends this list (subscription.canceled, adjustment.*).
        $actionable_events = apply_filters(
            'edd_paddle_webhook_actionable_events',
            [
                'transaction.completed',
                'transaction.paid',
                'transaction.updated',
            ]
        );

        if ( ! in_array( $payload['event_type'], $actionable_events, true ) ) {
            // Log the actual event_type so buyers can grep debug.log when
            // asking "why isn't subscription.updated / transaction.billed
            // / payment_failed syncing?". Most are Paddle Billing lifecycle
            // events we recognize but haven't wired up yet — not errors.
            edd_paddle_log( sprintf( 'Webhook: Event "%s" not in actionable list, skipping.', $payload['event_type'] ) );
            return;
        }

        $data           = $payload['data'];
        $event_type     = $payload['event_type'];

        $transaction_id = $data['id'];
        $status         = isset( $data['status'] ) ? $data['status'] : '';

        edd_paddle_log( 'Webhook: Transaction ID: ' . $transaction_id . ' | Status: ' . $status );

        // If it's an update, only proceed if the status is now completed
        if ( 'transaction.updated' === $event_type && 'completed' !== $status ) {
            edd_paddle_log( 'Webhook: Update event but status not completed, skipping.' );
            // Fire action so pro can still react (e.g., to a failed renewal transaction).
            do_action( 'edd_paddle_webhook_event', $event_type, $data, 0 );
            return;
        }

        $payment_id = isset( $data['custom_data']['edd_payment_id'] ) ? (int) $data['custom_data']['edd_payment_id'] : 0;

        if ( ! $payment_id ) {
            edd_paddle_log( 'Webhook: Missing edd_payment_id in custom_data. Transaction: ' . $transaction_id );
            // custom_data is buyer-extensible — could include PII. Verbose + redacted.
            edd_paddle_log_verbose( 'Webhook: Custom data was: ' . print_r( edd_paddle_redact_pii( isset( $data['custom_data'] ) ? $data['custom_data'] : 'not set' ), true ) );
            return;
        }

        edd_paddle_log( 'Webhook: Found payment_id: ' . $payment_id . ' | transaction_id: ' . $transaction_id );

        // Mark webhook as received (for polling to detect)
        update_post_meta( $payment_id, '_edd_paddle_webhook_received', current_time( 'mysql' ) );
        edd_paddle_log( 'Webhook: Marked webhook received for payment_id: ' . $payment_id );

        if ( ! function_exists( 'edd_get_payment' ) ) {
            edd_paddle_log( 'Webhook: edd_get_payment function not found.' );
            return;
        }

        $payment = edd_get_payment( $payment_id );
        if ( ! $payment ) {
            edd_paddle_log( 'Webhook: Payment record not found in EDD: ' . $payment_id );
            return;
        }

        // Store Paddle Customer ID
        $customer_id = isset( $data['customer_id'] ) ? $data['customer_id'] : '';
        if ( $customer_id ) {
            update_post_meta( $payment_id, '_edd_paddle_customer_id', $customer_id );

            $edd_customer_id = $payment->customer_id;
            if ( $edd_customer_id && function_exists( 'update_metadata' ) ) {
                update_metadata( 'edd_customer', $edd_customer_id, '_edd_paddle_customer_id', $customer_id );
            }
        }

        // Sync total from Paddle (Paddle uses cents, EDD uses decimals)
        $paddle_total = isset( $data['details']['totals']['total'] ) ? (float) $data['details']['totals']['total'] / 100 : 0;

        if ( $paddle_total > 0 && (float) $payment->total <= 0 ) {
            edd_update_payment_meta( $payment_id, '_edd_payment_total', $paddle_total );
            edd_paddle_log( 'Webhook: Updated payment total to: ' . $paddle_total );
        }

        edd_paddle_log( 'Webhook: Current payment status: ' . $payment->status );

        // Only update if currently pending
        if ( 'pending' === $payment->status || 'processing' === $payment->status ) {
            edd_update_payment_status( $payment_id, 'complete' );

            if ( function_exists( 'edd_set_payment_transaction_id' ) ) {
                edd_set_payment_transaction_id( $payment_id, $transaction_id );
            } else {
                update_post_meta( $payment_id, '_edd_payment_transaction_id', $transaction_id );
            }

            edd_insert_payment_note( $payment_id, sprintf( __( 'Paddle transaction completed. ID: %s', 'edd-paddle-gateway' ), $transaction_id ) );
            edd_paddle_log( 'Webhook: *** PAYMENT MARKED AS COMPLETE: ' . $payment_id . ' ***' );
        } else {
            edd_paddle_log( 'Webhook: Payment already processed or not pending. Current status: ' . $payment->status . ' | ID: ' . $payment_id );
        }

        // Fire action so pro add-on can handle its own events (subscription.*,
        // adjustment.*) using the same verified payload. Free's switch fell
        // through for those events; pro hooks this action.
        do_action( 'edd_paddle_webhook_event', $event_type, $data, $payment_id );

        edd_paddle_log( 'Webhook ======================================' );
    }
}


if ( ! function_exists( 'edd_paddle_webhook_listener' ) ) {
    /**
     * Listen for Paddle Webhook POST requests
     *
     * @return void
     */
    function edd_paddle_webhook_listener() {
        if ( ! isset( $_GET['edd-listener'] ) || 'paddle' !== $_GET['edd-listener'] ) {
            return;
        }

        edd_paddle_log( 'Webhook: Request received.' );

        $signature_header = isset( $_SERVER['HTTP_PADDLE_SIGNATURE'] ) ? $_SERVER['HTTP_PADDLE_SIGNATURE'] : '';
        $raw_body         = file_get_contents( 'php://input' );
        
        $mode    = function_exists( 'edd_get_option' ) ? edd_get_option( 'edd_paddle_mode', 'sandbox' ) : 'sandbox';
        $is_live = ( 'live' === $mode );
        
        if ( $is_live ) {
            $secret = function_exists( 'edd_get_option' ) ? edd_get_option( 'edd_paddle_live_webhook_secret', '' ) : '';
        } else {
            $secret = function_exists( 'edd_get_option' ) ? edd_get_option( 'edd_paddle_sandbox_webhook_secret', '' ) : '';
        }

        if ( empty( $raw_body ) ) {
            edd_paddle_log( 'Webhook: Empty request body.' );
            status_header( 400 );
            echo 'Empty body';
            exit;
        }

        if ( ! edd_paddle_verify_webhook_signature( $raw_body, $signature_header, $secret ) ) {
            edd_paddle_log( 'Webhook: Signature verification failed for request.' );
            status_header( 400 );
            echo 'Invalid signature';
            if ( ! defined( 'PHPUNIT_COMPOSER_INSTALL' ) ) {
                exit;
            }
            return;
        }

        edd_paddle_process_webhook_payload( $raw_body );

        status_header( 200 );
        echo 'OK';
        if ( ! defined( 'PHPUNIT_COMPOSER_INSTALL' ) ) {
            exit;
        }
    }
}

add_action( 'init', 'edd_paddle_webhook_listener' );
