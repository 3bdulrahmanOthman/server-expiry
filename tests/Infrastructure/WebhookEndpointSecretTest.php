<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * Guards webhook endpoint secret handling (v2.1, H3).
 *
 * secret_key is nullable, and an endpoint created without one signed its
 * deliveries with an empty key — effectively unsigned webhooks. Creation must
 * auto-generate a secret; edits must never wipe a stored secret.
 */
final class WebhookEndpointSecretTest extends TestCase
{
    private const MODEL = __DIR__.'/../../src/Infrastructure/Webhooks/Models/WebhookEndpoint.php';

    private const RESOURCE = __DIR__.'/../../src/Filament/Admin/Resources/WebhookEndpoint/WebhookEndpointResource.php';

    public function test_creation_generates_a_secret_when_left_empty(): void
    {
        $source = $this->source(self::MODEL);

        $this->assertStringContainsString('static::creating(function (self $endpoint)', $source);
        $this->assertStringContainsString('blank($endpoint->secret_key)', $source);
        $this->assertStringContainsString('random_bytes(', $source);
    }

    public function test_edit_form_only_dehydrates_the_secret_when_filled(): void
    {
        $source = $this->source(self::RESOURCE);

        $this->assertStringContainsString(
            'dehydrated(fn ($state) => filled($state))',
            $source,
            'The secret field must not overwrite a stored secret with an empty value on edit'
        );
    }

    private function source(string $path): string
    {
        return (string) file_get_contents($path);
    }
}
