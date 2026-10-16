<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Support;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use SquadronStrike\ServerExpiry\Application\Services\ExpirationService;

/**
 * Concurrency guard for expiration-based suspension (manual-suspension invariant).
 *
 * The lifecycle command selects candidate servers with a bulk query, then acts on
 * each one. Between that SELECT and the suspension write, an administrator can
 * suspend the same server by hand — which the Server::updating hook stamps as
 * `suspension_reason = 'manual'`. Without re-validation the scheduler would then
 * overwrite that reason with 'expiration', silently converting a manual
 * suspension into an expiration suspension and making the server eligible for
 * automatic revival. That is a violation of the manual-suspension invariant.
 *
 * The guard closes the window: it re-reads the row under a row lock inside a
 * short transaction and re-asks {@see ExpirationService::processExpiration()}
 * — the single owner of the suspension business rules — so no rule is duplicated
 * here. Only concurrency and the manual-suspension check live in this class.
 *
 * The locking transaction is deliberately short: the caller performs the
 * suspension (and the daemon sync it triggers) after the guard has returned, so
 * no row lock is ever held across a network round-trip.
 */
final class SuspensionGuard
{
    /**
     * Re-validate, under a row lock, that a server is still eligible for
     * expiration-based suspension.
     *
     * @return bool True only when the caller may proceed with the suspension.
     */
    public static function stillEligibleForExpirationSuspension(Server $server, ExpirationService $expirationService): bool
    {
        // The candidate came from a bulk SELECT executed before this call, so its
        // attributes are potentially stale; only the primary key is trustworthy.
        $serverId = $server->getKey();

        return (bool) DB::transaction(function () use ($serverId, $expirationService): bool {
            $locked = Server::query()->whereKey($serverId)->lockForUpdate()->first();

            $isSuspended = $locked instanceof Server && $locked->isSuspended();
            $suspensionReason = $locked instanceof Server ? $locked->suspension_reason : null;

            return self::maySuspend(
                $locked instanceof Server,
                $isSuspended,
                is_string($suspensionReason) ? $suspensionReason : null,
                // Re-ask the rule owner so grace period / auto-suspend / expiry are
                // never re-implemented at this boundary.
                fn (): bool => $locked instanceof Server
                    && $expirationService->processExpiration($locked->getKey()),
            );
        });
    }

    /**
     * The eligibility decision itself, free of persistence so it can be tested
     * directly.
     *
     * @param  bool  $exists  The row was found under the lock.
     * @param  bool  $isSuspended  The locked row is in the suspended state.
     * @param  string|null  $suspensionReason  The locked row's stamped reason.
     * @param  callable(): bool  $businessEligible  The domain rule check, evaluated
     *                                              only when the concurrency checks pass.
     */
    public static function maySuspend(
        bool $exists,
        bool $isSuspended,
        ?string $suspensionReason,
        callable $businessEligible
    ): bool {
        // Deleted while the batch was running.
        if (! $exists) {
            return false;
        }

        // Suspended in the meantime: by an administrator, by another worker, or
        // because the server is being reinstalled/restored. The command contract is
        // to act on active servers only.
        if ($isSuspended) {
            return false;
        }

        // The manual-suspension invariant. `suspension_reason` is stamped by the
        // Server::updating hook, so reading it under the same lock means a
        // concurrent manual suspension is either fully visible here or has not
        // happened yet.
        if ($suspensionReason === 'manual') {
            return false;
        }

        return $businessEligible();
    }
}
