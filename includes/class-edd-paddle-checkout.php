<?php
/**
 * Easy Digital Downloads - Paddle Checkout Flow
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Construct transaction payload for Paddle Billing v2
 *
 * @param array $purchase_data Purchase details from EDD.
 * @param int   $payment_id    The pending EDD payment ID.
 * @return array
 */
function edd_paddle_build_transaction_payload( $purchase_data, $payment_id ) {
    $items = [];

    $prices_to_sync = [];

    foreach ( $purchase_data['downloads'] as $download ) {
        $download_id = $download['id'];
        $price_id    = '';

        if ( function_exists( 'edd_has_variable_prices' ) && edd_has_variable_prices( $download_id ) ) {
            $price_options = get_post_meta( $download_id, 'edd_paddle_variable_prices', true );
            
            // EDD 3.0+ stores the variable price ID in $download['options']['price_id']
            $selected_price_id = isset( $download['options']['price_id'] ) ? $download['options']['price_id'] : '';
            
            if ( empty( $selected_price_id ) ) {
                $selected_price_id = get_post_meta( $download_id, '_edd_default_price_id', true );
            }

            if ( is_array( $price_options ) && isset( $price_options[ $selected_price_id ] ) ) {
                $price_id = $price_options[ $selected_price_id ];
            }
        } else {
            $price_id = get_post_meta( $download_id, 'edd_paddle_price_id', true );
        }

        if ( ! empty( $price_id ) ) {
            $quantity = isset( $download['quantity'] ) ? absint( $download['quantity'] ) : 1;
            
            if ( isset( $prices_to_sync[ $price_id ] ) ) {
                $prices_to_sync[ $price_id ] += $quantity;
            } else {
                $prices_to_sync[ $price_id ] = $quantity;
            }
        }
    }

    foreach ( $prices_to_sync as $price_id => $quantity ) {
        $items[] = [
            'price_id' => $price_id,
            'quantity' => $quantity,
        ];
    }

    $email = isset( $purchase_data['user_info']['email'] ) ? $purchase_data['user_info']['email'] : $purchase_data['user_email'];

    // Build return URL in wpfront style - uses EDD's native template system
    $return_url = add_query_arg( [
        'payment-confirmation' => 'paddle',
        'payment-id'           => $payment_id,
    ], function_exists( 'edd_get_success_page_uri' ) ? edd_get_success_page_uri() : home_url() );

    $payload = [
        'items'           => $items,
        'collection_mode' => 'automatic',
        'custom_data'     => [
            'edd_payment_id' => $payment_id,
        ],
        'customer' => [
            'email' => $email,
            'name'  => trim( $purchase_data['user_info']['first_name'] . ' ' . $purchase_data['user_info']['last_name'] ),
        ],
        'checkout' => [
            'return_url' => $return_url,
        ],
    ];

    // Handle Discounts
    if ( ! empty( $purchase_data['user_info']['discount'] ) && 'none' !== $purchase_data['user_info']['discount'] ) {
        $discount_code = $purchase_data['user_info']['discount'];
        if ( function_exists( 'edd_get_discount_id_by_code' ) ) {
            $discount_id = edd_get_discount_id_by_code( $discount_code );

            if ( $discount_id ) {
                $paddle_discount_id = get_post_meta( $discount_id, 'edd_paddle_discount_id', true );

                if ( empty( $paddle_discount_id ) && function_exists( 'edd_paddle_sync_discount' ) ) {
                    $paddle_discount_id = edd_paddle_sync_discount( $discount_id );
                }

                if ( $paddle_discount_id ) {
                    $payload['discounts'] = [
                        [
                            'id' => $paddle_discount_id,
                        ],
                    ];
                }
            }
        }
    }

    return $payload;
}

/**
 * Sync an EDD Discount to Paddle
 *
 * @param int $discount_id The EDD discount ID.
 * @return string|bool Paddle Discount ID or false on failure.
 */
