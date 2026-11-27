<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the API/UI renewal parity gap.
 *
 * The admin UI revives on renewal: a server suspended because it expired is
 * unsuspended when a new future date (or permanent) is set, and its warning
 * thresholds reset. The Application API renew endpoint persisted the new date
 * and dispatched ServerRenewed — whose listener only logs — leaving the server
 * suspended. Verified live against Pelican rc.2: POST /renew returned 200 with
 * the server still suspended and suspension_reason = 'expiration'.
 *
 * The controller drives Eloquent and the panel's SuspensionService, so — like
 * the H6.1 wiring suite — this contract pins the wiring in source.
 */
final class RenewalRevivalWiringTest extends TestCase
{
    private const CONTROLLER = __DIR__.'/../../src/Http/Controllers/Api/Application/Servers/ExpirationController.php';

    public function test_both_renew_paths_run_the_revival(): void
    {
        $source = $this->source();

        $renewBody = $this->methodBody($source, 'renew');

        $this->assertSame(
            2,
            substr_count($renewBody, '$this->reviveAfterRenewal($server,'),
            'The dated-renew branch and the permanent (clear) branch must both attempt revival'
        );
    }

    public function test_revival_resets_warning_thresholds_for_the_new_date(): void
    {
        $body = $this->methodBody($this->source(), 'reviveAfterRenewal');

        $this->assertStringContainsString(
            "\$server->forceFill(['expiry_warning_day' => null])->saveQuietly();",
            $body,
            'Same semantics as the UI picker: reminders start over for the new date'
        );
    }

    public function test_revival_only_unsuspends_expiration_suspensions(): void
    {
        $body = $this->methodBody($this->source(), 'reviveAfterRenewal');

        $this->assertStringContainsString(
            "\$server->suspension_reason !== 'expiration'",
            $body,
            'A manually suspended server must stay suspended after a renewal'
        );
        $this->assertStringContainsString(
            '! $server->isSuspended()',
            $body,
            'An active server needs no revival'
        );
        $this->assertStringContainsString(
            'SuspensionService::class)->handle($server, SuspendAction::Unsuspend)',
            $body,
            'The revival must go through the panel SuspensionService (DB write + daemon sync)'
        );
    }

    public function test_a_past_new_date_is_not_a_revival(): void
    {
        $body = $this->methodBody($this->source(), 'reviveAfterRenewal');

        $this->assertStringContainsString(
            '$newExpirationDate->isExpired()',
            $body,
            'Same guard as the UI picker: renewing to a past date leaves the lifecycle in charge'
        );
    }

    public function test_a_failed_revival_does_not_fail_the_renewal(): void
    {
        $body = $this->methodBody($this->source(), 'reviveAfterRenewal');

        $this->assertStringContainsString('catch (Throwable $exception)', $body);
        $this->assertStringContainsString(
            'report($exception);',
            $body,
            'The renewal already succeeded; a daemon sync failure on the unsuspend must not 500 the request'
        );
    }

    private function source(): string
    {
        $source = file_get_contents(self::CONTROLLER);
        self::assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, "function {$method}(");
        self::assertNotFalse($start, "Method {$method} must exist");

        $rest = substr($source, $start);
        $next = strpos($rest, "\n    /**", 10);
        $body = $next === false ? $rest : substr($rest, 0, $next);

        self::assertNotEmpty($body);

        return $body;
    }
}
