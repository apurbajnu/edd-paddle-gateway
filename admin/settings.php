<?php
/**
 * EDD Paddle Gateway settings registration
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'edd_paddle_register_settings_section' ) ) {
    /**
     * Register settings section for Paddle
     *
     * @param array $sections Gateway sections.
     * @return array
     */
    function edd_paddle_register_settings_section( $sections ) {
        $sections['paddle'] = __( 'Paddle', 'edd-paddle-gateway' );
        return $sections;
    }
}
add_filter( 'edd_settings_sections_gateways', 'edd_paddle_register_settings_section' );


if ( ! function_exists( 'edd_paddle_register_settings_fields' ) ) {
    /**
     * Register settings fields for Paddle
     *
     * @param array $settings Gateways settings.
     * @return array
     */
    function edd_paddle_register_settings_fields( $settings ) {
        $paddle_settings = [
            'edd_paddle_settings_header' => [
                'id'   => 'edd_paddle_settings_header',
                'name' => '<h3>' . __( 'Paddle Billing v2 Settings', 'edd-paddle-gateway' ) . '</h3>',
                'type' => 'header',
            ],
            'edd_paddle_mode' => [
                'id'      => 'edd_paddle_mode',
                'name'    => __( 'Mode', 'edd-paddle-gateway' ),
                'desc'    => __( 'Toggle between live and sandbox environments.', 'edd-paddle-gateway' ),
                'type'    => 'select',
                'options' => [
                    'sandbox' => __( 'Sandbox (Testing)', 'edd-paddle-gateway' ),
                    'live'    => __( 'Live (Production)', 'edd-paddle-gateway' ),
                ],
                'std'     => 'sandbox',
            ],
            'edd_paddle_sandbox_api_key' => [
                'id'   => 'edd_paddle_sandbox_api_key',
                'name' => __( 'Sandbox API Key', 'edd-paddle-gateway' ),
                'desc' => __( 'Enter your Paddle Sandbox API Key (starts with sbox_api_).', 'edd-paddle-gateway' ),
                'type' => 'text',
                'size' => 'regular',
            ],
            'edd_paddle_live_api_key' => [
                'id'   => 'edd_paddle_live_api_key',
                'name' => __( 'Live API Key', 'edd-paddle-gateway' ),
                'desc' => __( 'Enter your Paddle Live API Key (starts with live_api_).', 'edd-paddle-gateway' ),
                'type' => 'password',
                'size' => 'regular',
            ],
            'edd_paddle_sandbox_client_token' => [
                'id'   => 'edd_paddle_sandbox_client_token',
                'name' => __( 'Sandbox Client Token', 'edd-paddle-gateway' ),
                'desc' => __( 'Required for Paddle.js overlay checkout in Sandbox.', 'edd-paddle-gateway' ),
                'type' => 'text',
                'size' => 'regular',
            ],
            'edd_paddle_live_client_token' => [
                'id'   => 'edd_paddle_live_client_token',
                'name' => __( 'Live Client Token', 'edd-paddle-gateway' ),
                'desc' => __( 'Required for Paddle.js overlay checkout in Production.', 'edd-paddle-gateway' ),
                'type' => 'text',
                'size' => 'regular',
            ],
            'edd_paddle_sandbox_webhook_secret' => [
                'id'   => 'edd_paddle_sandbox_webhook_secret',
                'name' => __( 'Sandbox Webhook Secret', 'edd-paddle-gateway' ),
                'desc' => __( 'Required to verify sandbox webhooks (starts with pdl_ntf_).', 'edd-paddle-gateway' ),
                'type' => 'password',
                'size' => 'regular',
            ],
            'edd_paddle_live_webhook_secret' => [
                'id'   => 'edd_paddle_live_webhook_secret',
                'name' => __( 'Live Webhook Secret', 'edd-paddle-gateway' ),
                'desc' => __( 'Required to verify production webhooks (starts with pdl_ntf_).', 'edd-paddle-gateway' ),
                'type' => 'password',
                'size' => 'regular',
            ],
        ];

        // Allow pro add-on to inject its own settings fields (license, advanced).
        $paddle_settings = apply_filters( 'edd_paddle_settings_fields', $paddle_settings );

        $settings['paddle'] = $paddle_settings;
        return $settings;
    }
}
add_filter( 'edd_settings_gateways', 'edd_paddle_register_settings_fields' );

