<?php
/**
 * Easy Digital Downloads - Paddle Billing Gateway (Free)
 *
 * Plugin Name:       EDD Paddle Billing Gateway
 * Plugin URI:        https://wordpress.org/plugins/edd-paddle-gateway/
 * Description:       Accept one-time payments through Paddle Billing with off-site redirect checkout. The premium add-on (sold separately) unlocks overlay checkout, subscriptions, and refund handling.
 * Version:           1.0.0
 * Author:            bestdecoders
 * Author URI:        https://bestdecoders.com/
 * Text Domain:       edd-paddle-gateway
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Requires Plugins:  easy-digital-downloads
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Activation check: fail fast with a clear message if EDD isn't active.
 *
 * WordPress loads already-active plugins before firing the new plugin's
 * activation hook, so function_exists() here reliably tells us whether EDD
 * is available. Without this, buyers who install in the wrong order get a
 * half-loaded plugin and cryptic errors.
 */
function edd_paddle_activate() {
    if ( ! function_exists( 'edd_get_option' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'EDD Paddle Gateway requires Easy Digital Downloads 3.0+ to be installed and active. Please activate EDD first, then try activating this plugin again.', 'edd-paddle-gateway' ),
            esc_html__( 'Plugin not activated', 'edd-paddle-gateway' ),
            [ 'back_link' => true ]
        );
    }
}
if ( function_exists( 'register_activation_hook' ) ) {
    register_activation_hook( __FILE__, 'edd_paddle_activate' );
}

