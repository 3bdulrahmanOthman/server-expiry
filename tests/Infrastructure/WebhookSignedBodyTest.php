<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\WebhookSignature;

/**
 * Regression guard for the double-encoded webhook body bug.
 *
 * The delivery service signs $jsonPayload (HMAC-SHA256 over payload+timestamp)
 * but used to hand that string to Http::post($url, $data), which JSON-encodes
 * its argument — so the bytes on the wire were a JSON string *containing* JSON,
 * while X-Signature covered the inner document. Any receiver verifying the
 * signature over the raw request body (the standard contract) rejected every
 * webhook. Verified live against Pelican rc.2: the wire body arrived as
 * "{\"server_id\":2,...}" while the signature only matched the decoded payload.
 *
 * The fix sends the signed bytes verbatim via withBody(). The HTTP call itself
 * needs the Laravel client, so — like the H6.1 wiring suite — this contract
 * pins the wiring in source, while the signature primitive runs for real in
 * WebhookSignatureTest.
 */
final class WebhookSignedBodyTest extends TestCase
{
    private const SERVICE = __DIR__.'/../../src/Infrastructure/Webhooks/WebhookDeliveryService.php';

    public function test_the_signed_payload_is_what_goes_on_the_wire(): void
    {
        $source = $this->source();

        $signaturePosition = strpos($source, 'WebhookSignature::generate($jsonPayload.$timestamp, $secretKey)');
        $sendPosition = strpos($source, '->withBody($jsonPayload');

        $this->assertNotFalse($signaturePosition, 'The signature must cover the single-encoded payload');
        $this->assertNotFalse($sendPosition, 'The request body must be the signed payload verbatim');
        $this->assertLessThan(
            $sendPosition,
            $signaturePosition,
            'The payload must be signed before the same bytes are sent'
        );
    }

    public function test_the_payload_is_never_handled_to_post_for_reencoding(): void
    {
        $source = $this->source();

        $this->assertStringNotContainsString(
            '->post($endpoint->url, $jsonPayload)',
            $source,
            'Passing the encoded string to post() double-encodes it and breaks raw-body signature verification'
        );
    }

    public function test_signature_matches_raw_body_when_body_is_sent_verbatim(): void
    {
        // Behavioral half of the contract: with the body sent verbatim, a
        // receiver hashing (raw body + X-Timestamp) reproduces X-Signature.
        $payload = '{"server_id":2,"expiration_date":"2026-11-27T09:43:56+00:00"}';
        $timestamp = '1795771151';
        $secret = 'receiver-side-secret';

        $signature = WebhookSignature::generate($payload.$timestamp, $secret);

        // Simulate the receiver: hash the raw body it read off the socket.
        $rawBody = $payload; // withBody() sends these bytes unchanged
        $receiverHash = hash_hmac('sha256', $rawBody.$timestamp, $secret);

        $this->assertSame($signature, $receiverHash);
        $this->assertTrue(WebhookSignature::validate($rawBody.$timestamp, $secret, $signature));
    }

    private function source(): string
    {
        $source = file_get_contents(self::SERVICE);
        self::assertIsString($source);

        return $source;
    }
}
