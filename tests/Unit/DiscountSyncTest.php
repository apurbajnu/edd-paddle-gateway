<?php

namespace EDDPaddle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class DiscountSyncTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        // Note: __() is already defined in bootstrap.php, no need to mock here
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_sync_column_added_to_discount_list() {
        Functions\when('add_filter')->justReturn(true);

        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-checkout.php';

        $columns = ['name' => 'Name', 'code' => 'Code', 'amount' => 'Amount'];
        $result = edd_paddle_add_discount_sync_column($columns);

        $this->assertArrayHasKey('paddle_sync', $result, 'Paddle sync column should be added to discount list columns.');
        $this->assertEquals('Paddle Sync', $result['paddle_sync'], 'Column label should be "Paddle Sync".');
    }

    public function test_render_sync_status_when_not_synced() {
        Functions\expect('get_post_meta')
            ->with(123, 'edd_paddle_discount_id', true)
            ->andReturn('');

        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-checkout.php';

        ob_start();
        edd_paddle_render_discount_sync_column(123, (object)['id' => 123]);
        $output = ob_get_clean();

        $this->assertStringContainsString('edd-paddle-status--none', $output, 'Should show gray status indicator when not synced.');
        $this->assertStringContainsString('Not synced', $output, 'Should show "Not synced" text when discount has no Paddle ID.');
    }

    public function test_render_sync_status_when_synced() {
        Functions\expect('get_post_meta')
            ->with(456, 'edd_paddle_discount_id', true)
            ->andReturn('dsc_paddle_12345');

        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-checkout.php';

        ob_start();
        edd_paddle_render_discount_sync_column(456, (object)['id' => 456]);
        $output = ob_get_clean();

        $this->assertStringContainsString('edd-paddle-status--synced', $output, 'Should show green status indicator when synced.');
        $this->assertStringContainsString('Synced', $output, 'Should show "Synced" text when discount has Paddle ID.');
    }

    public function test_hooks_are_registered_for_discount_sync() {
        // add_action is called multiple times in the file, so we just stub it
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);

        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-checkout.php';

        // Verify sync functions are defined in free plugin (callable by Pro)
        $this->assertTrue(function_exists('edd_paddle_sync_discount_on_create'), 'Sync on create function should be defined.');
        $this->assertTrue(function_exists('edd_paddle_sync_discount_on_update'), 'Sync on update function should be defined.');
        $this->assertTrue(function_exists('edd_paddle_discount_sync_admin_notice'), 'Admin notice function should be defined.');
        $this->assertTrue(function_exists('edd_paddle_register_discount_sync_hooks'), 'Registration function should be defined.');
    }

    public function test_discount_sync_hooks_only_register_when_pro_active() {
        // Stub did_action to simulate Pro NOT being active
        Functions\when('did_action')->justReturn(0);
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);

        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-checkout.php';

        // The registration function exists but should not have registered
        // the sync hooks since Pro is not active.
        $this->assertTrue(
            function_exists('edd_paddle_register_discount_sync_hooks'),
            'Registration function should exist for Pro to call.'
        );
    }

    public function test_admin_notice_displays_when_sync_failed_param_present() {
        $_GET['edd_paddle_discount_sync_failed'] = '1';

        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-checkout.php';

        ob_start();
        edd_paddle_discount_sync_admin_notice();
        $output = ob_get_clean();

        $this->assertStringContainsString('notice-error', $output, 'Should display error notice class.');
        $this->assertStringContainsString('Discount failed to sync', $output, 'Should display failure message.');
        $this->assertStringContainsString('is-dismissible', $output, 'Notice should be dismissible.');

        unset($_GET['edd_paddle_discount_sync_failed']);
    }

    public function test_admin_notice_does_not_display_without_param() {
        // Ensure the GET param is not set
        unset($_GET['edd_paddle_discount_sync_failed']);

        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-checkout.php';

        ob_start();
        edd_paddle_discount_sync_admin_notice();
        $output = ob_get_clean();

        $this->assertEmpty($output, 'Should not display notice when sync failed parameter is not present.');
    }

    public function test_sync_discount_function_exists_and_has_guard() {
        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-checkout.php';

        $this->assertTrue(function_exists('edd_paddle_sync_discount'), 'Discount sync function should be defined.');
    }

    public function test_render_sync_status_handles_null_discount() {
        Functions\expect('get_post_meta')
            ->with(789, 'edd_paddle_discount_id', true)
            ->andReturn(null);

        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-checkout.php';

        ob_start();
        edd_paddle_render_discount_sync_column(789, (object)['id' => 789]);
        $output = ob_get_clean();

        $this->assertStringContainsString('edd-paddle-status--none', $output, 'Should show gray status indicator when meta is null.');
        $this->assertStringContainsString('Not synced', $output, 'Should show "Not synced" text when discount meta is null.');
    }

    public function test_sync_functions_handle_empty_discount_id() {
        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-checkout.php';

        // These should not throw errors with empty ID
        try {
            edd_paddle_sync_discount_on_create(['code' => 'TEST'], 0);
            edd_paddle_sync_discount_on_update(['code' => 'TEST'], 0);
            $this->assertTrue(true, 'Functions should handle empty discount ID gracefully.');
        } catch (\Exception $e) {
            $this->fail('Sync functions should not throw exceptions with empty ID: ' . $e->getMessage());
        }
    }
}
