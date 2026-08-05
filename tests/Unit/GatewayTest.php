<?php

namespace EDDPaddle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Actions;

class GatewayTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_plugin_registers_paddle_gateway() {
        // Require the main plugin entry point
        // Since the main plugin file registers hooks when loaded, we mock WordPress hook registration.
        
        // Assert that the filter for registering payment gateways has been added.
        // The expected filter hook is 'edd_payment_gateways'
        require_once dirname(dirname(__DIR__)) . '/edd-paddle-gateway.php';

        $this->assertTrue(
            has_filter('edd_payment_gateways', 'edd_paddle_register_gateway') !== false,
            'The gateway registration filter edd_paddle_register_gateway was not hooked to edd_payment_gateways.'
        );
    }

    public function test_gateway_registration_adds_paddle_to_gateways_array() {
        // Assert that edd_paddle_register_gateway returns the gateways array with paddle included
        $gateways = [
            'paypal' => ['admin_label' => 'PayPal', 'checkout_label' => 'PayPal']
        ];

        $registered = edd_paddle_register_gateway($gateways);

        $this->assertArrayHasKey('paddle', $registered);
        $this->assertEquals('Paddle', $registered['paddle']['admin_label']);
        $this->assertEquals('Paddle', $registered['paddle']['checkout_label']);
    }
}
