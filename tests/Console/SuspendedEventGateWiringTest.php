<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Console;

use PHPUnit\Framework\TestCase;

/**
 * Guards the post-suspension event gate (PR #21 review follow-up).
 *
 * The suspension sequence decides from the REFRESHED database state, not the
 * daemon outcome, and only an expiration suspension may emit
 * ServerSuspendedByExpiration: an admin suspending the server by hand inside
 * the suspension window must never produce a server.expiry.suspended event.
 *
 * The command body cannot execute inside this plugin-only test harness (it
 * drives Eloquent, the panel's SuspensionService and Laravel facades), so this
 * contract pins the wiring positionally in source; the behavior itself is
 * exercised against the real panel during runtime verification.
 */
final class SuspendedEventGateWiringTest extends TestCase
{
    private const COMMAND = __DIR__.'/../../src/Console/Commands/ProcessServerExpirationCommand.php';

    public function test_database_state_is_refreshed_before_any_suspended_event(): void
    {
        $command = $this->command();

        $suspendPosition = strpos($command, '$this->suspensionService->handle($server, SuspendAction::Suspend)');
        $refreshPosition = strrpos($command, '$server->refresh()');
        $dispatchPosition = strpos($command, 'Event::dispatch(new ServerSuspendedByExpiration(');

        $this->assertNotFalse($suspendPosition);
        $this->assertNotFalse($refreshPosition);
        $this->assertNotFalse($dispatchPosition);
        $this->assertLessThan(
            $refreshPosition,
            $suspendPosition,
            'The refresh must come after the suspension attempt'
        );
        $this->assertLessThan(
            $dispatchPosition,
            $refreshPosition,
            'The refreshed database state must be read before the suspended event is dispatched'
        );
    }

    public function test_non_expiration_suspension_skips_the_event(): void
    {
        $command = $this->command();

        $gate = "\$server->isSuspended() && \$server->suspension_reason !== 'expiration'";
        $gatePosition = strpos($command, $gate);
        $dispatchPosition = strpos($command, 'Event::dispatch(new ServerSuspendedByExpiration(');

        $this->assertNotFalse($gatePosition, 'The suspension_reason gate must exist');
        $this->assertLessThan(
            $dispatchPosition,
            $gatePosition,
            'The suspension_reason gate must run before the suspended event is dispatched'
        );

        $gateBody = substr($command, (int) $gatePosition, 700);
        $this->assertMatchesRegularExpression(
            '/\{.*?continue;/s',
            $gateBody,
            'A suspension with a reason other than expiration must skip to the next server without dispatching'
        );
        $this->assertStringNotContainsString(
            'ServerSuspendedByExpiration',
            (string) substr($gateBody, 0, (int) strpos($gateBody, 'continue;')),
            'The skipped path must not dispatch the expiration event'
        );
    }

    public function test_unsuspended_server_marks_the_notification_failed_and_continues(): void
    {
        $command = $this->command();

        $checkPosition = strpos($command, 'if (! $server->isSuspended())');
        $dispatchPosition = strpos($command, 'Event::dispatch(new ServerSuspendedByExpiration(');

        $this->assertNotFalse($checkPosition);
        $this->assertLessThan(
            $dispatchPosition,
            $checkPosition,
            'The not-suspended check must run before the suspended event is dispatched'
        );

        $body = substr($command, (int) $checkPosition, 900);
        $this->assertStringContainsString('markNotificationAsFailed(', $body, 'A failed suspension must schedule a notification retry');
        $this->assertMatchesRegularExpression('/\{.*?continue;/s', $body, 'A failed suspension must continue with the next server');
    }

    public function test_the_suspended_event_has_exactly_one_dispatch_site(): void
    {
        $this->assertSame(
            1,
            substr_count($this->command(), 'Event::dispatch(new ServerSuspendedByExpiration('),
            'The expiration-suspended event must have a single dispatch site behind both gates'
        );
    }

    public function test_daemon_sync_failure_does_not_retry_the_notification_after_dispatch(): void
    {
        $command = $this->command();

        $dispatchPosition = strpos($command, 'Event::dispatch(new ServerSuspendedByExpiration(');
        $warnPosition = strpos($command, 'if ($daemonSyncFailed)');

        $this->assertNotFalse($dispatchPosition);
        $this->assertNotFalse($warnPosition);
        $this->assertLessThan(
            $warnPosition,
            $dispatchPosition,
            'The daemon-sync warning runs after the event dispatch, so a persisted suspension is not retried'
        );

        $warnBody = substr($command, (int) $warnPosition, 600);
        $this->assertStringNotContainsString(
            'markNotificationAsFailed(',
            $warnBody,
            'A daemon sync failure after a persisted suspension must not schedule a notification retry'
        );
    }

    private function command(): string
    {
        return (string) file_get_contents(self::COMMAND);
    }
}
