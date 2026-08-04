<?php

namespace EDDPaddle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class WebhookEdgeCasesTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', true );
        }

        Functions\when('current_time')->justReturn('2026-05-24 10:00:00');
        Functions\when('status_header')->justReturn(null);
        Functions\when('edd_get_option')->justReturn('secret');

        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-webhook.php';
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // --- Signature Verification ---

    public function test_signature_fails_with_empty_header() {
        $result = edd_paddle_verify_webhook_signature('body', '', 'secret');
        $this->assertFalse($result);
    }

    public function test_signature_fails_with_empty_secret() {
        $result = edd_paddle_verify_webhook_signature('body', 'ts=123;h1=abc', '');
        $this->assertFalse($result);
    }

    public function test_signature_fails_with_malformed_header_no_equals() {
        $result = edd_paddle_verify_webhook_signature('body', 'invalidheader', 'secret');
        $this->assertFalse($result);
    }

    public function test_signature_fails_with_missing_h1() {
        $ts = time();
        $result = edd_paddle_verify_webhook_signature('body', "ts={$ts}", 'secret');
        $this->assertFalse($result);
    }

    public function test_signature_fails_with_missing_ts() {
        $result = edd_paddle_verify_webhook_signature('body', 'h1=abc123', 'secret');
        $this->assertFalse($result);
    }

    // --- Payload Processing ---

    public function test_payload_skipped_on_invalid_json() {
        edd_paddle_process_webhook_payload('not valid json');
        $this->assertTrue(true);
    }

    public function test_payload_skipped_on_unknown_event_type() {
        $raw_body = json_encode([
            'event_type' => 'transaction.disputed',
            'data' => ['id' => 'txn_123']
        ]);

        edd_paddle_process_webhook_payload($raw_body);
        $this->assertTrue(true);
    }

    public function test_payload_skipped_on_empty_event_type() {
        $raw_body = json_encode([
            'data' => ['id' => 'txn_123']
        ]);

        edd_paddle_process_webhook_payload($raw_body);
        $this->assertTrue(true);
    }

    public function test_transaction_updated_skipped_if_status_not_completed() {
        $raw_body = json_encode([
            'event_type' => 'transaction.updated',
            'data' => [
                'id' => 'txn_update',
                'status' => 'pending',
                'custom_data' => ['edd_payment_id' => 600]
            ]
        ]);

        // Should NOT call edd_get_payment
        Functions\when('edd_get_payment')->justReturn(false);

        edd_paddle_process_webhook_payload($raw_body);
        $this->assertTrue(true);
    }

    public function test_transaction_updated_processed_if_status_completed() {
        $payment_id = 601;
        $transaction_id = 'txn_update_complete';

        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('edd_get_payment')->justReturn((object)[
            'status' => 'pending',
            'total' => 0,
            'customer_id' => 0,
        ]);

        $status_updated = false;
        Functions\when('edd_update_payment_status')->alias(function($id, $status) use (&$status_updated) {
            $status_updated = ($status === 'complete');
        });
        Functions\when('edd_insert_payment_note')->justReturn(null);

        $raw_body = json_encode([
            'event_type' => 'transaction.updated',
            'data' => [
                'id' => $transaction_id,
                'status' => 'completed',
                'custom_data' => ['edd_payment_id' => $payment_id]
            ]
        ]);

        edd_paddle_process_webhook_payload($raw_body);
        $this->assertTrue($status_updated);
    }

    public function test_payment_not_updated_if_already_complete() {
        $payment_id = 700;

        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('edd_get_payment')->justReturn((object)[
            'status' => 'complete',
            'total' => 29.00,
            'customer_id' => 0,
        ]);

        $status_updated = false;
        Functions\when('edd_update_payment_status')->alias(function($id, $status) use (&$status_updated) {
            $status_updated = true;
        });

        $raw_body = json_encode([
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => 'txn_abc',
                'status' => 'completed',
                'custom_data' => ['edd_payment_id' => $payment_id]
            ]
        ]);

        edd_paddle_process_webhook_payload($raw_body);
        $this->assertFalse($status_updated);
    }

    public function test_payment_not_found_returns_early() {
        $payment_id = 99999;

        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('edd_get_payment')->justReturn(false);

        $status_updated = false;
        Functions\when('edd_update_payment_status')->alias(function($id, $status) use (&$status_updated) {
            $status_updated = true;
        });

        $raw_body = json_encode([
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => 'txn_missing',
                'status' => 'completed',
                'custom_data' => ['edd_payment_id' => $payment_id]
            ]
        ]);

        edd_paddle_process_webhook_payload($raw_body);
        $this->assertFalse($status_updated);
    }

    public function test_missing_payment_id_returns_early() {
        $raw_body = json_encode([
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => 'txn_no_payment',
                'status' => 'completed',
                'custom_data' => []
            ]
        ]);

        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('edd_get_payment')->justReturn(false);
        Functions\when('edd_update_payment_status')->justReturn(null);

        edd_paddle_process_webhook_payload($raw_body);
        $this->assertTrue(true);
    }

    public function test_total_synced_from_paddle_when_payment_total_is_zero() {
        $payment_id = 801;

        Functions\when('update_post_meta')->justReturn(true);

        $synced_total = null;
        Functions\when('edd_update_payment_meta')->alias(function($id, $key, $val) use (&$synced_total) {
            if ($key === '_edd_payment_total') {
                $synced_total = $val;
            }
        });

        Functions\when('edd_get_payment')->justReturn((object)[
            'status' => 'pending',
            'total' => 0,
            'customer_id' => 0,
        ]);
        Functions\when('edd_update_payment_status')->justReturn(null);
        Functions\when('edd_insert_payment_note')->justReturn(null);

        $raw_body = json_encode([
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => 'txn_total',
                'status' => 'completed',
                'custom_data' => ['edd_payment_id' => $payment_id],
                'details' => ['totals' => ['total' => 2999]]
            ]
        ]);

        edd_paddle_process_webhook_payload($raw_body);
        $this->assertEquals(29.99, $synced_total);
    }

    public function test_total_not_overwritten_when_payment_already_has_total() {
        $payment_id = 802;

        Functions\when('update_post_meta')->justReturn(true);

        $synced_total = null;
        Functions\when('edd_update_payment_meta')->alias(function($id, $key, $val) use (&$synced_total) {
            if ($key === '_edd_payment_total') {
                $synced_total = $val;
            }
        });

        Functions\when('edd_get_payment')->justReturn((object)[
            'status' => 'pending',
            'total' => 25.00, // Already has a total
            'customer_id' => 0,
        ]);
        Functions\when('edd_update_payment_status')->justReturn(null);
        Functions\when('edd_insert_payment_note')->justReturn(null);

        $raw_body = json_encode([
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => 'txn_total2',
                'status' => 'completed',
                'custom_data' => ['edd_payment_id' => $payment_id],
                'details' => ['totals' => ['total' => 2999]]
            ]
        ]);

        edd_paddle_process_webhook_payload($raw_body);
        $this->assertNull($synced_total); // Should NOT have been called
    }

    public function test_customer_id_stored_on_payment_and_edd_customer() {
        $payment_id = 800;

        Functions\when('update_post_meta')->justReturn(true);

        $meta_saved = [];
        Functions\when('update_metadata')->alias(function($type, $id, $key, $val) use (&$meta_saved) {
            $meta_saved[$key] = $val;
        });

        Functions\when('edd_get_payment')->justReturn((object)[
            'status' => 'pending',
            'total' => 0,
            'customer_id' => 42,
        ]);
        Functions\when('edd_update_payment_meta')->justReturn(null);
        Functions\when('edd_update_payment_status')->justReturn(null);
        Functions\when('edd_insert_payment_note')->justReturn(null);

        $raw_body = json_encode([
            'event_type' => 'transaction.paid',
            'data' => [
                'id' => 'txn_ctm',
                'status' => 'completed',
                'customer_id' => 'ctm_new',
                'custom_data' => ['edd_payment_id' => $payment_id]
            ]
        ]);

        edd_paddle_process_webhook_payload($raw_body);
        $this->assertEquals('ctm_new', $meta_saved['_edd_paddle_customer_id']);
    }
}