function edd_paddle_sync_discount( $discount_id ) {
    if ( ! class_exists( 'EDD_Discount' ) ) {
        return false;
    }

    $discount = new EDD_Discount( $discount_id );
    $api      = edd_paddle_get_api();

    $amount = $discount->get_amount();
    $type   = $discount->get_type(); // percentage or flat

    $paddle_body = [
        'name'    => $discount->get_name(),
        'code'    => $discount->get_code(),
        'type'    => ( 'flat' === $type ) ? 'flat' : 'percentage',
        'amount'  => ( 'flat' === $type ) ? number_format( (float) $amount, 2, '', '' ) : (string) $amount,
        'enabled' => true,
    ];

    if ( 'flat' === $type ) {
        $paddle_body['currency_code'] = function_exists( 'edd_get_currency' ) ? edd_get_currency() : 'USD';
    }

    // Check if this discount was already synced to Paddle
    $existing_paddle_id = get_post_meta( $discount_id, 'edd_paddle_discount_id', true );
    $last_synced_hash   = get_post_meta( $discount_id, '_edd_paddle_discount_hash', true );
    $current_hash       = md5( serialize( $paddle_body ) );

    if ( $existing_paddle_id && $last_synced_hash === $current_hash ) {
        // Already synced and unchanged
        return $existing_paddle_id;
    }

    if ( $existing_paddle_id ) {
        // Discount was edited — update in Paddle
        $response = $api->update_discount( $existing_paddle_id, $paddle_body );

        if ( is_wp_error( $response ) ) {
            edd_paddle_log( 'Discount update failed: ' . $discount_id . ' | Error: ' . $response->get_error_message() );
            // Fall through to try creating a new one
        } else {
            update_post_meta( $discount_id, '_edd_paddle_discount_hash', $current_hash );
            return $existing_paddle_id;
        }
    }

    // Create new discount in Paddle
    $response = $api->create_discount( $paddle_body );

    if ( is_wp_error( $response ) ) {
        edd_paddle_log( 'Discount sync failed: ' . $discount_id . ' | Error: ' . $response->get_error_message() );
        return false;
    }

    $paddle_id = $response['data']['id'];
    update_post_meta( $discount_id, 'edd_paddle_discount_id', $paddle_id );
    update_post_meta( $discount_id, '_edd_paddle_discount_hash', $current_hash );

    return $paddle_id;
}

/**
 * Processes purchase for Redirect Mode
 *
 * @param array $purchase_data Purchase data.
 * @return void
 */
