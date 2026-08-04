<?php

namespace EDDPaddle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class WebhookTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\expect('current_time')->andReturn('2026-05-24 10:00:00');
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_signature_verification_success() {
        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-webhook.php';

        $secret = 'pdl_ntf_123';
        $ts = time();
        $raw_body = '{"event_type":"transaction.completed","data":{"id":"txn_abc123"}}';
        $payload = $ts . ':' . $raw_body;
        $h1 = hash_hmac('sha256', $payload, $secret);

        $signature_header = "ts={$ts};h1={$h1}";

        $result = edd_paddle_verify_webhook_signature($raw_body, $signature_header, $secret);
        $this->assertTrue($result);
    }

    public function test_signature_verification_fails_with_wrong_secret() {
        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-webhook.php';

        $secret = 'pdl_ntf_123';
        $ts = time();
        $raw_body = '{"event_type":"transaction.completed"}';
        $payload = $ts . ':' . $raw_body;
        $h1 = hash_hmac('sha256', $payload, 'wrong_secret');

        $signature_header = "ts={$ts};h1={$h1}";

        $result = edd_paddle_verify_webhook_signature($raw_body, $signature_header, $secret);
        $this->assertFalse($result);
    }

    public function test_signature_verification_fails_with_expired_timestamp() {
        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-webhook.php';

        $secret = 'pdl_ntf_123';
        $ts = time() - 700; // Over 10 minutes ago
        $raw_body = '{"event_type":"transaction.completed"}';
        $payload = $ts . ':' . $raw_body;
        $h1 = hash_hmac('sha256', $payload, $secret);

        $signature_header = "ts={$ts};h1={$h1}";

        $result = edd_paddle_verify_webhook_signature($raw_body, $signature_header, $secret);
        $this->assertFalse($result);
    }

    public function test_webhook_completes_order_on_transaction_completed() {
        $payment_id = 999;
        $transaction_id = 'txn_abc123';
        
        $raw_body = json_encode([
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => $transaction_id,
                'status' => 'completed',
                'custom_data' => [
                    'edd_payment_id' => $payment_id
                ]
            ]
        ]);

        Functions\expect('edd_get_option')
            ->with('edd_paddle_webhook_secret', '')
            ->andReturn('pdl_ntf_123');

        // Mock EDD payment status updates
        Functions\expect('edd_get_payment')
            ->once()
            ->with($payment_id)
            ->andReturn((object) ['status' => 'pending']);

        Functions\expect('edd_update_payment_status')
            ->once()
            ->with($payment_id, 'complete');

        Functions\expect('update_post_meta')
            ->once()
            ->with($payment_id, '_edd_payment_transaction_id', $transaction_id);

        Functions\expect('edd_insert_payment_note')
            ->once()
            ->with($payment_id, Mockery::type('string'));

        require_once dirname(dirname(__DIR__)) . '/includes/class-edd-paddle-webhook.php';

        // Direct call to process raw payload
        edd_paddle_process_webhook_payload($raw_body);

        $this->assertTrue(true);
    }
}