if ( ! function_exists( 'edd_paddle_gateway_settings_url' ) ) {
    /**
     * Register the Settings URL for the Paddle Gateway
     *
     * @return string
     */
    function edd_paddle_gateway_settings_url() {
        return admin_url( 'edit.php?post_type=download&page=edd-settings&tab=gateways&section=paddle' );
    }
}
add_filter( 'edd_gateway_settings_url_paddle', 'edd_paddle_gateway_settings_url' );

if ( ! function_exists( 'edd_paddle_enqueue_admin_css' ) ) {
    /**
     * Enqueue admin CSS to make the configure gear icon button always visible
     * for the Paddle gateway row in the EDD Payment Gateways settings list.
     *
     * EDD hides the button via CSS by default unless the parent has .edd-plugin__active,
     * which is only set by the Extension Manager installer (not for third-party gateways).
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    function edd_paddle_enqueue_admin_css() {
        wp_enqueue_style(
            'edd-paddle-admin',
            EDD_PADDLE_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            EDD_PADDLE_VERSION
        );
    }
}
add_action( 'admin_enqueue_scripts', 'edd_paddle_enqueue_admin_css' );

if ( ! function_exists( 'edd_paddle_settings_conditional_js' ) ) {
    /**
     * Output inline JS to show/hide API key fields based on the selected mode.
     *
     * - Sandbox mode selected → show Sandbox API Key, hide Live API Key
     * - Live mode selected → show Live API Key, hide Sandbox API Key
     * - Client Token, Webhook Secret, and Checkout Flow are always visible.
     *
     * Self-activating: only runs if the mode dropdown exists on the page.
     *
     * @return void
     */
    function edd_paddle_settings_conditional_js() {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // EDD 3.0+ uses id="edd_settings[edd_paddle_mode]" and name="edd_settings[edd_paddle_mode]"
            // Select by name as it's more robust in jQuery for bracketed names
            var $modeSelect = $('select[name="edd_settings[edd_paddle_mode]"]');
            if (!$modeSelect.length) {
                $modeSelect = $('#edd_paddle_mode');
                if (!$modeSelect.length) return;
            }

            function toggleApiKeys() {
                var isLive = ('live' === $modeSelect.val());
                
                // Find all inputs by name
                var $sandboxInput = $('input[name="edd_settings[edd_paddle_sandbox_api_key]"]');
                var $liveInput    = $('input[name="edd_settings[edd_paddle_live_api_key]"]');

                var $sandboxToken = $('input[name="edd_settings[edd_paddle_sandbox_client_token]"]');
                var $liveToken    = $('input[name="edd_settings[edd_paddle_live_client_token]"]');

                var $sandboxSecret = $('input[name="edd_settings[edd_paddle_sandbox_webhook_secret]"]');
                var $liveSecret    = $('input[name="edd_settings[edd_paddle_live_webhook_secret]"]');
                
                // Fallbacks for IDs
                if (!$sandboxInput.length) $sandboxInput = $('#edd_paddle_sandbox_api_key');
                if (!$liveInput.length) $liveInput = $('#edd_paddle_live_api_key');
                if (!$sandboxToken.length) $sandboxToken = $('#edd_paddle_sandbox_client_token');
                if (!$liveToken.length) $liveToken = $('#edd_paddle_live_client_token');
                if (!$sandboxSecret.length) $sandboxSecret = $('#edd_paddle_sandbox_webhook_secret');
                if (!$liveSecret.length) $liveSecret = $('#edd_paddle_live_webhook_secret');

                // Toggle <tr> rows
                if ($sandboxInput.length) $sandboxInput.closest('tr').toggle(!isLive);
                if ($liveInput.length) $liveInput.closest('tr').toggle(isLive);
                
                if ($sandboxToken.length) $sandboxToken.closest('tr').toggle(!isLive);
                if ($liveToken.length) $liveToken.closest('tr').toggle(isLive);
                
                if ($sandboxSecret.length) $sandboxSecret.closest('tr').toggle(!isLive);
                if ($liveSecret.length) $liveSecret.closest('tr').toggle(isLive);
            }

            $modeSelect.on('change', toggleApiKeys);
            toggleApiKeys();
        });
        </script>
        <?php
    }
}
add_action( 'admin_print_footer_scripts', 'edd_paddle_settings_conditional_js' );