function edd_paddle_process_purchase( $purchase_data ) {

    // If this is an AJAX request, we let the JS handler deal with it.
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
        return;
    }

    edd_paddle_log( '======================================' );
    edd_paddle_log( 'Starting purchase process for Redirect Mode' );

    // Collect payment details
    $payment_data = [
        'total'        => $purchase_data['price'],
        'date'         => date( 'Y-m-d H:i:s' ),
        'user_email'   => isset( $purchase_data['user_email'] ) ? $purchase_data['user_email'] : $purchase_data['user_info']['email'],
        'purchase_key' => isset( $purchase_data['purchase_key'] ) ? $purchase_data['purchase_key'] : '',
        'currency'     => function_exists( 'edd_get_currency' ) ? edd_get_currency() : 'USD',
        'downloads'    => $purchase_data['downloads'],
        'user_info'    => $purchase_data['user_info'],
        'status'       => 'pending',
        'gateway'      => 'paddle',
    ];

    $payment_id = edd_insert_payment( $payment_data );

    if ( ! $payment_id ) {
        edd_paddle_log( 'Failed to insert payment.' );
        if ( function_exists( 'edd_send_back_to_checkout' ) ) {
            edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['gateway'] );
        }
        return;
    }

    edd_paddle_log( 'Payment created with ID: ' . $payment_id . ' | Key: ' . $payment_data['purchase_key'] );

    // Build the payload
    $payload = edd_paddle_build_transaction_payload( $purchase_data, $payment_id );

    if ( empty( $payload['items'] ) ) {
        edd_paddle_log( 'Payload has no items - products not synced to Paddle.' );
        if ( function_exists( 'edd_record_gateway_error' ) ) {
            edd_record_gateway_error( __( 'Paddle Error', 'edd-paddle-gateway' ), __( 'Your products are not yet synced to Paddle. Please contact support.', 'edd-paddle-gateway' ) );
        }
        edd_update_payment_status( $payment_id, 'failed' );
        if ( function_exists( 'edd_send_back_to_checkout' ) ) {
            edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['gateway'] );
        }
        return;
    }

    edd_paddle_log_verbose( 'Payload built: ' . print_r( edd_paddle_redact_pii( $payload ), true ) );

    // Get API wrapper
    $api = edd_paddle_get_api();

    // Call /transactions
    $response = $api->create_transaction( $payload );

    if ( is_wp_error( $response ) ) {
        edd_paddle_log( 'API error: ' . $response->get_error_message() );
        if ( function_exists( 'edd_record_gateway_error' ) ) {
            edd_record_gateway_error( __( 'Paddle Error', 'edd-paddle-gateway' ), $response->get_error_message() );
        }
        if ( function_exists( 'edd_send_back_to_checkout' ) ) {
            edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['gateway'] );
        }
        return;
    }

    $checkout_url = isset( $response['data']['checkout']['url'] ) ? $response['data']['checkout']['url'] : '';
    $transaction_id = isset( $response['data']['id'] ) ? $response['data']['id'] : '';

    if ( ! empty( $transaction_id ) ) {
        if ( function_exists( 'edd_set_payment_transaction_id' ) ) {
            edd_set_payment_transaction_id( $payment_id, $transaction_id );
        } else {
            update_post_meta( $payment_id, '_edd_payment_transaction_id', $transaction_id );
        }
        edd_paddle_log( 'Paddle Transaction ID saved to Payment #' . $payment_id . ': ' . $transaction_id );
    }

    // Checkout mode is filterable. Default 'redirect' (free's only mode).
    // Pro add-on returns 'overlay' to enable the inline Paddle.js modal
    // code path below.
    $checkout_type = apply_filters( 'edd_paddle_checkout_mode', 'redirect' );

    if ( 'redirect' === $checkout_type && ! empty( $checkout_url ) ) {
        // Paddle Billing v2 returns an overlay-style URL (on the merchant's
        // own domain with ?_ptxn=) for accounts without Hosted Checkout
        // approval. Redirecting to that URL would strand the buyer — no
        // Paddle.js is loaded in redirect mode, so the modal never renders.
        // Detect this configuration and render the interstitial (same path
        // overlay mode uses) so checkout works regardless of dashboard setup.
        $home_host      = wp_parse_url( home_url(), PHP_URL_HOST );
        $url_host       = wp_parse_url( $checkout_url, PHP_URL_HOST );
        $is_overlay_url = $home_host
            && $url_host
            && $home_host === $url_host
            && false !== strpos( $checkout_url, '_ptxn=' );

        if ( $is_overlay_url && ! empty( $transaction_id ) ) {
            edd_paddle_log( 'Paddle returned overlay-style URL in redirect mode; rendering interstitial instead of redirecting.' );
            if ( function_exists( 'edd_empty_cart' ) ) {
                edd_empty_cart();
            }
            // 'inline' embeds the checkout form on the interstitial page so
            // buyers experience a dedicated checkout page (free-tier UX).
            // Pro's overlay mode passes 'overlay' for the modal-over-cart flow.
            edd_paddle_render_overlay_interstitial( $transaction_id, $payment_id, 'inline' );
            if ( ! defined( 'PHPUNIT_COMPOSER_INSTALL' ) ) {
                exit;
            }
            return;
        }

        edd_paddle_log( 'Redirecting to Paddle checkout URL: ' . $checkout_url );
        wp_redirect( $checkout_url );
        if ( ! defined( 'PHPUNIT_COMPOSER_INSTALL' ) ) {
            exit;
        }
        return;
    }

    if ( empty( $transaction_id ) ) {
        edd_paddle_log( 'No transaction ID returned from API.' );
        if ( function_exists( 'edd_send_back_to_checkout' ) ) {
            edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['gateway'] );
        }
        return;
    }

    // Overlay mode: render an interstitial page that opens the Paddle overlay.
    // EDD has already handled login/registration upstream of edd_gateway_paddle,
    // so by this point the user is authenticated (or guest checkout is allowed).
    if ( function_exists( 'edd_empty_cart' ) ) {
        edd_empty_cart();
    }

    edd_paddle_render_overlay_interstitial( $transaction_id, $payment_id );
    if ( ! defined( 'PHPUNIT_COMPOSER_INSTALL' ) ) {
        exit;
    }
}
add_action( 'edd_gateway_paddle', 'edd_paddle_process_purchase' );

