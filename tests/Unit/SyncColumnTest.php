<?php

namespace EDDPaddle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class SyncColumnTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        require_once dirname(dirname(__DIR__)) . '/edd-paddle-gateway.php';
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_add_sync_column_inserts_after_price() {
        $columns = [
            'cb' => '<input type="checkbox">',
            'title' => 'Title',
            'price' => 'Price',
            'date' => 'Date',
        ];

        $result = edd_paddle_add_sync_column($columns);

        $this->assertArrayHasKey('paddle_sync', $result);
        $keys = array_keys($result);
        $price_pos = array_search('price', $keys);
        $sync_pos = array_search('paddle_sync', $keys);
        $this->assertEquals($price_pos + 1, $sync_pos);
    }

    public function test_add_sync_column_appends_if_no_price_column() {
        $columns = [
            'cb' => '<input type="checkbox">',
            'title' => 'Title',
        ];

        $result = edd_paddle_add_sync_column($columns);

        $this->assertArrayHasKey( 'paddle_sync', $result );
        $this->assertEquals( 'Paddle Sync', $result['paddle_sync'] );
    }

    public function test_render_synced_status() {
        Functions\when('get_post_meta')->alias(function($id, $key, $single) {
            $map = [
                'edd_paddle_product_id' => 'prod_123',
                'edd_paddle_price_id' => 'pri_abc',
                'edd_paddle_variable_prices' => '',
            ];
            return $map[$key] ?? '';
        });
        Functions\when('esc_attr__')->alias(function($text) { return $text; });
        Functions\when('esc_html__')->alias(function($text) { return $text; });

        ob_start();
        edd_paddle_render_sync_column('paddle_sync', 1);
        $output = ob_get_clean();

        $this->assertStringContainsString('edd-paddle-status--synced', $output);
        $this->assertStringContainsString('Synced', $output);
    }

    public function test_render_partial_status_product_exists_no_prices() {
        Functions\when('get_post_meta')->alias(function($id, $key, $single) {
            $map = [
                'edd_paddle_product_id' => 'prod_123',
                'edd_paddle_price_id' => '',
                'edd_paddle_variable_prices' => '',
            ];
            return $map[$key] ?? '';
        });
        Functions\when('esc_attr__')->alias(function($text) { return $text; });
        Functions\when('esc_html__')->alias(function($text) { return $text; });

        ob_start();
        edd_paddle_render_sync_column('paddle_sync', 2);
        $output = ob_get_clean();

        $this->assertStringContainsString('edd-paddle-status--partial', $output);
        $this->assertStringContainsString('No prices', $output);
    }

    public function test_render_not_synced_status() {
        Functions\when('get_post_meta')->alias(function($id, $key, $single) {
            return '';
        });
        Functions\when('esc_attr__')->alias(function($text) { return $text; });
        Functions\when('esc_html__')->alias(function($text) { return $text; });

        ob_start();
        edd_paddle_render_sync_column('paddle_sync', 3);
        $output = ob_get_clean();

        $this->assertStringContainsString('edd-paddle-status--none', $output);
        $this->assertStringContainsString('Not synced', $output);
    }

    public function test_render_skips_non_paddle_column() {
        ob_start();
        edd_paddle_render_sync_column('some_other_column', 1);
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function test_render_synced_with_variable_prices() {
        Functions\when('get_post_meta')->alias(function($id, $key, $single) {
            $map = [
                'edd_paddle_product_id' => 'prod_456',
                'edd_paddle_price_id' => '',
                'edd_paddle_variable_prices' => [1 => 'pri_a', 2 => 'pri_b'],
            ];
            return $map[$key] ?? '';
        });
        Functions\when('esc_attr__')->alias(function($text) { return $text; });
        Functions\when('esc_html__')->alias(function($text) { return $text; });

        ob_start();
        edd_paddle_render_sync_column('paddle_sync', 4);
        $output = ob_get_clean();

        $this->assertStringContainsString('edd-paddle-status--synced', $output);
    }
}