if ( ! function_exists( 'edd_paddle_run_test_connection' ) ) {
    /**
     * Verify the saved API key works by making a lightweight GET to /transactions.
     * Returns [ 'success' => bool, 'message' => string ]. Kept separate from the
     * AJAX wrapper so it can be unit-tested directly.
     *
     * @return array{success:bool,message:string}
     */
    function edd_paddle_run_test_connection() {
        $api      = edd_paddle_get_api();
        $response = $api->request( 'GET', 'transactions?per_page=1', [] );

        if ( is_wp_error( $response ) ) {
            $code = $response->get_error_code();
            $msg  = $response->get_error_message();

            // Tailor the message for the most common failure so buyers can self-fix.
            if ( false !== stripos( (string) $msg, 'unauthorized' ) || 'paddle_forbidden' === $code ) {
                return [
                    'success' => false,
                    'message' => __( 'Authentication failed. Check that the API key matches the selected mode (sandbox vs live).', 'edd-paddle-gateway' ),
                ];
            }
            return [ 'success' => false, 'message' => $msg ];
        }

        return [ 'success' => true, 'message' => __( 'Connection successful — your API key works.', 'edd-paddle-gateway' ) ];
    }
}

if ( ! function_exists( 'edd_paddle_ajax_test_connection' ) ) {
    /**
     * AJAX wrapper for edd_paddle_run_test_connection: permission + nonce + JSON.
     *
     * @return void
     */
    function edd_paddle_ajax_test_connection() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'edd-paddle-gateway' ) ], 403 );
        }
        check_ajax_referer( 'edd_paddle_test_connection', 'nonce' );

        $result = edd_paddle_run_test_connection();
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }
}
add_action( 'wp_ajax_edd_paddle_test_connection', 'edd_paddle_ajax_test_connection' );

if ( ! function_exists( 'edd_paddle_test_connection_inline_js' ) ) {
    /**
     * Inject the "Test Connection" button + AJAX handler into the settings page.
     * Anchors to the Mode row so the button shows for both sandbox and live
     * regardless of which is selected. Uses the same inline-JS pattern as
     * edd_paddle_settings_conditional_js to avoid EDD's setting-type API.
     *
     * @return void
     */
    function edd_paddle_test_connection_inline_js() {
        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce    = wp_create_nonce( 'edd_paddle_test_connection' );

        $label_button  = __( 'Test API Connection', 'edd-paddle-gateway' );
        $label_testing = __( 'Testing...', 'edd-paddle-gateway' );
        $label_row      = __( 'Test Connection', 'edd-paddle-gateway' );
        $label_desc     = __( 'Verifies your saved API key by making a test request to Paddle. Save changes first if you just entered a new key.', 'edd-paddle-gateway' );
        $label_failed   = __( 'Request failed (server error)', 'edd-paddle-gateway' );
        ?>
<script type="text/javascript">
jQuery(document).ready(function($) {
    var $modeRow = $('select[name="edd_settings[edd_paddle_mode]"]').closest('tr');
    if (!$modeRow.length || $('#edd-paddle-test-connection-row').length) return;

    var $row = $('<tr valign="top" id="edd-paddle-test-connection-row">')
        .append($('<th scope="row">').text(<?php echo wp_json_encode($label_row); ?>))
        .append($('<td>')
            .append($('<button type="button" class="button button-secondary" id="edd-paddle-test-connection">').text(<?php echo wp_json_encode($label_button); ?>))
            .append($('<span id="edd-paddle-test-result" style="margin-left:10px;font-weight:600;">'))
            .append($('<p class="description">').text(<?php echo wp_json_encode($label_desc); ?>))
        );
    $modeRow.after($row);

    $('#edd-paddle-test-connection').on('click', function() {
        var $btn = $(this).prop('disabled', true).text(<?php echo wp_json_encode($label_testing); ?>);
        var $result = $('#edd-paddle-test-result').empty().css('color', '#666');

        $.post(<?php echo wp_json_encode($ajax_url); ?>, {
            action: 'edd_paddle_test_connection',
            nonce:  <?php echo wp_json_encode($nonce); ?>
        }).done(function(resp) {
            if (resp && resp.success) {
                $result.css('color', '#46b450').text('✓ ' + (resp.data.message || 'Success'));
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Unknown error';
                $result.css('color', '#dc3232').text('✗ ' + msg);
            }
        }).fail(function() {
            $result.css('color', '#dc3232').text('✗ ' + <?php echo wp_json_encode($label_failed); ?>);
        }).always(function() {
            $btn.prop('disabled', false).text(<?php echo wp_json_encode($label_button); ?>);
        });
    });
});
</script>
        <?php
    }
}
add_action( 'admin_print_footer_scripts', 'edd_paddle_test_connection_inline_js' );
