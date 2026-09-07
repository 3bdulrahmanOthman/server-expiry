<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Infrastructure\Webhooks;

/**
 * Handles HMAC-SHA256 signature generation and validation for webhook payloads.
 */
class WebhookSignature
{
    /**
     * Generate a signature for the given payload and secret key.
     *
     * @param string $payload The JSON payload to sign
     * @param string $secretKey The secret key for HMAC-SHA256
     * @return string The hex-encoded signature
     */
    public static function generate(string $payload, string $secretKey): string
    {
        return hash_hmac('sha256', $payload, $secretKey);
    }

    /**
     * Validate a signature against the payload and secret key.
     *
     * @param string $payload The JSON payload to validate
     * @param string $secretKey The secret key for HMAC-SHA256
     * @param string $signature The signature to validate (hex-encoded)
     * @return bool True if signature is valid, false otherwise
     */
    public static function validate(string $payload, string $secretKey, string $signature): bool
    {
        $expectedSignature = self::generate($payload, $secretKey);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Generate a timestamp for webhook delivery (used for replay protection).
     *
     * @return string Unix timestamp
     */
    public static function generateTimestamp(): string
    {
        return strval(time());
    }
}