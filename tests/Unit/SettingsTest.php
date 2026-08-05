<?php

namespace EDDPaddle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;
use Mockery;

class SettingsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // admin_url and __ are already defined globally in tests/bootstrap.php,
        // so we can't stub them here (Patchwork can't redefine). Stub only the
        // functions that admin/settings.php needs and that aren't pre-defined.
        Functions\when('wp_create_nonce')->justReturn('fake_nonce');
        Functions\when('wp_json_encode')->alias(function($v) { return json_encode($v); });
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_settings_section_is_registered() {
        // Load the settings file
        require_once dirname(dirname(__DIR__)) . '/admin/settings.php';

        $sections = [];
        $registered_sections = edd_paddle_register_settings_section($sections);

        $this->assertArrayHasKey('paddle', $registered_sections);
        $this->assertEquals('Paddle', $registered_sections['paddle']);
    }

    public function test_settings_fields_are_registered() {
        require_once dirname(dirname(__DIR__)) . '/admin/settings.php';

        $settings = [];
        $registered_settings = edd_paddle_register_settings_fields($settings);

        $this->assertArrayHasKey('paddle', $registered_settings);
        $fields = $registered_settings['paddle'];

        $this->assertArrayHasKey('edd_paddle_settings_header', $fields);
        $this->assertArrayHasKey( 'edd_paddle_mode', $fields );
        $this->assertArrayHasKey( 'edd_paddle_sandbox_api_key', $fields );
        $this->assertArrayHasKey( 'edd_paddle_live_api_key', $fields );
        $this->assertArrayHasKey( 'edd_paddle_sandbox_client_token', $fields );
        $this->assertArrayHasKey( 'edd_paddle_live_client_token', $fields );
        $this->assertArrayHasKey( 'edd_paddle_sandbox_webhook_secret', $fields );
        $this->assertArrayHasKey( 'edd_paddle_live_webhook_secret', $fields );
        // Free version does NOT register checkout_type — pro add-on adds it via filter.
        $this->assertArrayNotHasKey( 'edd_paddle_checkout_type', $fields );
    }

    public function test_settings_url_is_registered() {
        require_once dirname(dirname(__DIR__)) . '/admin/settings.php';

        $url = edd_paddle_gateway_settings_url();
        $this->assertStringContainsString('section=paddle', $url);
    }

    /**
     * Test Connection returns success when API key authenticates.
     */
    public function test_run_test_connection_returns_success_on_valid_key() {
        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-api.php';
        require_once dirname(dirname(__DIR__)) . '/admin/settings.php';

        $mock_api = Mockery::mock('EDD_Paddle_API');
        $mock_api->shouldReceive('request')
            ->once()
            ->with('GET', 'transactions?per_page=1', [])
            ->andReturn(['data' => []]);
        Functions\when('edd_paddle_get_api')->justReturn($mock_api);

        $result = edd_paddle_run_test_connection();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('successful', $result['message']);
    }

    /**
     * Test Connection returns a tailored message when the API rejects auth.
     */
    public function test_run_test_connection_returns_typed_message_on_auth_failure() {
        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-api.php';
        require_once dirname(dirname(__DIR__)) . '/admin/settings.php';

        $mock_api = Mockery::mock('EDD_Paddle_API');
        $mock_api->shouldReceive('request')
            ->once()
            ->andReturn(new \WP_Error('paddle_forbidden', 'Unauthorized request'));
        Functions\when('edd_paddle_get_api')->justReturn($mock_api);

        $result = edd_paddle_run_test_connection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Authentication failed', $result['message']);
    }
}
