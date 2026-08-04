<?php
/**
 * PHPUnit Tests Bootstrap
 */

// Define ABSPATH to prevent exit when loading main plugin files.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

// Define PHPUNIT_COMPOSER_INSTALL so webhook listener doesn't exit
if ( ! defined( 'PHPUNIT_COMPOSER_INSTALL' ) ) {
    define( 'PHPUNIT_COMPOSER_INSTALL', true );
}

// Load Composer autoloader.
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Define core WP helper functions globally for tests so they persist across Brain Monkey teardowns
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return dirname( $file ) . '/';
    }
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) {
        return 'https://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( 'is_admin' ) ) {
    function is_admin() {
        return true;
    }
}

if ( ! function_exists( 'clean_post_cache' ) ) {
    function clean_post_cache( $post = null ) {
        // No-op stub for unit tests; in production this invalidates WP post caches.
    }
}

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) {
        return 'https://example.com/wp-admin/' . $path;
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $code;
        public $message;
        public $data;

        public function __construct( $code = '', $message = '', $data = '' ) {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }
    }
}

if ( ! function_exists( 'edd_paddle_log' ) ) {
    function edd_paddle_log( $message ) {
        error_log( '[EDD Paddle] ' . $message );
    }
}

// Helpers used by include files (class-edd-paddle-*.php) that may be loaded
// by individual tests without the main plugin file. Mirrors the plugin's
// implementations so tests get real behavior without coupling to the plugin
// file's load order. Kept in sync via the `function_exists` guard.
if ( ! function_exists( 'edd_paddle_pii_keys' ) ) {
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
    function edd_paddle_log_verbose( $message ) {
        if ( defined( 'EDD_PADDLE_DEBUG' ) && EDD_PADDLE_DEBUG ) {
            error_log( '[EDD Paddle][verbose] ' . $message );
        }
    }
}