/**
 * Render the checkout interstitial page.
 *
 * Self-contained HTML page that loads the Paddle SDK, initializes it with the
 * configured client token, and opens Paddle.Checkout. On completion, redirects
 * to the success page; on close, returns to checkout.
 *
 * @param string $transaction_id Paddle transaction ID.
 * @param int    $payment_id     EDD payment ID.
 * @param string $display_mode   Paddle.Checkout displayMode: 'overlay' (modal
 *                               over the page, default) or 'inline' (embedded
 *                               form on the page). Redirect-mode callers pass
 *                               'inline' for a dedicated-page feel; overlay-mode
 *                               callers use the default 'overlay' for the modal.
 * @return void
 */
function edd_paddle_render_overlay_interstitial( $transaction_id, $payment_id, $display_mode = 'overlay' ) {
    $mode    = function_exists( 'edd_get_option' ) ? edd_get_option( 'edd_paddle_mode', 'sandbox' ) : 'sandbox';
    $is_live = ( 'live' === $mode );

    if ( $is_live ) {
        $client_token = function_exists( 'edd_get_option' ) ? edd_get_option( 'edd_paddle_live_client_token', '' ) : '';
    } else {
        $client_token = function_exists( 'edd_get_option' ) ? edd_get_option( 'edd_paddle_sandbox_client_token', '' ) : '';
    }

    $success_url = add_query_arg( [
        'payment-confirmation' => 'paddle',
        'payment-id'           => $payment_id,
    ], function_exists( 'edd_get_success_page_uri' ) ? edd_get_success_page_uri() : home_url() );

    $checkout_url = function_exists( 'edd_get_checkout_uri' ) ? edd_get_checkout_uri() : home_url( '/' );

    // Paddle.Checkout.open accepts displayMode "overlay" (modal) or "inline"
    // (embedded in the page). Redirect-mode callers pass "inline" so buyers
    // get a dedicated full-page checkout; overlay-mode callers use the
    // default "overlay" so the modal appears over the cart page.
    $allowed_modes = [ 'overlay', 'inline' ];
    if ( ! in_array( $display_mode, $allowed_modes, true ) ) {
        $display_mode = 'overlay';
    }

    $paddle_env    = $is_live ? 'production' : 'sandbox';
    $env_set_js    = $is_live ? '' : 'Paddle.Environment.set(' . json_encode( $paddle_env ) . ');';
    $token_js      = json_encode( $client_token );
    $txn_js        = json_encode( $transaction_id );
    $success_js    = json_encode( $success_url );
    $checkout_js   = json_encode( $checkout_url );
    $display_js    = json_encode( $display_mode );

    edd_paddle_log( 'Rendering overlay interstitial for transaction: ' . $transaction_id . ' | payment: ' . $payment_id );

    $title = esc_html__( 'Secure Checkout', 'edd-paddle-gateway' );
    $head  = esc_html__( 'Opening secure checkout...', 'edd-paddle-gateway' );
    $sub   = esc_html__( 'Please wait a moment.', 'edd-paddle-gateway' );

    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . $title . '</title>';
    echo '<style>@keyframes edd-paddle-spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}';
    echo 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#f5f5f5}';
    echo '.edd-paddle-wrap{text-align:center;color:#333}.edd-paddle-spinner{width:48px;height:48px;margin:0 auto 20px;border:4px solid #e2e4e7;border-top:4px solid #0073aa;border-radius:50%;animation:edd-paddle-spin 1s linear infinite}';
    echo 'h2{margin:0 0 8px;font-size:18px;font-weight:600}p{margin:0;color:#666;font-size:14px}</style>';
    echo '</head><body><div class="edd-paddle-wrap"><div class="edd-paddle-spinner"></div>';
    echo '<h2>' . $head . '</h2><p>' . $sub . '</p>';
    echo '<script src="https://cdn.paddle.com/paddle/v2/paddle.js"></script>';
    echo '<script>';
    echo $env_set_js;
    echo 'Paddle.Initialize({token:' . $token_js . ',eventCallback:function(event){';
    echo 'if(event.name==="checkout.completed"){window.location.href=' . $success_js . ';}';
    echo 'else if(event.name==="checkout.closed"){window.location.href=' . $checkout_js . ';}';
    echo '}});';
    echo 'Paddle.Checkout.open({transactionId:' . $txn_js . ',settings:{displayMode:' . $display_js . ',theme:"light",locale:"en"}});';
    echo '</script></div></body></html>';
}

/**
 * Show payment processing page if order is still pending
 * Uses EDD's native template system like wpfront-paddle-gateway
 *
 * @param string $content Page content.
 * @return string
 */
