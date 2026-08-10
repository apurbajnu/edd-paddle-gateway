<?php
/**
 * Paddle Billing v2 API Wrapper
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EDD_Paddle_API {

    /**
     * Paddle API Key
     *
     * @var string
     */
    private $api_key;

    /**
     * Sandbox mode
     *
     * @var bool
     */
    private $sandbox;

    /**
     * Constructor
     *
     * @param string $api_key Paddle API Key.
     * @param bool   $sandbox Sandbox mode.
     */
    public function __construct( $api_key, $sandbox = false ) {
        $this->api_key = $api_key;
        $this->sandbox = (bool) $sandbox;
    }

    /**
     * Get Base URL
     *
     * @return string
     */
    private function get_base_url() {
        return $this->sandbox ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com';
    }

    /**
     * Make API Request
     *
     * @param string $method HTTP method.
     * @param string $endpoint API endpoint.
     * @param array  $body Request body parameters.
     * @return array|WP_Error
     */
    public function request( $method, $endpoint, $body = [] ) {
        $url = $this->get_base_url() . '/' . ltrim( $endpoint, '/' );

        $headers = [
            'Authorization'  => 'Bearer ' . $this->api_key,
            'Content-Type'   => 'application/json',
            'Paddle-Version' => defined( 'EDD_PADDLE_API_VERSION' ) ? EDD_PADDLE_API_VERSION : '1',
        ];

        $args = [
            'method'      => strtoupper( $method ),
            'headers'     => $headers,
            'timeout'     => 30,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking'    => true,
        ];

        if ( ! empty( $body ) ) {
            $args['body'] = json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data          = json_decode( $response_body, true );

        if ( $response_code < 200 || $response_code >= 300 ) {
            $error_message = isset( $data['error']['detail'] ) ? $data['error']['detail'] : __( 'An error occurred during the API request.', 'edd-paddle-gateway' );
            $error_code    = isset( $data['error']['code'] ) ? $data['error']['code'] : 'paddle_api_error';

            // Surface the error code/message at operational level (no PII), but
            // gate the full response body behind verbose mode — error bodies
            // can include customer email/PII from Paddle.
            edd_paddle_log( sprintf( 'Paddle API Error: %s | Code: %s | URL: %s', $error_message, $error_code, $url ) );
            edd_paddle_log_verbose( sprintf( 'Paddle API Error response body: %s', edd_paddle_redact_pii( $response_body ) ) );

            // Always log full error details for debugging discount sync issues
            if ( isset( $data['error'] ) ) {
                edd_paddle_log( 'Paddle API Full Error Details: ' . wp_json_encode( $data['error'] ) );
            }

            return new WP_Error( $error_code, $error_message, $data );
        }

        return $data;
    }

    /**
     * Create Transaction
     *
     * @param array $body Transaction creation parameters.
     * @return array|WP_Error
     */
    public function create_transaction( $body ) {
        return $this->request( 'POST', 'transactions', $body );
    }

    /**
     * Get Transaction
     *
     * @param string $transaction_id Paddle Transaction ID.
     * @return array|WP_Error
     */
    public function get_transaction( $transaction_id ) {
        return $this->request( 'GET', 'transactions/' . $transaction_id );
    }

    /**
     * Create adjustment (refund)
     *
     * @param string $transaction_id Paddle Transaction ID.
     * @param array  $body Adjustment details.
     * @return array|WP_Error
     */
    public function refund_transaction( $body ) {
        return $this->request( 'POST', 'adjustments', $body );
    }

    /**
     * Create Customer
     *
     * @param array $body Customer details.
     * @return array|WP_Error
     */
    public function create_customer( $body ) {
        return $this->request( 'POST', 'customers', $body );
    }

    /**
     * Get Customer
     *
     * @param string $customer_id Paddle Customer ID.
     * @return array|WP_Error
     */
    public function get_customer( $customer_id ) {
        return $this->request( 'GET', 'customers/' . $customer_id );
    }

    /**
     * Cancel Subscription
     *
     * @param string $subscription_id Paddle Subscription ID.
     * @param array  $body Cancellation details.
     * @return array|WP_Error
     */
    public function cancel_subscription( $subscription_id, $body = [] ) {
        return $this->request( 'POST', 'subscriptions/' . $subscription_id . '/cancel', $body );
    }

    /**
     * Create Customer Portal Session
     *
     * @param string $customer_id Paddle Customer ID.
     * @return array|WP_Error
     */
    public function create_portal_session( $customer_id ) {
        return $this->request( 'POST', 'customers/' . $customer_id . '/portal-sessions' );
    }

    /**
     * Create Product
     *
     * @param array $body Product details.
     * @return array|WP_Error
     */
    public function create_product( $body ) {
        return $this->request( 'POST', 'products', $body );
    }

    /**
     * Update Product
     *
     * @param string $product_id Paddle Product ID.
     * @param array  $body Product update details.
     * @return array|WP_Error
     */
    public function update_product( $product_id, $body ) {
        return $this->request( 'PATCH', 'products/' . $product_id, $body );
    }

    /**
     * Create Price
     *
     * @param array $body Price details.
     * @return array|WP_Error
     */
    public function create_price( $body ) {
        return $this->request( 'POST', 'prices', $body );
    }

    /**
     * Update Price
     *
     * @param string $price_id Paddle Price ID.
     * @param array  $body Price update details.
     * @return array|WP_Error
     */
    public function update_price( $price_id, $body ) {
        return $this->request( 'PATCH', 'prices/' . $price_id, $body );
    }

    /**
     * Create Discount (Coupon)
     *
     * @param array $body Discount details.
     * @return array|WP_Error
     */
    public function create_discount( $body ) {
        return $this->request( 'POST', 'discounts', $body );
    }

    /**
     * Update Discount (Coupon)
     *
     * @param string $discount_id Paddle Discount ID.
     * @param array  $body Update details.
     * @return array|WP_Error
     */
    /**
     * Update Discount (Coupon)
     *
     * @param string $discount_id Paddle Discount ID.
     * @param array  $body Update details.
     * @return array|WP_Error
     */
    public function update_discount( $discount_id, $body ) {
        return $this->request( 'PATCH', 'discounts/' . $discount_id, $body );
    }

    /**
     * Check if the current currency is supported by Paddle
     *
     * @return bool
     */
    public static function is_currency_supported() {
        $supported = [ 'USD', 'EUR', 'GBP', 'ARS', 'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'HKD', 'HUF', 'INR', 'JPY', 'KRW', 'MXN', 'NOK', 'NZD', 'PLN', 'RUB', 'SEK', 'SGD', 'THB', 'TWD', 'ZAR' ];
        $currency  = function_exists( 'edd_get_currency' ) ? strtoupper( edd_get_currency() ) : 'USD';
        return in_array( $currency, $supported, true );
    }

    /**
     * Format a price amount for Paddle's unit_price.amount field.
     *
     * Paddle expects amounts in the smallest currency unit (integers).
     * Most currencies use 2 decimals (multiply by 100); ISO 4217 zero-decimal
     * currencies (JPY, KRW, etc.) use 0 decimals and must NOT be multiplied.
     *
     * Of Paddle's currently supported currencies, only JPY and KRW are
     * zero-decimal. The full ISO 4217 list is included for forward-compat.
     *
     * @param float|string $amount   Price in major units (e.g. 19.99 or "1000").
     * @param string       $currency 3-letter ISO 4217 currency code.
     * @return string Integer string in the smallest currency unit, no separators.
     */
    public static function format_unit_price_amount( $amount, $currency ) {
        $zero_decimal = [ 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ];
        $decimals     = in_array( strtoupper( (string) $currency ), $zero_decimal, true ) ? 0 : 2;
        return number_format( (float) $amount, $decimals, '', '' );
    }

    /**
     * Map an EDD Recurring period to a Paddle billing_cycle interval + frequency.
     *
     * Paddle supports interval: day | week | month | year (no quarter, no semiyear).
     * - 'day'/'week'/'month'/'year' pass through with frequency 1
     * - 'quarter'  → month, frequency 3
     * - 'semiyear' → month, frequency 6
     *
     * @param string $period EDD Recurring period.
     * @return array{interval:string,frequency:int}
     */
    public static function map_period_to_billing_cycle( $period ) {
        switch ( $period ) {
            case 'quarter':
                return [ 'interval' => 'month', 'frequency' => 3 ];
            case 'semiyear':
                return [ 'interval' => 'month', 'frequency' => 6 ];
            default:
                return [ 'interval' => (string) $period, 'frequency' => 1 ];
        }
    }
}
