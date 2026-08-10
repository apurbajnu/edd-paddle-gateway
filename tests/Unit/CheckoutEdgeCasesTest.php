<?php

namespace EDDPaddle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class CheckoutEdgeCasesTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', true );
        }

        Functions\when('current_time')->justReturn('2026-05-24 10:00:00');
        Functions\when('status_header')->justReturn(null);
        Functions\when('add_query_arg')->justReturn('https://example.com/?payment-confirmation=paddle&payment-id=1');
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('edd_get_success_page_uri')->justReturn('https://example.com/success');
        Functions\when('edd_get_currency')->justReturn('USD');

        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-api.php';
        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-checkout.php';
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_build_payload_with_single_price() {
        $download_id = 101;

        Functions\when('edd_has_variable_prices')->justReturn(false);
        Functions\when('get_post_meta')->alias(function($id, $key, $single) use ($download_id) {
            if ($key === 'edd_paddle_price_id') return 'pri_single';
            return '';
        });

        $purchase_data = [
            'downloads' => [
                ['id' => $download_id, 'options' => [], 'quantity' => 1]
            ],
            'user_info' => [
                'id' => 1,
                'email' => 'test@example.com',
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'discount' => 'none',
            ],
        ];

        $payload = edd_paddle_build_transaction_payload($purchase_data, 500);

        $this->assertCount(1, $payload['items']);
        $this->assertEquals('pri_single', $payload['items'][0]['price_id']);
        $this->assertEquals(1, $payload['items'][0]['quantity']);
        $this->assertEquals(500, $payload['custom_data']['edd_payment_id']);
        $this->assertEquals('test@example.com', $payload['customer']['email']);
        $this->assertEquals('Jane Smith', $payload['customer']['name']);
        $this->assertEquals('automatic', $payload['collection_mode']);
    }

    public function test_build_payload_with_variable_prices() {
        $download_id = 102;

        Functions\when('edd_has_variable_prices')->justReturn(true);
        Functions\when('get_post_meta')->alias(function($id, $key, $single) use ($download_id) {
            $map = [
                'edd_paddle_variable_prices' => [2 => 'pri_business'],
                '_edd_default_price_id' => '2',
            ];
            return $map[$key] ?? '';
        });

        $purchase_data = [
            'downloads' => [
                ['id' => $download_id, 'options' => ['price_id' => 2], 'quantity' => 1]
            ],
            'user_info' => [
                'id' => 1,
                'email' => 'buyer@example.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'discount' => 'none',
            ],
        ];

        $payload = edd_paddle_build_transaction_payload($purchase_data, 501);

        $this->assertCount(1, $payload['items']);
        $this->assertEquals('pri_business', $payload['items'][0]['price_id']);
    }

    public function test_build_payload_aggregates_duplicate_price_ids() {
        $download_id = 103;

        Functions\when('edd_has_variable_prices')->justReturn(false);
        Functions\when('get_post_meta')->alias(function($id, $key, $single) {
            if ($key === 'edd_paddle_price_id') return 'pri_same';
            return '';
        });

        $purchase_data = [
            'downloads' => [
                ['id' => $download_id, 'options' => [], 'quantity' => 2],
                ['id' => $download_id, 'options' => [], 'quantity' => 3],
            ],
            'user_info' => [
                'id' => 1,
                'email' => 'test@example.com',
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'discount' => 'none',
            ],
        ];

        $payload = edd_paddle_build_transaction_payload($purchase_data, 502);

        $this->assertCount(1, $payload['items']);
        $this->assertEquals('pri_same', $payload['items'][0]['price_id']);
        $this->assertEquals(5, $payload['items'][0]['quantity']);
    }

    public function test_build_payload_includes_discount_when_present() {
        $download_id = 104;
        $discount_id = 77;

        Functions\when('edd_has_variable_prices')->justReturn(false);
        Functions\when('edd_get_discount_id_by_code')->justReturn($discount_id);

        Functions\when('get_post_meta')->alias(function($id, $key, $single) use ($discount_id, $download_id) {
            if ($id === $download_id && $key === 'edd_paddle_price_id') return 'pri_disc';
            if ($id === $discount_id && $key === 'edd_paddle_discount_id') return 'dsc_paddle_20';
            return '';
        });

        $purchase_data = [
            'downloads' => [
                ['id' => $download_id, 'options' => [], 'quantity' => 1]
            ],
            'user_info' => [
                'id' => 1,
                'email' => 'test@example.com',
                'first_name' => 'Test',
                'last_name' => 'User',
                'discount' => 'SAVE20',
            ],
        ];

        $payload = edd_paddle_build_transaction_payload($purchase_data, 503);

        // Paddle API uses 'discount_id' (singular) to auto-apply catalog discounts
        $this->assertArrayHasKey('discount_id', $payload);
        $this->assertEquals('dsc_paddle_20', $payload['discount_id']);
    }

    public function test_build_payload_no_discount_when_none_applied() {
        $download_id = 105;

        Functions\when('edd_has_variable_prices')->justReturn(false);
        Functions\when('get_post_meta')->alias(function($id, $key, $single) {
            if ($key === 'edd_paddle_price_id') return 'pri_nodisc';
            return '';
        });

        $purchase_data = [
            'downloads' => [
                ['id' => $download_id, 'options' => [], 'quantity' => 1]
            ],
            'user_info' => [
                'id' => 1,
                'email' => 'test@example.com',
                'first_name' => 'Test',
                'last_name' => 'User',
                'discount' => 'none',
            ],
        ];

        $payload = edd_paddle_build_transaction_payload($purchase_data, 504);

        $this->assertArrayNotHasKey('discount_id', $payload);
    }

    public function test_build_payload_skips_download_without_price_id() {
        $download_id = 106;

        Functions\when('edd_has_variable_prices')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');

        $purchase_data = [
            'downloads' => [
                ['id' => $download_id, 'options' => [], 'quantity' => 1]
            ],
            'user_info' => [
                'id' => 1,
                'email' => 'test@example.com',
                'first_name' => 'Test',
                'last_name' => 'User',
                'discount' => 'none',
            ],
        ];

        $payload = edd_paddle_build_transaction_payload($purchase_data, 505);

        $this->assertEmpty($payload['items']);
    }

    public function test_build_payload_default_quantity_is_1() {
        $download_id = 107;

        Functions\when('edd_has_variable_prices')->justReturn(false);
        Functions\when('get_post_meta')->alias(function($id, $key, $single) {
            if ($key === 'edd_paddle_price_id') return 'pri_qty';
            return '';
        });

        // No quantity specified
        $purchase_data = [
            'downloads' => [
                ['id' => $download_id, 'options' => []]
            ],
            'user_info' => [
                'id' => 1,
                'email' => 'test@example.com',
                'first_name' => 'Test',
                'last_name' => 'User',
                'discount' => 'none',
            ],
        ];

        $payload = edd_paddle_build_transaction_payload($purchase_data, 506);

        $this->assertEquals(1, $payload['items'][0]['quantity']);
    }

    public function test_build_payload_uses_user_email_from_user_info() {
        $download_id = 108;

        Functions\when('edd_has_variable_prices')->justReturn(false);
        Functions\when('get_post_meta')->alias(function($id, $key, $single) {
            if ($key === 'edd_paddle_price_id') return 'pri_email';
            return '';
        });

        $purchase_data = [
            'downloads' => [
                ['id' => $download_id, 'options' => [], 'quantity' => 1]
            ],
            'user_info' => [
                'id' => 1,
                'email' => 'from-user-info@example.com',
                'first_name' => 'Test',
                'last_name' => 'User',
                'discount' => 'none',
            ],
        ];

        $payload = edd_paddle_build_transaction_payload($purchase_data, 507);

        $this->assertEquals('from-user-info@example.com', $payload['customer']['email']);
    }

    public function test_build_payload_has_return_url_in_checkout() {
        $download_id = 109;

        Functions\when('edd_has_variable_prices')->justReturn(false);
        Functions\when('get_post_meta')->alias(function($id, $key, $single) {
            if ($key === 'edd_paddle_price_id') return 'pri_url';
            return '';
        });

        $purchase_data = [
            'downloads' => [
                ['id' => $download_id, 'options' => [], 'quantity' => 1]
            ],
            'user_info' => [
                'id' => 1,
                'email' => 'test@example.com',
                'first_name' => 'Test',
                'last_name' => 'User',
                'discount' => 'none',
            ],
        ];

        $payload = edd_paddle_build_transaction_payload($purchase_data, 508);

        $this->assertArrayHasKey('checkout', $payload);
        $this->assertArrayHasKey('return_url', $payload['checkout']);
    }
}
