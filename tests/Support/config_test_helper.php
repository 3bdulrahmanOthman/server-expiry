<?php

/*
 * Minimal config() stand-in for tests that exercise configuration-backed
 * infrastructure objects directly — the plugin test harness has no Laravel
 * framework, so config()/facades do not exist there.
 *
 * Guarded: when the plugin runs inside a real framework bootstrap, Laravel's
 * config() is already defined and this file must never shadow it. Values are
 * seeded per-test through set_test_config(); unknown keys return the caller's
 * default, exactly like Laravel's config().
 */

declare(strict_types=1);

if (! function_exists('config')) {
    $GLOBALS['__test_config_store'] = [];

    function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $GLOBALS['__test_config_store'];
        }

        return $GLOBALS['__test_config_store'][$key] ?? $default;
    }
}

if (! function_exists('set_test_config')) {
    function set_test_config(array $values): void
    {
        $GLOBALS['__test_config_store'] = array_merge($GLOBALS['__test_config_store'], $values);
    }
}

if (! function_exists('clear_test_config')) {
    function clear_test_config(): void
    {
        $GLOBALS['__test_config_store'] = [];
    }
}
