<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function clear_test_config;

/**
 * Documents the notification dispatch contract.
 *
 * IMPORTANT — what these tests do and do not prove.
 *
 * The plugin's standalone suite has no Laravel application (tests/bootstrap.php
 * supplies only config()/now()), so it cannot dispatch a notification, resolve
 * the Notification facade, or observe queue behaviour at runtime. These tests
 * assert the DECLARED contract — the interface each class implements and which
 * dispatcher each call site uses. They do not prove delivery, queueing, or
 * worker execution. Delivering a notification is verified only by the
 * Pelican-backed "Filament feature tests" CI job on a real panel.
 *
 * The distinction matters because the two dispatch styles are NOT equivalent:
 *
 *   Notification::send(...)     -> checks ShouldQueue and queues when set
 *                                  (vendor NotificationSender::send, L89)
 *   Notification::sendNow(...)  -> dispatches through the channel in-process,
 *                                  ignoring ShouldQueue entirely
 *                                  (vendor NotificationSender::sendNow)
 *
 * The expiry listeners therefore deliver synchronously on purpose: they mark
 * the notification_idempotency row "sent" immediately, so queueing would record
 * delivery before it happened. The `ShouldQueue` declaration only takes effect
 * for the two command call sites that use `$user->notify(...)`.
 */
final class NotificationQueueContractTest extends TestCase
{
    protected function tearDown(): void
    {
        clear_test_config();
        parent::tearDown();
    }

    /**
     * The notification classes are inspected as source, not autoloaded: they
     * extend Illuminate\Notifications\Notification, which does not exist in the
     * framework-less standalone suite.
     *
     * @return array<string, array{0: string}>
     */
    public static function notificationClasses(): array
    {
        return [
            'expired' => [__DIR__.'/../../src/Notifications/ServerExpiredNotification.php'],
            'expiring warning' => [__DIR__.'/../../src/Notifications/ServerExpiringWarningNotification.php'],
        ];
    }

    #[DataProvider('notificationClasses')]
    public function test_notification_declares_the_queued_contract(string $path): void
    {
        $source = (string) file_get_contents($path);

        self::assertStringContainsString(
            'implements ShouldQueue',
            $source,
            'The class must implement ShouldQueue so notify() queues it instead of sending inline'
        );

        self::assertStringContainsString(
            'use Illuminate\Contracts\Queue\ShouldQueue;',
            $source,
            'ShouldQueue must be the Laravel queue contract'
        );

        self::assertStringContainsString('function via(', $source);
        self::assertStringContainsString('function toMail(', $source);
    }

    /**
     * The event-driven path used by the expiry lifecycle. This is the primary
     * delivery route, and it is synchronous by design.
     *
     * @return array<string, array{0: string}>
     */
    public static function synchronousListenerPaths(): array
    {
        return [
            'expiration warning' => [__DIR__.'/../../src/Listeners/ExpirationWarningListener.php'],
            'suspended by expiration' => [__DIR__.'/../../src/Listeners/ServerSuspendedByExpirationListener.php'],
        ];
    }

    #[DataProvider('synchronousListenerPaths')]
    public function test_expiry_listeners_dispatch_synchronously(string $path): void
    {
        $source = (string) file_get_contents($path);

        self::assertStringContainsString(
            'Notification::sendNow(',
            $source,
            'The listener marks idempotency as sent immediately after dispatch, so it must not queue'
        );

        self::assertStringNotContainsString(
            'Notification::send(',
            $source,
            'Switching to send() would queue the notification while idempotency is already marked sent'
        );
    }

    /**
     * The two command call sites use the notifiable's notify() helper, which
     * honours ShouldQueue — so these are the paths that genuinely require a
     * queue worker.
     *
     * @return array<string, array{0: string}>
     */
    public static function queuedCommandPaths(): array
    {
        return [
            'send warnings' => [__DIR__.'/../../src/Console/Commands/SendExpiryWarningsCommand.php'],
            'suspend expired' => [__DIR__.'/../../src/Console/Commands/SuspendExpiredServersCommand.php'],
        ];
    }

    #[DataProvider('queuedCommandPaths')]
    public function test_command_dispatch_sites_use_the_notify_helper(string $path): void
    {
        $source = (string) file_get_contents($path);

        self::assertMatchesRegularExpression(
            '/->notify\(new \w+Notification\(/',
            $source,
            'notify() honours ShouldQueue; sendNow() here would silently bypass the worker'
        );
    }
}
