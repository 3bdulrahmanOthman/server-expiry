<?php

declare(strict_types=1);
use Carbon\Carbon;

/*
 * Minimal framework stand-ins for tests, in a harness with no Laravel.
 *
 * The plugin depends on Laravel only at runtime (the panel provides it); the test
 * harness has no illuminate/* package installed, so the few framework helpers a
 * test needs are provided here. Guarded so a real framework bootstrap is never
 * shadowed — exactly like config_test_helper.php.
 */

if (! function_exists('now')) {
    /**
     * Laravel's now() helper, reduced to a clock.
     *
     * Returns the engine class explicitly (no unqualified reference, which would
     * fatal from this namespace — the defect these suites guard). Carbon when it
     * is installed (the behavioral delivery tests exercise code paths that use
     * Carbon-only methods like addSeconds()/copy(), exactly as the panel
     * provides them); otherwise a plain DateTimeImmutable, on which the portable
     * claim predicate still provably runs.
     */
    function now(): DateTimeImmutable
    {
        if (class_exists(Carbon::class)) {
            return Carbon::now()->toImmutable();
        }

        return new DateTimeImmutable('now');
    }
}
