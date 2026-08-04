<?php

namespace EDDPaddle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class ProductSyncEdgeCasesTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-api.php';
        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-product-sync.php';
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_sync_updates_existing_product() {
        $post_id = 200;

        Functions\when('get_post_type')->justReturn('download');
        Functions\when('get_the_title')->justReturn('Updated Product');
        Functions\when('get_post_field')->justReturn('New description');
        Functions\when('edd_has_variable_prices')->justReturn(false);
        Functions\when('edd_get_download_price')->justReturn('15.00');
        Functions\when('edd_get_currency')->justReturn('USD');
        Functions\when('edd_recurring')->justReturn(null);

        Functions\when('get_post_meta')->alias(function($id, $key, $single) use ($post_id) {
            $map = [
                'edd_paddle_product_id' => 'prod_existing',
                'edd_paddle_price_id' => 'pri_existing',
                '_edd_paddle_last_amount' => '1500',
            ];
            return $map[$key] ?? '';
        });

        Functions\when('update_post_meta')->justReturn(true);

        $mock_api = Mockery::mock('EDD_Paddle_API');
        $mock_api->shouldReceive('update_product')
            ->once()
            ->with('prod_existing', Mockery::on(function($arg) {
                return $arg['name'] === 'Updated Product';
            }))
            ->andReturn(['data' => ['id' => 'prod_existing']]);

        // Amount unchanged → existing price gets its description updated, not recreated.
        $mock_api->shouldReceive('update_price')
            ->once()
            ->with('pri_existing', Mockery::on(function($arg) {
                return isset($arg['description']);
            }))
            ->andReturn(['data' => ['id' => 'pri_existing']]);

        // Price should NOT be created (amount unchanged)
        $mock_api->shouldNotReceive('create_price');

        edd_paddle_sync_product_to_paddle($post_id, $mock_api);
        $this->assertTrue(true);
    }

    public function test_sync_creates_new_product_when_none_exists() {
        $post_id = 201;

        Functions\when('get_post_type')->justReturn('download');
        Functions\when('get_the_title')->justReturn('New Product');
        Functions\when('get_post_field')->justReturn('');
        Functions\when('wp_trim_words')->justReturn('Trimmed content');
        Functions\when('edd_has_variable_prices')->justReturn(false);
        Functions\when('edd_get_download_price')->justReturn('25.00');
        Functions\when('edd_get_currency')->justReturn('USD');
        Functions\when('edd_recurring')->justReturn(null);

        Functions\when('get_post_meta')->alias(function($id, $key, $single) {
            return '';
        });

        $meta_updates = [];
        Functions\when('update_post_meta')->alias(function($id, $key, $val) use (&$meta_updates) {
            $meta_updates[$key] = $val;
        });

        $mock_api = Mockery::mock('EDD_Paddle_API');
        $mock_api->shouldReceive('create_product')
            ->once()
            ->andReturn(['data' => ['id' => 'prod_new']]);
        $mock_api->shouldReceive('create_price')
            ->once()
            ->with(Mockery::on(function($arg) {
                return $arg['product_id'] === 'prod_new'
                    && $arg['unit_price']['amount'] === '2500'
                    && $arg['unit_price']['currency_code'] === 'USD';
            }))
            ->andReturn(['data' => ['id' => 'pri_new']]);

        edd_paddle_sync_product_to_paddle($post_id, $mock_api);

        $this->assertEquals('prod_new', $meta_updates['edd_paddle_product_id']);
        $this->assertEquals('pri_new', $meta_updates['edd_paddle_price_id']);
        $this->assertEquals('2500', $meta_updates['_edd_paddle_last_amount']);
    }

    public function test_sync_returns_early_on_product_creation_error() {
        $post_id = 202;

        Functions\when('get_post_type')->justReturn('download');
        Functions\when('get_the_title')->justReturn('Fail Product');
        Functions\when('get_post_field')->justReturn('Desc');

        Functions\when('get_post_meta')->alias(function($id, $key, $single) {
            return '';
        });

        $mock_api = Mockery::mock('EDD_Paddle_API');
        $mock_api->shouldReceive('create_product')
            ->once()
            ->andReturn(new \WP_Error('api_error', 'Product creation failed'));
        $mock_api->shouldNotReceive('create_price');

        edd_paddle_sync_product_to_paddle($post_id, $mock_api);
        $this->assertTrue(true);
    }

    public function test_price_not_recreated_when_amount_unchanged() {
        $post_id = 207;

        Functions\when('get_post_type')->justReturn('download');
        Functions\when('get_the_title')->justReturn('Same Price');
        Functions\when('get_post_field')->justReturn('Desc');
        Functions\when('edd_has_variable_prices')->justReturn(false);
        Functions\when('edd_get_download_price')->justReturn('19.99');
        Functions\when('edd_get_currency')->justReturn('USD');
        Functions\when('edd_recurring')->justReturn(null);

        Functions\when('get_post_meta')->alias(function($id, $key, $single) use ($post_id) {
            $map = [
                'edd_paddle_product_id' => 'prod_unchanged',
                'edd_paddle_price_id' => 'pri_unchanged',
                '_edd_paddle_last_amount' => '1999', // Same as 19.99 in cents
            ];
            return $map[$key] ?? '';
        });
        Functions\when('update_post_meta')->justReturn(true);

        $mock_api = Mockery::mock('EDD_Paddle_API');
        $mock_api->shouldReceive('update_product')->once();
        // Amount unchanged → description-only update via PATCH (not a new price create).
        $mock_api->shouldReceive('update_price')
            ->once()
            ->with('pri_unchanged', Mockery::on(function($arg) {
                return isset($arg['description']);
            }))
            ->andReturn(['data' => ['id' => 'pri_unchanged']]);
        $mock_api->shouldNotReceive('create_price');

        edd_paddle_sync_product_to_paddle($post_id, $mock_api);
        $this->assertTrue(true);
    }

    /**
     * Zero-decimal currencies (JPY, KRW) must not be multiplied by 100.
     * ¥1000 should reach Paddle as "1000", not "100000".
     */
    public function test_zero_decimal_currency_not_multiplied_to_cents() {
        $post_id = 208;

        Functions\when('get_post_type')->justReturn('download');
        Functions\when('get_the_title')->justReturn('JPY Product');
        Functions\when('get_post_field')->justReturn('Desc');
        Functions\when('edd_has_variable_prices')->justReturn(false);
        Functions\when('edd_get_download_price')->justReturn('1000');
        Functions\when('edd_get_currency')->justReturn('JPY');
        Functions\when('edd_recurring')->justReturn(null);

        Functions\when('get_post_meta')->alias(function($id, $key, $single) {
            $map = [
                'edd_paddle_product_id' => '', // force create_product path
            ];
            return $map[$key] ?? '';
        });
        Functions\when('update_post_meta')->justReturn(true);

        $captured_price = null;
        $mock_api = Mockery::mock('EDD_Paddle_API');
        $mock_api->shouldReceive('create_product')
            ->once()
            ->andReturn(['data' => ['id' => 'prod_jpy_new']]);
        $mock_api->shouldReceive('create_price')
            ->once()
            ->andReturnUsing(function($body) use (&$captured_price) {
                $captured_price = $body;
                return ['data' => ['id' => 'pri_jpy']];
            });

        edd_paddle_sync_product_to_paddle($post_id, $mock_api);

        $this->assertNotNull($captured_price);
        $this->assertEquals('1000', $captured_price['unit_price']['amount']);
        $this->assertEquals('JPY', $captured_price['unit_price']['currency_code']);
    }

    /**
     * EDD 'semiyear' period must map to Paddle month/6 (Paddle has no 'semiyear' interval).
     */
}
