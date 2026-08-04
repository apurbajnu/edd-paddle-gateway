<?php

namespace EDDPaddle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class ProductSyncTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_sync_skipped_if_not_download_post_type() {
        Functions\expect('get_post_type')
            ->once()
            ->with(123)
            ->andReturn('post');

        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-product-sync.php';

        // Call sync function directly
        edd_paddle_sync_product_to_paddle(123);

        // Assert no API call or metadata updates occurred (implicitly verified via Mockery expectations not failing)
        $this->assertTrue(true);
    }

    public function test_sync_creates_new_paddle_product_and_price_for_standard_download() {
        $post_id = 456;
        
        Functions\expect('get_post_type')->andReturn('download');
        Functions\expect('get_post_meta')
            ->with($post_id, 'edd_paddle_product_id', true)
            ->andReturn(''); // No existing product ID
        
        Functions\expect('get_the_title')->andReturn('Awesome Product');
        Functions\expect('get_post_field')->andReturn('Product description');
        Functions\expect('edd_has_variable_prices')->andReturn(false);
        Functions\expect('edd_get_download_price')->andReturn('29.00');
        Functions\expect('edd_get_currency')->andReturn('USD');
        Functions\expect('edd_recurring')->andReturn(null);

        // Mock API Wrapper
        $mock_api = Mockery::mock('EDD_Paddle_API');
        $mock_api->shouldReceive('create_product')
            ->once()
            ->with([
                'name' => 'Awesome Product',
                'description' => 'Product description',
                'tax_category' => 'standard',
                'custom_data' => ['edd_download_id' => $post_id]
            ])
            ->andReturn(['data' => ['id' => 'prod_abc123']]);

        $mock_api->shouldReceive('create_price')
            ->once()
            ->with([
                'product_id' => 'prod_abc123',
                // No "Standard License" suffix by default — title-only description.
                'description' => 'Awesome Product',
                'unit_price' => [
                    'amount' => '2900', // Cents
                    'currency_code' => 'USD'
                ]
            ])
            ->andReturn(['data' => ['id' => 'pri_xyz789']]);

        // Mock metadata updates
        Functions\expect('update_post_meta')
            ->once()
            ->with($post_id, 'edd_paddle_product_id', 'prod_abc123');

        Functions\expect('update_post_meta')
            ->once()
            ->with($post_id, 'edd_paddle_price_id', 'pri_xyz789');

        // Execute sync
        edd_paddle_sync_product_to_paddle($post_id, $mock_api);
        
        $this->assertTrue(true);
    }
}
