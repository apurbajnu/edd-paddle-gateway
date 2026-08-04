<?php
/**
 * Easy Digital Downloads - Paddle Product Sync
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'edd_paddle_get_api' ) ) {
    /**
     * Get the Paddle API Instance configured with store settings
     *
     * @return EDD_Paddle_API
     */
    function edd_paddle_get_api() {
        $mode    = function_exists( 'edd_get_option' ) ? edd_get_option( 'edd_paddle_mode', 'sandbox' ) : 'sandbox';
        $sandbox = ( 'sandbox' === $mode );
        
        if ( $sandbox ) {
            $api_key = function_exists( 'edd_get_option' ) ? edd_get_option( 'edd_paddle_sandbox_api_key' ) : '';
        } else {
            $api_key = function_exists( 'edd_get_option' ) ? edd_get_option( 'edd_paddle_live_api_key' ) : '';
        }

        return new EDD_Paddle_API( $api_key, $sandbox );
    }
}


if ( ! function_exists( 'edd_paddle_sync_product_to_paddle' ) ) {
    /**
     * Sync WordPress Download to Paddle Product and Price(s)
     *
     * @param int            $post_id The WordPress post ID.
     * @param EDD_Paddle_API $api     Optional. API instance to override.
     * @return void
     */
    function edd_paddle_sync_product_to_paddle( $post_id, $api = null ) {
        // Ensure we have fresh data
        clean_post_cache( $post_id );

        // Check if this is a download post type
        if ( 'download' !== get_post_type( $post_id ) ) {
            return;
        }

        // Avoid infinite loops on save_post
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! $api ) {
            $api = edd_paddle_get_api();
        }

        $product_id = get_post_meta( $post_id, 'edd_paddle_product_id', true );
        $title      = get_the_title( $post_id );
        $excerpt    = get_post_field( 'post_excerpt', $post_id );
        if ( empty( $excerpt ) ) {
            $excerpt = wp_trim_words( get_post_field( 'post_content', $post_id ), 20 );
        }

        $product_body = [
            'name'         => $title,
            'description'  => $excerpt,
            'tax_category' => 'standard',
            'custom_data'  => [
                'edd_download_id' => $post_id,
            ],
        ];

        $current_product_hash = md5( serialize( $product_body ) );
        $last_product_hash    = get_post_meta( $post_id, '_edd_paddle_product_hash', true );

        if ( empty( $product_id ) ) {
            // Create product in Paddle
            $product_response = $api->create_product( $product_body );
            if ( is_wp_error( $product_response ) ) {
                edd_paddle_log( 'Product creation failed. Error: ' . $product_response->get_error_message() . ' | download_id: ' . $post_id );
                return;
            }
            $product_id = $product_response['data']['id'];
            update_post_meta( $post_id, 'edd_paddle_product_id', $product_id );
            update_post_meta( $post_id, '_edd_paddle_product_hash', $current_product_hash );
        } elseif ( $current_product_hash !== $last_product_hash ) {
            // Update existing product in Paddle
            $update_response = $api->update_product( $product_id, $product_body );
            if ( is_wp_error( $update_response ) ) {
                edd_paddle_log( 'Product update failed. Error: ' . $update_response->get_error_message() . ' | download_id: ' . $post_id );
            } else {
                update_post_meta( $post_id, '_edd_paddle_product_hash', $current_product_hash );
            }
        }

        // Sync Price(s)
        $currency = function_exists( 'edd_get_currency' ) ? edd_get_currency() : 'USD';

        // Variable-price sync gated behind filter. Defaults to false so free
        // plugin syncs only single-price products. Pro add-on flips this to
        // true to enable variable/multi-price sync using the same code path.
        $supports_variable = apply_filters( 'edd_paddle_product_sync_supports_variable_prices', false );

        if ( $supports_variable && function_exists( 'edd_has_variable_prices' ) && edd_has_variable_prices( $post_id ) ) {
            $prices        = edd_get_variable_prices( $post_id );
            $synced_prices = get_post_meta( $post_id, 'edd_paddle_variable_prices', true );
            if ( ! is_array( $synced_prices ) ) {
                $synced_prices = [];
            }

            $last_amounts = get_post_meta( $post_id, '_edd_paddle_last_amounts', true );
            if ( ! is_array( $last_amounts ) ) {
                $last_amounts = [];
            }

            $last_price_hashes = get_post_meta( $post_id, '_edd_paddle_variable_price_hashes', true );
            if ( ! is_array( $last_price_hashes ) ) {
                $last_price_hashes = [];
            }

            foreach ( $prices as $price_id => $price_data ) {
                $price_amount = EDD_Paddle_API::format_unit_price_amount( $price_data['amount'], $currency );

                $is_recurring = false;
                if ( function_exists( 'edd_recurring' ) && edd_recurring() ) {
                    $is_recurring = edd_recurring()->is_price_recurring( $post_id, $price_id );
                }

                $price_body = [
                    'product_id'  => $product_id,
                    'description' => $title . ' - ' . $price_data['name'],
                    'unit_price'  => [
                        'amount'        => $price_amount,
                        'currency_code' => $currency,
                    ],
                ];

                if ( $is_recurring ) {
                    $period = edd_recurring()->get_period( $price_id, $post_id );

                    $price_body['billing_cycle'] = EDD_Paddle_API::map_period_to_billing_cycle( $period );
                }

                $current_price_hash = md5( serialize( $price_body ) );
                $last_price_hash    = isset( $last_price_hashes[ $price_id ] ) ? $last_price_hashes[ $price_id ] : '';
                $existing_paddle_price_id = isset( $synced_prices[ $price_id ] ) ? $synced_prices[ $price_id ] : '';
                $last_amount              = isset( $last_amounts[ $price_id ] ) ? $last_amounts[ $price_id ] : '';

                if ( empty( $existing_paddle_price_id ) || $current_price_hash !== $last_price_hash ) {
                    
                    // If amount and recurring status/period are same, we can just update the description
                    $immutable_changed = empty( $existing_paddle_price_id ) || ( $price_amount !== $last_amount );
                    
                    // Basic billing cycle change detection (simplified for hash check)
                    // If the hash changed but the amount didn't, we still create a new price if type/billing_cycle changed.
                    // But our hash already covers those. So if hash changed but amount is same, it might just be the description.
                    
                    if ( ! $immutable_changed && ! empty( $existing_paddle_price_id ) ) {
                        // Try updating description
                        $api->update_price( $existing_paddle_price_id, [ 'description' => $price_body['description'] ] );
                        $last_price_hashes[ $price_id ] = $current_price_hash;
                    } else {
                        // Create new Price in Paddle
                        $price_response = $api->create_price( $price_body );
                        if ( is_wp_error( $price_response ) ) {
                            edd_paddle_log( 'Price creation failed. Error: ' . $price_response->get_error_message() . ' | download_id: ' . $post_id . ' | price_id: ' . $price_id );
                        } else {
                            // Archive old price
                            if ( ! empty( $existing_paddle_price_id ) ) {
                                $api->update_price( $existing_paddle_price_id, [ 'status' => 'archived' ] );
                            }

                            $synced_prices[ $price_id ] = $price_response['data']['id'];
                            $last_amounts[ $price_id ]  = $price_amount;
                            $last_price_hashes[ $price_id ] = $current_price_hash;
                        }
                    }
                }
            }
            update_post_meta( $post_id, 'edd_paddle_variable_prices', $synced_prices );
            update_post_meta( $post_id, '_edd_paddle_last_amounts', $last_amounts );
            update_post_meta( $post_id, '_edd_paddle_variable_price_hashes', $last_price_hashes );
        } else {
            $price_amount = function_exists( 'edd_get_download_price' ) ? edd_get_download_price( $post_id ) : '0.00';
            $price_amount = EDD_Paddle_API::format_unit_price_amount( $price_amount, $currency );

            $is_recurring = false;
            if ( function_exists( 'edd_recurring' ) && edd_recurring() ) {
                $is_recurring = edd_recurring()->is_recurring( $post_id );
            }

            // Single-price description: default to just the product title.
            // Sellers of services, ebooks, memberships, etc. don't want "Standard License"
            // appended — expose a filter so they can set a suffix globally or per-download.
            $suffix      = apply_filters( 'edd_paddle_price_description_suffix', '', $post_id );
            $description = $suffix ? trim( $title . ' - ' . $suffix ) : $title;

            $price_body = [
                'product_id'  => $product_id,
                'description' => $description,
                'unit_price'  => [
                    'amount'        => $price_amount,
                    'currency_code' => $currency,
                ],
            ];

            if ( $is_recurring ) {
                $period = get_post_meta( $post_id, 'edd_period', true );

                $price_body['billing_cycle'] = EDD_Paddle_API::map_period_to_billing_cycle( $period );
            }

            $current_price_hash = md5( serialize( $price_body ) );
            $last_price_hash    = get_post_meta( $post_id, '_edd_paddle_price_hash', true );
            $existing_price_id = get_post_meta( $post_id, 'edd_paddle_price_id', true );
            $last_amount       = get_post_meta( $post_id, '_edd_paddle_last_amount', true );

            if ( empty( $existing_price_id ) || $current_price_hash !== $last_price_hash ) {
                
                $immutable_changed = empty( $existing_price_id ) || ( $price_amount !== $last_amount );

                if ( ! $immutable_changed && ! empty( $existing_price_id ) ) {
                    $api->update_price( $existing_price_id, [ 'description' => $price_body['description'] ] );
                    update_post_meta( $post_id, '_edd_paddle_price_hash', $current_price_hash );
                } else {
                    $price_response = $api->create_price( $price_body );
                    if ( is_wp_error( $price_response ) ) {
                        edd_paddle_log( 'Price creation failed. Error: ' . $price_response->get_error_message() . ' | download_id: ' . $post_id );
                    } else {
                        // Archive old price
                        if ( ! empty( $existing_price_id ) ) {
                            $api->update_price( $existing_price_id, [ 'status' => 'archived' ] );
                        }

                        update_post_meta( $post_id, 'edd_paddle_price_id', $price_response['data']['id'] );
                        update_post_meta( $post_id, '_edd_paddle_last_amount', $price_amount );
                        update_post_meta( $post_id, '_edd_paddle_price_hash', $current_price_hash );
                    }
                }
            }
        }
    }
}
add_action( 'save_post_download', 'edd_paddle_sync_product_to_paddle', 999, 1 );
add_action( 'edd_save_download', 'edd_paddle_sync_product_to_paddle', 999, 1 );