function edd_paddle_payment_confirm( $content ) {
    // Debug logging
    edd_paddle_log( '======================================' );
    edd_paddle_log( 'edd_paddle_payment_confirm FILTER CALLED' );
    edd_paddle_log_verbose( 'GET params: ' . print_r( edd_paddle_redact_pii( $_GET ), true ) );
    edd_paddle_log( 'is_success_page: ' . ( function_exists( 'edd_is_success_page' ) ? ( edd_is_success_page() ? 'YES' : 'NO' ) : 'FUNCTION NOT FOUND' ) );

    // Support both wpfront-style (payment-id) and payment_key (underscore/hyphen) parameters
    $payment_id = 0;
    $redirect_params = [];

    // Method 1: Direct payment-id (from Paddle redirect)
    if ( isset( $_GET['payment-id'] ) ) {
        $payment_id = absint( $_GET['payment-id'] );
        $redirect_params['payment-id'] = $payment_id;
    }
    // Method 2: payment_key lookup (from overlay mode JS redirect) - supports both underscore and hyphen
    elseif ( isset( $_GET['payment_key'] ) ) {
        $payment_key = sanitize_text_field( $_GET['payment_key'] );
        $payment_id = edd_get_purchase_id_by_key( $payment_key );
        $redirect_params['payment_key'] = $payment_key; // Use underscore for EDD compatibility
    }
    elseif ( isset( $_GET['payment-key'] ) ) {
        $payment_key = sanitize_text_field( $_GET['payment-key'] );
        $payment_id = edd_get_purchase_id_by_key( $payment_key );
        $redirect_params['payment_key'] = $payment_key; // Convert to underscore for EDD
    }

    if ( ! $payment_id ) {
        return $content;
    }

    // Always include payment-confirmation parameter for redirect
    $redirect_params['payment-confirmation'] = 'paddle';

    edd_paddle_log( '======================================' );
    edd_paddle_log( 'Payment confirm page loaded for payment_id: ' . $payment_id );

    $status = edd_get_payment_status( $payment_id );
    $webhook_received = get_post_meta( $payment_id, '_edd_paddle_webhook_received', true );

    edd_paddle_log( 'Payment status: ' . $status . ' | Webhook received: ' . ( $webhook_received ? 'YES' : 'NO' ) );

    // If payment is still pending or processing, show the processing template
    if ( 'pending' === $status || 'processing' === $status ) {
        // Force empty the cart here just in case
        if ( function_exists( 'edd_empty_cart' ) ) {
            edd_empty_cart();
        }

        edd_paddle_log( 'Payment still pending, showing processing template' );

        // Build refresh URL WITH parameters so filter runs on next reload
        $success_url = function_exists( 'edd_get_success_page_uri' ) ? edd_get_success_page_uri() : home_url();
        $refresh_url = add_query_arg( $redirect_params, $success_url );

        edd_paddle_log( 'Refresh URL: ' . $refresh_url );

        // Output custom processing content (no full HTML wrapper - WordPress handles that)
        ob_start();
        ?>
        <style>
            .edd-paddle-processing {
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 50vh;
                text-align: center;
            }
            .edd-paddle-spinner {
                width: 50px;
                height: 50px;
                margin: 0 auto 20px;
                border: 4px solid #f3f3f3;
                border-top: 4px solid #0073aa;
                border-radius: 50%;
                animation: edd-paddle-spin 1s linear infinite;
            }
            @keyframes edd-paddle-spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
        <div class="edd-paddle-processing">
            <div>
                <div class="edd-paddle-spinner"></div>
                <h2><?php _e( 'Processing Your Payment', 'edd-paddle-gateway' ); ?></h2>
                <p><?php _e( 'Please wait while we confirm your payment...', 'edd-paddle-gateway' ); ?></p>
                <script>
                    setTimeout(function() {
                        var redirectUrl = <?php echo json_encode( $refresh_url ); ?>;
                        console.log('[EDD Paddle] Redirecting to: ' + redirectUrl);
                        window.location.href = redirectUrl;
                    }, 5000);
                </script>
            </div>
        </div>
        <?php
        $content = ob_get_clean();
    } else {
        edd_paddle_log( 'Payment complete (' . $status . '), showing success content' );
        // Payment is complete, return the original content unchanged
        // This allows EDD to show the normal success page
        return $content;
    }

    edd_paddle_log( '======================================' );

    return $content;
}
add_filter( 'edd_payment_confirm_paddle', 'edd_paddle_payment_confirm' );

