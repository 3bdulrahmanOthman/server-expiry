<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Service;

use PHPUnit\Framework\TestCase;
use SquadronStrike\ServerExpiry\Support\SuspensionGuard;

/**
 * H(P0-2) regression guard: the manual-suspension invariant.
 *
 * A server suspended by hand must never be turned into an expiration suspension.
 * The lifecycle command reads its candidate list with a bulk query and then acts
 * per server, so an administrator can suspend the same server in between. Acting
 * on the stale row made the Server::updating hook stamp
 * `suspension_reason = 'expiration'` over `'manual'`, which both mislabels the
 * suspension and makes the server eligible for automatic revival on renewal.
 *
 * These tests drive the guard's real decision function. The invariant is asserted
 * in the strongest form available here: when a manual suspension is visible, the
 * domain rule check must NOT even be consulted.
 */
final class SuspensionGuardDecisionTest extends TestCase
{
    public function test_manually_suspended_server_is_never_suspended_by_expiration(): void
    {
        $businessRulesConsulted = false;

        $maySuspend = SuspensionGuard::maySuspend(
            exists: true,
            isSuspended: false,
            suspensionReason: 'manual',
            businessEligible: function () use (&$businessRulesConsulted): bool {
                $businessRulesConsulted = true;

                return true;
            },
        );

        $this->assertFalse($maySuspend, 'A manually suspended server must not be converted into an expiration suspension');
        $this->assertFalse(
            $businessRulesConsulted,
            'The domain rules must not even be consulted once a manual suspension is visible'
        );
    }

    public function test_already_suspended_server_is_skipped(): void
    {
        $this->assertFalse(
            SuspensionGuard::maySuspend(
                exists: true,
                isSuspended: true,
                suspensionReason: null,
                businessEligible: static fn (): bool => true,
            ),
            'A server suspended between the batch read and the write must be skipped'
        );
    }

    public function test_deleted_server_is_skipped(): void
    {
        $this->assertFalse(
            SuspensionGuard::maySuspend(
                exists: false,
                isSuspended: false,
                suspensionReason: null,
                businessEligible: static fn (): bool => true,
            ),
            'A server deleted while the batch was running must be skipped'
        );
    }

    public function test_active_server_defers_to_the_domain_rules(): void
    {
        $this->assertTrue(
            SuspensionGuard::maySuspend(
                exists: true,
                isSuspended: false,
                suspensionReason: null,
                businessEligible: static fn (): bool => true,
            ),
            'An active, eligible server may be suspended'
        );

        $this->assertFalse(
            SuspensionGuard::maySuspend(
                exists: true,
                isSuspended: false,
                suspensionReason: null,
                businessEligible: static fn (): bool => false,
            ),
            'The guard must defer to the domain rules (grace period, auto-suspend, expiry)'
        );
    }

    public function test_expiration_reason_is_not_treated_as_manual(): void
    {
        // Re-running against a server already suspended by expiration is handled by
        // the isSuspended check; the reason alone must not block a legitimate
        // suspension of an active server.
        $this->assertTrue(
            SuspensionGuard::maySuspend(
                exists: true,
                isSuspended: false,
                suspensionReason: 'expiration',
                businessEligible: static fn (): bool => true,
            ),
            "A stale 'expiration' reason on an active server must not block suspension"
        );
    }
}
