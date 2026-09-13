<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Infrastructure;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;

/**
 * Regression tests for the R10 hotfix: the webhook listeners must serialize
 * the expiration date using ExpirationDate's public API. The previous code
 * called ExpirationDate::toString(), which does not exist, and fatally
 * errored on every ExpirationCreated / ExpirationSet / ServerRenewed
 * webhook dispatch.
 */
final class WebhookPayloadExpirationDateTest extends TestCase
{
    /**
     * The exact serialization expression used by the listeners — same
     * contract as the Application API show() endpoint.
     */
    private function serialize(ExpirationDate $expirationDate): ?string
    {
        return $expirationDate->isPermanent()
            ? null
            : $expirationDate->getDateTime()->format(DateTimeInterface::ATOM);
    }

    public function test_non_permanent_date_serializes_to_atom(): void
    {
        $expirationDate = ExpirationDate::fromDateTime(new DateTimeImmutable('2026-09-20 12:00:00'));

        $serialized = $this->serialize($expirationDate);

        $this->assertIsString($serialized);
        $this->assertSame(
            (new DateTimeImmutable('2026-09-20 12:00:00'))->format(DateTimeInterface::ATOM),
            $serialized
        );
    }

    public function test_permanent_date_serializes_to_null(): void
    {
        $this->assertNull($this->serialize(ExpirationDate::permanent()));
    }

    public function test_serialized_date_is_json_encodable(): void
    {
        $payload = [
            'server_id' => 'srv-1',
            'expiration_date' => $this->serialize(ExpirationDate::fromDateTime(new DateTimeImmutable('2026-09-20 12:00:00'))),
            'timestamp' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        ];

        $this->assertNotFalse(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Source-level guard: none of the expiration webhook listeners may call
     * the nonexistent ExpirationDate::toString() again, and all must use the
     * isPermanent()/getDateTime() public API consistently.
     */
    public function test_listeners_do_not_call_nonexistent_tostring(): void
    {
        $listeners = [
            'SendExpirationCreatedWebhook.php',
            'SendExpirationSetWebhook.php',
            'SendServerRenewedWebhook.php',
        ];

        foreach ($listeners as $listener) {
            $path = __DIR__.'/../../src/Infrastructure/Webhooks/Listeners/'.$listener;
            $source = file_get_contents($path);

            $this->assertNotFalse($source, "Listener source not found: $listener");
            $this->assertStringNotContainsString(
                '->toString()',
                $source,
                "$listener must not call ExpirationDate::toString() (method does not exist)"
            );
            $this->assertStringContainsString('isPermanent()', $source, "$listener must handle permanent dates");
            $this->assertStringContainsString('getDateTime()->format(\\'.DateTimeInterface::class.'::ATOM)', $source,
                "$listener must serialize via the public API using the ATOM format");
        }
    }
}