/**
 * Alternative: Hook into template_redirect to intercept before page loads
 */
function edd_paddle_template_redirect() {
    if ( ! isset( $_GET['payment-confirmation'] ) || $_GET['payment-confirmation'] !== 'paddle' ) {
        return;
    }

    edd_paddle_log( '======================================' );
    edd_paddle_log( 'template_redirect hook called' );
    edd_paddle_log_verbose( 'GET params: ' . print_r( edd_paddle_redact_pii( $_GET ), true ) );
    edd_paddle_log( 'Current URL: ' . $_SERVER['REQUEST_URI'] );

    // Support both wpfront-style (payment-id) and payment_key (underscore/hyphen) parameters
    $payment_id = 0;
    $redirect_params = [];

    // Method 1: Direct payment-id (from Paddle redirect)
    if ( isset( $_GET['payment-id'] ) ) {
        $payment_id = absint( $_GET['payment-id'] );
        $redirect_params['payment-id'] = $payment_id;
    }
    // Method 2: payment_key lookup (from overlay mode JS redirect) - supports both underscore and hyphen
    elseif ( isset( $_GET['payment_key'] ) ) {
        $payment_key = sanitize_text_field( $_GET['payment_key'] );
        $payment_id = edd_get_purchase_id_by_key( $payment_key );
        $redirect_params['payment_key'] = $payment_key; // Use underscore for EDD compatibility
    }
    elseif ( isset( $_GET['payment-key'] ) ) {
        $payment_key = sanitize_text_field( $_GET['payment-key'] );
        $payment_id = edd_get_purchase_id_by_key( $payment_key );
        $redirect_params['payment_key'] = $payment_key; // Convert to underscore for EDD
    }

    if ( ! $payment_id ) {
        edd_paddle_log( 'No payment_id found' );
        return;
    }

    // Always include payment-confirmation parameter for redirect
    $redirect_params['payment-confirmation'] = 'paddle';

    edd_paddle_log( 'Found payment_id: ' . $payment_id );

    $status = edd_get_payment_status( $payment_id );
    edd_paddle_log( 'Payment status: ' . $status );

    // If payment is still pending or processing, show processing page
    if ( 'pending' === $status || 'processing' === $status ) {
        // Force empty the cart here just in case
        if ( function_exists( 'edd_empty_cart' ) ) {
            edd_empty_cart();
        }

        edd_paddle_log( 'Payment pending, showing processing page' );

        // Build refresh URL WITH parameters
        $success_url = function_exists( 'edd_get_success_page_uri' ) ? edd_get_success_page_uri() : home_url();
        $refresh_url = add_query_arg( $redirect_params, $success_url );

        edd_paddle_log( 'Refresh URL: ' . $refresh_url );

        // Output full processing page and exit
        // Use json_encode for proper JS escaping (keeps & intact)
        $redirect_url_js = json_encode( $refresh_url );
        $output = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Processing</title>';
        $output .= '<style>@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}';
        $output .= 'body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#f5f5f5}';
        $output .= '.spinner{width:50px;height:50px;margin:0 auto 20px;border:4px solid #f3f3f3;border-top:4px solid #0073aa;border-radius:50%;animation:spin 1s linear infinite}';
        $output .= 'h2{margin:0 0 10px;color:#333}p{margin:0;color:#666}</style></head><body>';
        $output .= '<div style="text-align:center;"><div class="spinner"></div>';
        $output .= '<h2>' . esc_html( __( 'Processing Your Payment', 'edd-paddle-gateway' ) ) . '</h2>';
        $output .= '<p>' . esc_html( __( 'Please wait while we confirm your payment...', 'edd-paddle-gateway' ) ) . '</p>';
        $output .= '<script>setTimeout(function(){ window.location.href = ' . $redirect_url_js . '; }, 5000);</script>';
        $output .= '</div></body></html>';

        echo $output;
        if ( ! defined( 'PHPUNIT_COMPOSER_INSTALL' ) ) {
            exit;
        }
    } else {
        edd_paddle_log( 'Payment complete, letting EDD handle success page' );
    }
}
add_action( 'template_redirect', 'edd_paddle_template_redirect', 1 );

