<?php

declare(strict_types=1);

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
     * Laravel's now() helper, reduced to a clock as DateTimeImmutable.
     *
     * Returns the engine class explicitly (no unqualified reference, which would
     * fatal from this namespace — the defect these suites guard). Only native
     * DateTimeImmutable API is used, so the helper — and therefore the portable
     * claim predicate it feeds — provably needs no Carbon.
     */
    function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}