// Define Constants
define( 'EDD_PADDLE_VERSION', '1.0.0' );
define( 'EDD_PADDLE_API_URL', 'https://api.paddle.com' );
define( 'EDD_PADDLE_SANDBOX_URL', 'https://sandbox-api.paddle.com' );
define( 'EDD_PADDLE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EDD_PADDLE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
// Paddle Billing API version this integration targets. Bump here when Paddle
// ships a new API version and we've verified compatibility — one place to change.
define( 'EDD_PADDLE_API_VERSION', '1' );

// Load Composer Autoloader only during PHPUnit tests
if ( defined( 'PHPUNIT_COMPOSER_INSTALL' ) && file_exists( EDD_PADDLE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once EDD_PADDLE_PLUGIN_DIR . 'vendor/autoload.php';
}

// Load include files
require_once EDD_PADDLE_PLUGIN_DIR . 'includes/class-edd-paddle-api.php';
require_once EDD_PADDLE_PLUGIN_DIR . 'includes/class-edd-paddle-product-sync.php';
require_once EDD_PADDLE_PLUGIN_DIR . 'includes/class-edd-paddle-checkout.php';
require_once EDD_PADDLE_PLUGIN_DIR . 'includes/class-edd-paddle-webhook.php';




// Load admin settings
if ( is_admin() ) {
    require_once EDD_PADDLE_PLUGIN_DIR . 'admin/settings.php';
} else {
    // Also load settings functions for unit testing or webhooks if is_admin() check needs to be bypassed or settings are required
    require_once EDD_PADDLE_PLUGIN_DIR . 'admin/settings.php';
}

/**
 * Register the Paddle Payment Gateway in EDD
 *
 * @param array $gateways Registered payment gateways.
 * @return array
 */
function edd_paddle_register_gateway( $gateways ) {
    $gateways['paddle'] = [
        'admin_label'    => __( 'Paddle', 'edd-paddle-gateway' ),
        'checkout_label' => __( 'Paddle', 'edd-paddle-gateway' ),
    ];
    return $gateways;
}
add_filter( 'edd_payment_gateways', 'edd_paddle_register_gateway' );

/**
 * Remove credit card form from checkout for Paddle
 *
 * @return void
 */
function edd_paddle_remove_cc_form() {
    return;
}
add_action( 'edd_paddle_cc_form', 'edd_paddle_remove_cc_form' );

/**
 * Get the Paddle transaction ID for a payment
 *
 * @param string $transaction_id The current transaction ID.
 * @return string
 */
function edd_paddle_get_transaction_id( $transaction_id, $payment_id = 0 ) {
    // If we have a payment object, check the transaction_id property first (standard EDD 3.x)
    if ( $payment_id ) {
        $payment = edd_get_payment( $payment_id );
        if ( $payment && ! empty( $payment->transaction_id ) && $payment->transaction_id != $payment->ID ) {
            return $payment->transaction_id;
        }
        
        // Fallback to meta if property is empty or just the payment ID
        $meta_id = get_post_meta( $payment_id, '_edd_payment_transaction_id', true );
        if ( ! empty( $meta_id ) ) {
            return $meta_id;
        }
    }

    // Return the passed ID if it's not empty and not just the payment ID
    if ( ! empty( $transaction_id ) && $transaction_id != $payment_id ) {
        return $transaction_id;
    }

    return '';
}
add_filter( 'edd_get_payment_transaction_id-paddle', 'edd_paddle_get_transaction_id', 10, 2 );

/**
 * Link the transaction ID to the Paddle Dashboard
 *
 * @param string $transaction_id The transaction ID.
 * @param int    $payment_id    The payment ID.
 * @return string
 */
function edd_paddle_link_transaction_id( $transaction_id, $payment_id ) {
    if ( empty( $transaction_id ) || 'paddle' !== edd_get_payment_gateway( $payment_id ) ) {
        return $transaction_id;
    }

    $url = 'https://vendors.paddle.com/transactions/' . $transaction_id;
    return '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $transaction_id ) . '</a>';
}
add_filter( 'edd_payment_details_transaction_id-paddle', 'edd_paddle_link_transaction_id', 10, 2 );

/**
 * Log a message to the EDD debug log
 *
 * @param string $message The message to log.
 * @return void
 */
if ( ! function_exists( 'edd_paddle_log' ) ) {
/**
 * Log a message using EDD's debug log or fall back to error_log
 *
 * @param string $message The message to log.
 * @return void
 */
function edd_paddle_log( $message ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[EDD Paddle] ' . $message );
    }
}
} // end edd_paddle_log function_exists guard

if ( ! function_exists( 'edd_paddle_pii_keys' ) ) {
    /**
     * Array keys (lowercase) whose values are treated as PII for log redaction.
     * Covers Paddle Billing API fields + common WP/EDD user fields.
     *
     * @return string[]
     */
    function edd_paddle_pii_keys() {
        return [
            'email', 'mail', 'user_email',
            'name', 'first_name', 'last_name', 'full_name', 'customer_name', 'display_name',
            'phone', 'telephone', 'tel',
            'address', 'address1', 'address2', 'street', 'street1', 'street2', 'line1', 'line2',
            'city', 'state', 'region', 'province', 'county',
            'zip', 'zipcode', 'postal_code', 'postcode', 'country_code', 'country',
            'card', 'card_number', 'number', 'cvc', 'cvv', 'expiry', 'expiry_month', 'expiry_year',
            'token', 'authorization_code', 'authorization',
            'password', 'secret', 'api_key', 'api_token',
            'date_of_birth', 'dob', 'ssn', 'tax_id', 'vat_number',
            'ip', 'ip_address', 'remote_ip',
        ];
    }
}

if ( ! function_exists( 'edd_paddle_redact_pii' ) ) {
    /**
     * Recursively redact PII values from a variable so it can be logged safely.
     *
     * - Arrays: walk all keys; if key (lowercased) is in edd_paddle_pii_keys(),
     *   mask its value as '[redacted]'. Other keys are recursed into.
     * - Strings: replace embedded email addresses with '[email-redacted]'.
     *   (Useful for raw JSON webhook bodies / API responses.)
     * - Other types: returned unchanged.
     *
     * @param mixed $data Data to redact.
     * @return mixed
     */
    function edd_paddle_redact_pii( $data ) {
        if ( is_array( $data ) ) {
            $redacted = [];
            $keys     = edd_paddle_pii_keys();
            foreach ( $data as $k => $v ) {
                if ( in_array( strtolower( (string) $k ), $keys, true ) ) {
                    $redacted[ $k ] = '[redacted]';
                } else {
                    $redacted[ $k ] = edd_paddle_redact_pii( $v );
                }
            }
            return $redacted;
        }
        if ( is_string( $data ) ) {
            // Email regex: local@domain.tld — covers most Paddle webhook payloads and error bodies.
            return preg_replace(
                '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
                '[email-redacted]',
                $data
            );
        }
        return $data;
    }
}

if ( ! function_exists( 'edd_paddle_log_verbose' ) ) {
    /**
     * Verbose logging for payloads that may contain PII (raw webhook bodies,
     * full API request/response dumps, $_GET, customer objects, etc.).
     *
     * Off by default. Buyers opt in by defining `define('EDD_PADDLE_DEBUG', true);`
     * in wp-config.php — separately from WP_DEBUG so verbose Paddle logging
     * doesn't fill debug.log on every WP_DEBUG install.
     *
     * Even with verbose enabled, callers should pass data through edd_paddle_redact_pii()
     * for defense-in-depth.
     *
     * @param string $message The verbose message to log.
     * @return void
     */
    function edd_paddle_log_verbose( $message ) {
        if ( defined( 'EDD_PADDLE_DEBUG' ) && EDD_PADDLE_DEBUG ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[EDD Paddle][verbose] ' . $message );
            }
        }
    }
}

