<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the dead inline first-send bug.
 *
 * queueDelivery() is documented to attempt the initial send inline, and the
 * attempt budget assumes it ("the first claim's increment makes it 1"). But the
 * claim predicate refuses fresh unclaimed rows on purpose — a scheduler pass
 * must never steal a row from the inline attempt that owns it — and the inline
 * attempt claimed through the same predicate. So the inline send could never
 * claim its own row: every delivery sat unclaimed until the abandoned-row
 * recovery ran one lease (300s) later. Verified live against Pelican rc.2:
 * events at 09:13:56/09:14:03 first reached the receiver at 09:19:11.
 *
 * The fix inserts the row already claimed by its creator and hands that token
 * to attemptDelivery(), which skips the guarded UPDATE for pre-claimed rows.
 * The fresh-row exclusion in the claim predicate is unchanged and keeps
 * protecting the creator's ownership.
 */
final class WebhookInlineFirstSendTest extends TestCase
{
    private const SERVICE = __DIR__.'/../../src/Infrastructure/Webhooks/WebhookDeliveryService.php';

    public function test_queued_rows_are_inserted_already_claimed_by_their_creator(): void
    {
        $source = $this->source();

        $queueBody = $this->methodBody($source, 'queueDelivery');

        $this->assertStringContainsString(
            'WebhookDeliveryClaim::newToken()',
            $queueBody,
            'queueDelivery must mint the claim token it will send under'
        );

        foreach (["'claim_token' => \$token", "'claimed_at' => now()", "'attempt' => 1"] as $fragment) {
            $this->assertStringContainsString(
                $fragment,
                $queueBody,
                "The insert must carry {$fragment} so the creator owns the row and the inline send consumes attempt 1"
            );
        }

        $this->assertStringContainsString(
            '$this->attemptDelivery($delivery, $token)',
            $queueBody,
            'The inline attempt must receive the pre-claimed token'
        );
    }

    public function test_attempt_delivery_accepts_a_preclaimed_token_and_still_claims_without_one(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'attemptDelivery(WebhookDelivery $delivery, ?string $preclaimedToken = null)',
            $source,
            'Retries and recovery keep the claimless call shape'
        );

        $attemptBody = $this->methodBody($source, 'attemptDelivery');

        $this->assertStringContainsString(
            '$token = $preclaimedToken ?? $this->claim($delivery);',
            $attemptBody,
            'A pre-claimed row must skip the guarded UPDATE that structurally excludes it'
        );
    }

    public function test_the_fresh_row_exclusion_still_protects_the_creators_ownership(): void
    {
        // The claim predicate is intentionally NOT relaxed: a scheduler pass
        // must still refuse unclaimed rows younger than one lease. That is what
        // makes pre-claiming safe against a racing scheduler.
        $source = $this->source();

        $this->assertMatchesRegularExpression(
            "/where\('next_attempt_at', '<=', \\\$staleBefore\)/",
            $source,
            'The abandoned-unclaimed recovery window (one lease) must remain'
        );
    }

    private function source(): string
    {
        $source = file_get_contents(self::SERVICE);
        self::assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, "function {$method}(");
        self::assertNotFalse($start, "Method {$method} must exist");

        // The next method or the end of the class bounds the body.
        $rest = substr($source, $start);
        $next = strpos($rest, "\n    /**", 10);
        $body = $next === false ? $rest : substr($rest, 0, $next);

        self::assertNotEmpty($body);

        return $body;
    }
}
