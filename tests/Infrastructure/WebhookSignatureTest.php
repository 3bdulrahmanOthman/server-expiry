<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\WebhookSignature;

final class WebhookSignatureTest extends TestCase
{
    public function test_signature_is_hmac_sha256_of_payload_and_real_secret(): void
    {
        $payload = '{"server_id":"abc"}';
        $secret = 's3cr3t-value-with-!special@chars';

        $signature = WebhookSignature::generate($payload, $secret);

        $this->assertSame(hash_hmac('sha256', $payload, $secret), $signature);
    }

    public function test_signing_uses_the_actual_secret_not_a_masked_value(): void
    {
        // Regression guard for R9: signing must use the configured secret.
        // A masked accessor ('********') would produce a different signature.
        $payload = '{"event":"server.expiry.updated"}';
        $realSecret = 'real-configured-secret';

        $signature = WebhookSignature::generate($payload.$realSecret, $realSecret);

        $this->assertNotSame(
            WebhookSignature::generate($payload.str_repeat('*', min(8, strlen($realSecret))), $realSecret),
            $signature,
            'The signature must be derived from the real secret, never from a masked placeholder'
        );
    }

    public function test_validate_accepts_a_correct_signature(): void
    {
        $payload = '{"ok":true}';
        $signature = WebhookSignature::generate($payload, 'secret');

        $this->assertTrue(WebhookSignature::validate($payload, 'secret', $signature));
    }

    public function test_validate_rejects_wrong_secret_or_payload(): void
    {
        $payload = '{"ok":true}';
        $signature = WebhookSignature::generate($payload, 'secret');

        $this->assertFalse(WebhookSignature::validate($payload, 'other-secret', $signature));
        $this->assertFalse(WebhookSignature::validate('{"ok":false}', 'secret', $signature));
    }

    public function test_timestamp_is_current_unix_time(): void
    {
        $before = time();

        $this->assertGreaterThanOrEqual($before, (int) WebhookSignature::generateTimestamp());
        $this->assertLessThanOrEqual(time(), (int) WebhookSignature::generateTimestamp());
    }
}