/**
 * Display an admin notice if the currency is not supported by Paddle
 *
 * @return void
 */
function edd_paddle_unsupported_currency_notice() {
    if ( ! EDD_Paddle_API::is_currency_supported() ) {
        $currency = function_exists( 'edd_get_currency' ) ? edd_get_currency() : 'USD';
        echo '<div class="error"><p>' . sprintf( __( 'Unsupported currency! Your currency <code>%s</code> is not supported by Paddle Billing.', 'edd-paddle-gateway' ), $currency ) . '</p></div>';
    }
}
add_action( 'admin_notices', 'edd_paddle_unsupported_currency_notice' );

if ( ! function_exists( 'edd_paddle_add_sync_column' ) ) {
    /**
     * Add a "Paddle" column to the Downloads list table
     *
     * @param array $columns Existing columns.
     * @return array
     */
    function edd_paddle_add_sync_column( $columns ) {
        $new_columns = [];
        $added       = false;

        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;
            if ( 'price' === $key ) {
                $new_columns['paddle_sync'] = __( 'Paddle Sync', 'edd-paddle-gateway' );
                $added = true;
            }
        }

        if ( ! $added ) {
            $new_columns['paddle_sync'] = __( 'Paddle Sync', 'edd-paddle-gateway' );
        }

        return $new_columns;
    }
}
add_filter( 'manage_edit-download_columns', 'edd_paddle_add_sync_column', 100 );
add_filter( 'edd_download_columns', 'edd_paddle_add_sync_column', 100 );

if ( ! function_exists( 'edd_paddle_render_sync_column' ) ) {
    /**
     * Render the Paddle sync status for each Download row
     *
     * @param string $column  Column identifier.
     * @param int    $post_id The download post ID.
     * @return void
     */
    function edd_paddle_render_sync_column( $column, $post_id ) {
        // Prevent duplicate rendering
        static $rendered = [];
        if ( isset( $rendered[ $post_id . $column ] ) ) {
            return;
        }

        if ( 'paddle_sync' !== $column ) {
            return;
        }

        $rendered[ $post_id . $column ] = true;

        $product_id = get_post_meta( $post_id, 'edd_paddle_product_id', true );

        if ( empty( $product_id ) ) {
            echo '<span class="edd-paddle-status edd-paddle-status--none" title="' . esc_attr__( 'Not synced to Paddle', 'edd-paddle-gateway' ) . '"></span> ' . esc_html__( 'Not synced', 'edd-paddle-gateway' );
            return;
        }

        $single_price   = get_post_meta( $post_id, 'edd_paddle_price_id', true );
        $variable_prices = get_post_meta( $post_id, 'edd_paddle_variable_prices', true );
        $has_prices      = ! empty( $single_price ) || ( is_array( $variable_prices ) && ! empty( $variable_prices ) );

        if ( $has_prices ) {
            echo '<span class="edd-paddle-status edd-paddle-status--synced" title="' . esc_attr__( 'Synced to Paddle', 'edd-paddle-gateway' ) . '"></span> ' . esc_html__( 'Synced', 'edd-paddle-gateway' );
        } else {
            echo '<span class="edd-paddle-status edd-paddle-status--partial" title="' . esc_attr__( 'Product exists but no prices synced', 'edd-paddle-gateway' ) . '"></span> ' . esc_html__( 'No prices', 'edd-paddle-gateway' );
        }
    }
}
add_action( 'manage_download_posts_custom_column', 'edd_paddle_render_sync_column', 10, 2 );

/**
 * Bootstrap complete. Pro add-on hooks this action to initialize itself
 * once free's classes are guaranteed to exist.
 */
do_action( 'edd_paddle_loaded' );
