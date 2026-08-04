<?php

namespace EDDPaddle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use EDD_Paddle_API;
use Mockery;

require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-api.php';

class APITest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_api_uses_sandbox_url_when_sandbox_is_enabled() {
        $api_key = 'sbox_api_123';
        
        // Mock wp_remote_request to return successful response
        Functions\expect('wp_remote_request')
            ->once()
            ->with('https://sandbox-api.paddle.com/transactions', Mockery::on(function($args) use ($api_key) {
                return $args['headers']['Authorization'] === 'Bearer ' . $api_key;
            }))
            ->andReturn([
                'response' => ['code' => 201],
                'body' => json_encode(['data' => ['id' => 'txn_123']])
            ]);

        Functions\expect('is_wp_error')->andReturn(false);
        Functions\expect('wp_remote_retrieve_response_code')->andReturn(201);
        Functions\expect('wp_remote_retrieve_body')->andReturn(json_encode(['data' => ['id' => 'txn_123']]));

        $api = new EDD_Paddle_API($api_key, true);
        $result = $api->create_transaction(['items' => []]);

        $this->assertEquals('txn_123', $result['data']['id']);
    }

    public function test_api_uses_live_url_when_sandbox_is_disabled() {
        $api_key = 'live_api_123';
        
        Functions\expect('wp_remote_request')
            ->once()
            ->with('https://api.paddle.com/transactions', Mockery::on(function($args) use ($api_key) {
                return $args['headers']['Authorization'] === 'Bearer ' . $api_key;
            }))
            ->andReturn([
                'response' => ['code' => 201],
                'body' => json_encode(['data' => ['id' => 'txn_123']])
            ]);

        Functions\expect('is_wp_error')->andReturn(false);
        Functions\expect('wp_remote_retrieve_response_code')->andReturn(201);
        Functions\expect('wp_remote_retrieve_body')->andReturn(json_encode(['data' => ['id' => 'txn_123']]));

        $api = new EDD_Paddle_API($api_key, false);
        $result = $api->create_transaction(['items' => []]);

        $this->assertEquals('txn_123', $result['data']['id']);
    }

    public function test_api_returns_wp_error_on_request_failure() {
        $api_key = 'sbox_api_123';
        
        // Mock is_wp_error to return true
        Functions\expect('wp_remote_request')->andReturn(new \WP_Error('api_error', 'Request failed'));
        Functions\expect('is_wp_error')->andReturn(true);

        $api = new EDD_Paddle_API($api_key, true);
        $result = $api->create_transaction(['items' => []]);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('api_error', $result->get_error_code());
    }
}
