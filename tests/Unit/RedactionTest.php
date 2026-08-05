<?php

namespace EDDPaddle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the PII redaction helpers used to keep debug.log safe to share.
 *
 * These helpers are defined in tests/bootstrap.php so they're available before
 * any plugin include file is loaded; the plugin's own copies (in
 * edd-paddle-gateway.php) are function_exists-guarded so the implementations
 * stay in sync.
 */
class RedactionTest extends TestCase {
    public function test_pii_keys_list_includes_core_fields() {
        $keys = edd_paddle_pii_keys();
        $this->assertContains( 'email', $keys );
        $this->assertContains( 'first_name', $keys );
        $this->assertContains( 'last_name', $keys );
        $this->assertContains( 'phone', $keys );
        $this->assertContains( 'address', $keys );
        $this->assertContains( 'city', $keys );
        $this->assertContains( 'postal_code', $keys );
        $this->assertContains( 'country', $keys );
        $this->assertContains( 'password', $keys );
        $this->assertContains( 'api_key', $keys );
    }

    public function test_redact_pii_masks_known_keys_in_flat_array() {
        $input   = [
            'email'      => 'buyer@example.com',
            'name'       => 'Jane Doe',
            'phone'      => '+1-555-0100',
            'city'       => 'Berlin',
            'product_id' => 'pro_abc',
            'amount'     => 1999,
        ];
        $result = edd_paddle_redact_pii( $input );

        $this->assertEquals( '[redacted]', $result['email'] );
        $this->assertEquals( '[redacted]', $result['name'] );
        $this->assertEquals( '[redacted]', $result['phone'] );
        $this->assertEquals( '[redacted]', $result['city'] );
        // Non-PII keys preserved.
        $this->assertEquals( 'pro_abc', $result['product_id'] );
        $this->assertEquals( 1999, $result['amount'] );
    }

    public function test_redact_pii_walks_nested_arrays() {
        $input = [
            'customer' => [
                'email' => 'nested@example.com',
                'name'  => 'Nested Name',
                'id'    => 'ctm_123',
            ],
            'items'    => [
                [ 'price_id' => 'pri_1', 'quantity' => 2 ],
            ],
        ];
        $result = edd_paddle_redact_pii( $input );

        $this->assertEquals( '[redacted]', $result['customer']['email'] );
        $this->assertEquals( '[redacted]', $result['customer']['name'] );
        // Non-PII nested key preserved.
        $this->assertEquals( 'ctm_123', $result['customer']['id'] );
        $this->assertEquals( 'pri_1', $result['items'][0]['price_id'] );
        $this->assertEquals( 2, $result['items'][0]['quantity'] );
    }

    public function test_redact_pii_key_match_is_case_insensitive() {
        // Case is normalized; key names themselves must still match snake_case
        // (camelCase like 'FirstName' is intentionally NOT normalized — buyers
        // who use camelCase meta keys have chosen a non-PII-shaped namespace).
        $input   = [ 'EMAIL' => 'upper@example.com', 'First_Name' => 'Jane', 'postal_CODE' => '10115' ];
        $result = edd_paddle_redact_pii( $input );

        $this->assertEquals( '[redacted]', $result['EMAIL'] );
        $this->assertEquals( '[redacted]', $result['First_Name'] );
        $this->assertEquals( '[redacted]', $result['postal_CODE'] );
    }

    public function test_redact_pii_strips_embedded_emails_from_strings() {
        $raw   = 'Webhook from buyer@example.com for customer named John Doe <john@example.org>';
        $result = edd_paddle_redact_pii( $raw );

        $this->assertStringNotContainsString( 'buyer@example.com', $result );
        $this->assertStringNotContainsString( 'john@example.org', $result );
        $this->assertStringContainsString( '[email-redacted]', $result );
        // Names in plain prose aren't redacted (no reliable way to detect them).
        $this->assertStringContainsString( 'John Doe', $result );
    }

    public function test_redact_pii_handles_raw_json_body() {
        $raw   = '{"event_type":"transaction.completed","data":{"id":"txn_1","customer_id":"ctm_abc","custom_data":{"email":"cust@example.com","name":"Cust"}}}';
        $result = edd_paddle_redact_pii( $raw );

        // Embedded emails stripped (covers customer email in raw body).
        $this->assertStringNotContainsString( 'cust@example.com', $result );
        $this->assertStringContainsString( '[email-redacted]', $result );
    }

    public function test_redact_pii_passes_through_scalars_and_null() {
        $this->assertEquals( 42, edd_paddle_redact_pii( 42 ) );
        $this->assertEquals( 3.14, edd_paddle_redact_pii( 3.14 ) );
        $this->assertTrue( edd_paddle_redact_pii( true ) );
        $this->assertNull( edd_paddle_redact_pii( null ) );
    }

    public function test_redact_pii_redacts_pii_keys_inside_serialized_payload() {
        // Simulates what gets logged from edd_paddle_process_purchase — the
        // $payload variable that's sent to Paddle's create_transaction.
        $payload = [
            'items'       => [ [ 'price_id' => 'pri_xyz', 'quantity' => 1 ] ],
            'custom_data' => [ 'edd_payment_id' => 42 ],
            'customer'    => [
                'email' => 'buyer@example.com',
                'name'  => 'Jane Doe',
            ],
            'checkout'    => [ 'return_url' => 'https://example.com/?payment-id=42' ],
        ];

        $result = edd_paddle_redact_pii( $payload );

        $this->assertEquals( '[redacted]', $result['customer']['email'] );
        $this->assertEquals( '[redacted]', $result['customer']['name'] );
        $this->assertEquals( 'pri_xyz', $result['items'][0]['price_id'] );
        $this->assertEquals( 42, $result['custom_data']['edd_payment_id'] );
    }
}
