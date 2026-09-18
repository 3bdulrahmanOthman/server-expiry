<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Console;

use PHPUnit\Framework\TestCase;

/**
 * H6.1 regression guard: the scheduled lifecycle command is the only writer
 * of expiration-based suspensions, and its suspension sequence is what turns
 * "expired past grace" into (Suspended, suspension_reason = 'expiration'):
 *
 *   auto-suspend gate → grace-hours threshold query →
 *   ExpirationService::processExpiration() eligibility →
 *   idempotency claim → ServerExpired event →
 *   SuspensionContext flag → SuspensionService::handle() →
 *   Server::updating hook stamps the reason →
 *   ServerSuspendedByExpiration event → flag cleared in finally
 *
 * The command body cannot execute inside this plugin-only test harness (it
 * drives Eloquent, the panel's SuspensionService and Laravel helpers), so —
 * like the H3 header-action suite — this contract pins the wiring in source,
 * while the suspension DECISION matrix itself runs on the real service in
 * ExpirationSuspensionDecisionTest and the full chain is exercised against
 * the real panel during runtime verification.
 */
final class LifecycleSuspensionWiringTest extends TestCase
{
    private const COMMAND = __DIR__.'/../../src/Console/Commands/ProcessServerExpirationCommand.php';

    private const STANDALONE = __DIR__.'/../../src/Console/Commands/SuspendExpiredServersCommand.php';

    private const PROVIDER = __DIR__.'/../../src/Providers/ServerExpiryServiceProvider.php';

    public function test_lifecycle_command_is_scheduled_every_minute_without_overlap(): void
    {
        $provider = $this->source(self::PROVIDER);

        $this->assertMatchesRegularExpression(
            '/Schedule::command\(ProcessServerExpirationCommand::class\)\s*->\s*everyMinute\(\)\s*->\s*withoutOverlapping\(\)/',
            $provider,
            'The lifecycle scheduler must keep driving expiration suspension every minute'
        );
    }

    public function test_command_aborts_before_any_processing_when_auto_suspend_disabled(): void
    {
        $command = $this->source(self::COMMAND);
        $gatePosition = strpos($command, 'if (! $this->expirationService->isAutoSuspendEnabled())');
        $suspensionPosition = strpos($command, 'private function processExpirations(');

        $this->assertNotFalse($gatePosition, 'The auto-suspend gate must exist');
        $this->assertNotFalse($suspensionPosition);
        $this->assertLessThan(
            $suspensionPosition,
            $gatePosition,
            'The auto-suspend gate must run before any suspension processing'
        );
        $this->assertMatchesRegularExpression(
            '/isAutoSuspendEnabled\(\)\) \{[^!]*?return self::SUCCESS;/s',
            substr($command, (int) $gatePosition, 400),
            'A disabled SERVER_EXPIRY_AUTO_SUSPEND must abort the command'
        );
    }

    public function test_suspension_threshold_is_expiration_plus_grace_hours(): void
    {
        $command = $this->source(self::COMMAND);

        $this->assertStringContainsString(
            "\$graceHours = (int) (\$this->option('grace-hours') ?? \$this->expirationService->getGracePeriod()->hours());",
            $command,
            'The grace window must come from the configured grace period (env-normalized by the repository)'
        );
        $this->assertStringContainsString(
            '$threshold = now()->subHours($graceHours);',
            $command,
            'Only servers whose expiration is older than the grace window may be selected'
        );
        $this->assertStringContainsString(
            "->where('expires_at', '<=', \$threshold)",
            $command,
            'The selection must filter on expires_at being past the grace threshold'
        );
    }

    public function test_suspension_happens_only_when_the_service_deems_the_server_eligible(): void
    {
        $command = $this->source(self::COMMAND);

        $this->assertMatchesRegularExpression(
            '/if \(! \$this->expirationService->processExpiration\(\$server->id\)\) \{.*?continue;/s',
            $command,
            'Servers the service deems ineligible (inside grace, auto-suspend off) must be skipped'
        );
    }

    public function test_expiration_suspension_stamps_the_reason_and_always_clears_the_context_flag(): void
    {
        $command = $this->source(self::COMMAND);
        $provider = $this->source(self::PROVIDER);

        $flagPosition = strpos($command, 'SuspensionContext::setExpirationSuspensionInProgress(true)');
        $handlePosition = strpos($command, '$this->suspensionService->handle($server, SuspendAction::Suspend)');
        $clearPosition = strpos($command, 'SuspensionContext::setExpirationSuspensionInProgress(false)');

        $this->assertNotFalse($flagPosition, 'The expiration-suspension context flag must be set');
        $this->assertNotFalse($handlePosition, 'SuspensionService::handle must be the suspension call');
        $this->assertNotFalse($clearPosition, 'The context flag must be cleared after each server');
        $this->assertLessThan(
            $handlePosition,
            $flagPosition,
            'The context flag must be set BEFORE SuspensionService::handle so the model hook stamps the expiration reason'
        );

        // The updating hook turns the context flag into the owner-facing reason.
        $this->assertMatchesRegularExpression(
            "/isExpirationSuspensionInProgress\(\)\) \{\s*\\\$server->suspension_reason = 'expiration';/",
            $provider,
            'A suspension made under the context flag must stamp suspension_reason = expiration'
        );
    }

    /**
     * H6.1 runtime finding: `status` is cast to the ServerState enum, so the
     * updating hook receives enum instances while raw originals may still be
     * strings. The hook must normalize both sides before its strict value
     * comparison — the previous enum-vs-string comparison was always false
     * and the reason stamping/clearing never executed at runtime.
     */
    public function test_reason_hook_normalizes_enum_and_string_statuses_before_comparing(): void
    {
        $provider = $this->source(self::PROVIDER);

        $this->assertStringContainsString(
            '$newStatusValue = $newStatus instanceof \App\Enums\ServerState ? $newStatus->value : $newStatus;',
            $provider,
            'The hook must normalize the (enum-cast) live status before the strict value comparison'
        );
        $this->assertStringContainsString(
            '$originalStatusValue = $originalStatus instanceof \App\Enums\ServerState ? $originalStatus->value : $originalStatus;',
            $provider,
            'The hook must normalize the original status before the strict value comparison'
        );
        $this->assertStringNotContainsString(
            'if ($newStatus === $suspendedValue && $originalStatus !== $suspendedValue)',
            $provider,
            'Comparing the enum instance directly against the string value is always false — the hook would be dead code'
        );
    }

    public function test_standalone_suspend_command_follows_the_same_contract(): void
    {
        $command = $this->source(self::STANDALONE);

        $this->assertMatchesRegularExpression('/if \(! \$this->expirationService->isAutoSuspendEnabled\(\)\)/', $command);
        $this->assertStringContainsString('$threshold = now()->subHours($graceHours);', $command);
        $this->assertMatchesRegularExpression('/if \(! \$this->expirationService->processExpiration\(\$server->id\)\) \{/', $command);

        $flagPosition = strpos($command, 'SuspensionContext::setExpirationSuspensionInProgress(true)');
        $handlePosition = strpos($command, '$this->suspensionService->handle($server, SuspendAction::Suspend)');
        $this->assertNotFalse($flagPosition);
        $this->assertNotFalse($handlePosition);
        $this->assertLessThan($handlePosition, $flagPosition);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents($path);
    }
}
