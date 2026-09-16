<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Localization;

use PHPUnit\Framework\TestCase;

/**
 * Guards the translation catalogue against the v2.0.0 regression where keys
 * referenced via trans('server-expiry::strings.…') were missing from
 * lang/en/strings.php and rendered literally in the UI.
 *
 * The full source tree is scanned, so any future referenced-but-undefined
 * key fails this test.
 */
final class TranslationKeysTest extends TestCase
{
    /**
     * The keys confirmed missing in the v2.0.0 audit; kept explicit so a
     * partial re-removal cannot hide behind the scan below.
     */
    private const CONFIRMED_V201_KEYS = [
        'action_renew',
        'action_extend',
        'action_clear',
        'suspension_label',
        'state_suspended',
        'state_active',
        'none',
    ];

    public function test_confirmed_v201_keys_are_defined(): void
    {
        $defined = $this->definedKeys();

        foreach (self::CONFIRMED_V201_KEYS as $key) {
            $this->assertArrayHasKey($key, $defined, "Missing translation key: {$key}");
        }
    }

    public function test_every_referenced_key_is_defined(): void
    {
        $defined = $this->definedKeys();
        $referenced = $this->referencedKeys();

        $missing = array_diff($referenced, array_keys($defined));

        $this->assertSame(
            [],
            array_values($missing),
            'Referenced but undefined translation keys: '.implode(', ', $missing)
        );
    }

    /**
     * @return array<string, string>
     */
    private function definedKeys(): array
    {
        $strings = require __DIR__.'/../../lang/en/strings.php';

        $this->assertIsArray($strings);

        return $strings;
    }

    /**
     * @return list<string>
     */
    private function referencedKeys(): array
    {
        $referenced = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__.'/../../src', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                '/server-expiry::strings\.([a-z_0-9]+)/',
                (string) file_get_contents($file->getPathname()),
                $matches
            );

            foreach ($matches[1] as $key) {
                $referenced[$key] = true;
            }
        }

        return array_keys($referenced);
    }
}
