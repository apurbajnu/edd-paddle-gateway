<?php

namespace EDDPaddle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class CheckoutTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\expect('add_query_arg')->andReturn('https://example.com/success');
        Functions\expect('home_url')->andReturn('https://example.com');
        Functions\expect('edd_has_variable_prices')->andReturn(false);
        Functions\expect('edd_get_success_page_uri')->andReturn('https://example.com/success');
        Functions\when('wp_parse_url')->alias(function ($url, $component = -1) {
            return parse_url($url, $component);
        });
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_process_purchase_creates_payment_and_redirects() {
        $payment_id = 789;
        
        $purchase_data = [
            'downloads' => [
                ['id' => 101, 'options' => []]
            ],
            'user_info' => [
                'id' => 1,
                'email' => 'buyer@example.com',
                'first_name' => 'John',
                'last_name' => 'Doe'
            ],
            'price' => '29.00',
            'gateway' => 'paddle'
        ];

        // Mock EDD Insert Payment
        Functions\expect('edd_insert_payment')
            ->once()
            ->andReturn($payment_id);

        // Mock post meta retrieval for price IDs
        Functions\expect('get_post_meta')
            ->with(101, 'edd_paddle_price_id', true)
            ->andReturn('pri_xyz789');

        // Mock EDD options — single dispatcher handles all argument variants.
        Functions\when('edd_get_option')->alias(function($key, $default = '') {
            switch ($key) {
                case 'edd_paddle_mode': return 'sandbox';
                case 'edd_paddle_sandbox_api_key': return 'sbox_api_123';
                case 'edd_paddle_checkout_type': return 'redirect';
                default: return $default;
            }
        });

        Functions\expect('edd_get_currency')
            ->andReturn('USD');

        // Mock API call to create transaction
        $mock_api = Mockery::mock('EDD_Paddle_API');
        $mock_api->shouldReceive('create_transaction')
            ->once()
            ->with(Mockery::on(function($body) use ($payment_id) {
                return $body['custom_data']['edd_payment_id'] === $payment_id 
                    && $body['items'][0]['price_id'] === 'pri_xyz789';
            }))
            ->andReturn(['data' => [
                'id' => 'txn_999',
                'checkout' => ['url' => 'https://checkout.paddle.com/txn_999']
            ]]);

        // Mock edd_paddle_get_api function to return our mock API instance
        Functions\expect('edd_paddle_get_api')
            ->once()
            ->andReturn($mock_api);

        // Mock redirect and exit
        Functions\expect('wp_redirect')
            ->once()
            ->with('https://checkout.paddle.com/txn_999');

        // Stub helpers reached while persisting the transaction ID.
        Functions\when('update_post_meta')->justReturn(true);

        // Load file
        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-checkout.php';

        // Call the checkout process
        edd_paddle_process_purchase($purchase_data);

        $this->assertTrue(true);
    }

    /**
     * Paddle Billing v2 returns an overlay-style URL (on the merchant's own
     * domain with ?_ptxn=) for accounts without Hosted Checkout approval.
     * Redirecting to that URL leaves the buyer stranded because no Paddle.js
     * is loaded in redirect mode. Detect this and render the interstitial
     * (which loads Paddle.js and opens Paddle.Checkout.open) instead.
     */
    public function test_redirect_mode_renders_interstitial_when_paddle_returns_overlay_url() {
        $purchase_data = [
            'downloads' => [
                ['id' => 101, 'options' => []]
            ],
            'user_info' => [
                'id' => 1,
                'email' => 'buyer@example.com',
                'first_name' => 'John',
                'last_name' => 'Doe'
            ],
            'price' => '29.00',
            'gateway' => 'paddle'
        ];

        Functions\expect('edd_insert_payment')
            ->once()
            ->andReturn(789);

        Functions\expect('get_post_meta')
            ->with(101, 'edd_paddle_price_id', true)
            ->andReturn('pri_xyz789');

        Functions\when('edd_get_option')->alias(function($key, $default = '') {
            switch ($key) {
                case 'edd_paddle_mode': return 'sandbox';
                case 'edd_paddle_sandbox_api_key': return 'sbox_api_123';
                case 'edd_paddle_checkout_type': return 'redirect';
                case 'edd_paddle_sandbox_client_token': return 'test_client_token';
                default: return $default;
            }
        });

        Functions\expect('edd_get_currency')->andReturn('USD');
        Functions\when('edd_get_checkout_uri')->justReturn('https://example.com/checkout');
        Functions\when('edd_empty_cart')->justReturn(null);
        Functions\when('update_post_meta')->justReturn(true);

        // Paddle returns an overlay-style URL on the merchant's own domain.
        // home_url() returns https://example.com (from setUp), so host matches.
        $mock_api = Mockery::mock('EDD_Paddle_API');
        $mock_api->shouldReceive('create_transaction')
            ->once()
            ->andReturn(['data' => [
                'id' => 'txn_overlay123',
                'checkout' => ['url' => 'https://example.com/checkout?_ptxn=txn_overlay123']
            ]]);

        Functions\expect('edd_paddle_get_api')
            ->once()
            ->andReturn($mock_api);

        // wp_redirect MUST NOT be called for overlay-style URLs. If the old
        // behavior runs, this stub throws and the test fails loudly.
        Functions\when('wp_redirect')->alias(function($url) {
            throw new \RuntimeException('wp_redirect must not be called for overlay URL; got: ' . $url);
        });

        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-checkout.php';

        ob_start();
        edd_paddle_process_purchase($purchase_data);
        $output = ob_get_clean();

        $this->assertStringContainsString('cdn.paddle.com/paddle/v2/paddle.js', $output, 'Interstitial must enqueue Paddle.js SDK.');
        $this->assertStringContainsString('txn_overlay123', $output, 'Interstitial must pass the transaction ID to Paddle.Checkout.open.');
    }
}
