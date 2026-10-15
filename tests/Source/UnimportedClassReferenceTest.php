<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Source;

use PHPUnit\Framework\TestCase;

/**
 * H(P0-1) regression guard: no plugin source file may reference an unimported
 * class.
 *
 * `CustomListServers` imported `DateInterval` but not `DateTimeImmutable`, then
 * called `new DateTimeImmutable('now')` inside the plugin namespace. PHP resolved
 * that to
 * `SquadronStrike\ServerExpiry\Filament\Admin\Resources\Servers\Pages\DateTimeImmutable`,
 * so the admin "Renew" action — on both the row action and the bulk action —
 * died with `Class "..." not found`.
 *
 * `php -l` and Pint cannot see this: both are valid PHP. Only a tokeniser can.
 * This test scans every plugin source file and fails on any class reference that
 * would not resolve, which is a whole-repo contract rather than a regression test
 * for one line.
 */
final class UnimportedClassReferenceTest extends TestCase
{
    /**
     * Memoised map of PHP engine internal symbol names, lower-cased.
     *
     * @var array<string, true>|null
     */
    private ?array $phpInternals = null;

    /**
     * Class-like names PHP resolves structurally instead of treating as a class.
     */
    private const STRUCTURAL_KEYWORDS = [
        'self', 'static', 'parent', 'array', 'callable', 'iterable', 'object',
        'string', 'int', 'float', 'bool', 'void', 'mixed', 'never', 'null',
        'false', 'true', 'list', 'and', 'or', 'xor', 'instanceof', 'new',
        'clone', 'function', 'fn', 'match', 'return', 'echo', 'print',
    ];

    public function test_every_plugin_source_file_resolves_its_class_references(): void
    {
        $offenders = [];
        $index = $this->pluginClassIndex();

        foreach ($this->pluginSourceFiles() as $file) {
            foreach ($this->unimportedReferences($file, $index) as $reference) {
                $offenders[] = sprintf(
                    '%s:%d  %s  (no `use %s;` and no leading backslash)',
                    $this->relativePath($file),
                    $reference['line'],
                    $reference['expression'],
                    $reference['name']
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Unimported class references resolve to the current namespace and fatal at runtime:\n".
            implode("\n", $offenders)
        );
    }

    /**
     * The detector must actually catch the original defect. Without this, the
     * whole-repo contract above could pass by being blind.
     */
    public function test_detector_catches_the_original_missing_import(): void
    {
        $offenders = $this->unimportedReferencesFromSource(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace SquadronStrike\ServerExpiry\Filament\Admin\Resources\Servers\Pages;

        use DateInterval;

        final class Broken
        {
            public function run(): void
            {
                $now = new DateTimeImmutable('now');
                $later = new DateInterval('P30D');
                $other = new \DateTimeImmutable('now');
            }
        }
        PHP);

        $names = array_column($offenders, 'name');

        $this->assertContains('DateTimeImmutable', $names, 'An unimported global class must be reported');
        $this->assertNotContains('DateInterval', $names, 'An imported class must not be reported');
        $this->assertCount(1, $offenders, 'Exactly one reference is unimported in the fixture');
    }

    /**
     * And it must not produce false positives on valid code.
     */
    public function test_detector_accepts_imported_aliased_and_fully_qualified_references(): void
    {
        $offenders = $this->unimportedReferencesFromSource(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace My\Plugin;

        use DateTimeImmutable;
        use DateTimeImmutable as Clock;
        use SquadronStrike\ServerExpiry\Support\Expiry;

        final class Fine
        {
            public function run(): void
            {
                new DateTimeImmutable('now');
                new Clock('now');
                new \DateTimeImmutable('now');
                Expiry::warningDays();
                self::helper();
                new \SquadronStrike\Thing\Other();
            }

            private static function helper(): void {}
        }
        PHP);

        $this->assertSame([], array_column($offenders, 'name'));
    }

    /**
     * Decide whether an unqualified class reference actually resolves at runtime.
     *
     * PHP consults its own class table for `<currentNamespace>\<Name>`, and only
     * falls back to the global `<Name>` if that is not found. Crucially the
     * fallback is an autoloading lookup: a name is re-tried unprefixed only when it
     * can be loaded from a file.
     *
     * Verified on this PHP build with real files and three autoloader states
     * (none, inert, and the plugin's own Composer autoloader): a namespaced file
     * that says `new DateTimeImmutable(...)` without importing it fatals with
     * `Class "Ns\DateTimeImmutable" not found` in ALL three states, because engine
     * classes are pre-declared and never autoloaded, so no fallback can rescue
     * them. An unimported *userland* class in the global namespace does resolve,
     * because the autoloader path reaches it.
     *
     * $index is reserved for plugin-declared classes; the current rule needs no
     * lookup because an unimported engine class is fatal outright.
     */
    private function resolvesToSomethingReal(string $namespace, string $candidate, array $index): bool
    {
        $qualified = $namespace === '' ? $candidate : $namespace.'\\'.$candidate;

        if ($this->classResolves($qualified)) {
            return true;
        }

        // A PHP engine class (DateTimeImmutable, DateInterval, InvalidArgumentException,
        // ...). Pre-declared and never autoloadable => no global fallback exists.
        if (isset($this->phpInternals()[strtolower($candidate)])) {
            return $namespace === '';
        }

        // A userland class: the global fallback is reachable through the autoloader,
        // so the reference works. The plugin's own classes are modelled through the
        // PSR-4 index, which resolves step 1 for same-namespace declarations.
        return true;
    }

    /**
     * Determine whether a fully-qualified name denotes a class that really
     * exists, without invoking any autoloader.
     *
     * Autoloading must stay disabled: this harness has no framework, so asking
     * the Composer autoloader about `Illuminate\...` would try to load a plugin
     * model and explode with "Class not found" — the very class of failure this
     * test is meant to report, not to suffer.
     *
     * PHP engine internals (DateTimeImmutable, DateInterval, ...) are never
     * autoloaded, so they are resolved from the declared-symbol tables.
     */
    private function classResolves(string $resolved): bool
    {
        $normalized = ltrim($resolved, '\\');

        if ($normalized === '') {
            return false;
        }

        if (isset($this->phpInternals()[strtolower($normalized)])) {
            return true;
        }

        return class_exists($normalized, false)
            || interface_exists($normalized, false)
            || enum_exists($normalized, false);
    }

    /**
     * Map every class declared inside the plugin to its namespace, so that
     * references to same-namespace classes are not reported as unresolvable.
     *
     * @return array<string, string> Lower-cased short class name => namespace.
     */
    private function pluginClassIndex(): array
    {
        $index = [];

        foreach ($this->pluginSourceFiles() as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            $namespace = '';

            foreach ($tokens as $position => $token) {
                if (is_array($token) && $token[0] === T_NAMESPACE) {
                    $namespace = $this->readNamespace($tokens, $position);
                }

                if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    $name = $this->classTokenAfter($tokens, $position, 0);
                    if ($name !== null) {
                        $index[strtolower($name)] = $namespace;
                    }
                }
            }
        }

        return $index;
    }

    /**
     * @return array<string, true> Lower-cased internal symbol names.
     */
    private function phpInternals(): array
    {
        if ($this->phpInternals !== null) {
            return $this->phpInternals;
        }

        $names = [];

        foreach ([get_declared_classes(), get_declared_interfaces(), get_declared_traits()] as $group) {
            foreach ($group as $name) {
                if ((new \ReflectionClass($name))->isInternal()) {
                    $names[strtolower($name)] = true;
                }
            }
        }

        return $this->phpInternals = $names;
    }

    /**
     * @param  array<string, string>  $index
     * @return array<int, array{name: string, line: int, expression: string}>
     */
    private function unimportedReferences(string $file, array $index = []): array
    {
        return $this->unimportedReferencesFromSource((string) file_get_contents($file), $index);
    }

    /**
     * Tokenise a source string and report class references that cannot resolve.
     *
     * A single-token `T_STRING` in a class-reference position resolves to
     * `<currentNamespace>\<name>`; that is correct only when the short name is
     * imported or when the fully-qualified name exists (PHP built-ins).
     *
     * @return array<int, array{name: string, line: int, expression: string}>
     */
    private function unimportedReferencesFromSource(string $source, array $index = []): array
    {
        $tokens = token_get_all($source);

        $namespace = '';
        $imported = [];

        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_USE) {
                foreach ($this->importedShortNames($tokens, $token[2]) as $name) {
                    $imported[strtolower($name)] = true;
                }
            }
        }

        foreach ($tokens as $position => $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespace = $this->readNamespace($tokens, $position);
            }
        }

        $offenders = [];

        foreach ($tokens as $offset => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NEW) {
                $candidates = [$this->classTokenAfter($tokens, $offset, 0)];
            } elseif ($token[0] === T_DOUBLE_COLON) {
                $candidates = [$this->classTokenBefore($tokens, $offset)];
            } else {
                continue;
            }

            foreach ($candidates as $candidate) {
                if ($candidate === null) {
                    continue;
                }

                $short = strtolower($candidate);
                if (in_array($short, self::STRUCTURAL_KEYWORDS, true) || isset($imported[$short])) {
                    continue;
                }

                if ($this->resolvesToSomethingReal($namespace, $candidate, $index)) {
                    continue;
                }

                $offenders[] = [
                    'name' => $candidate,
                    'line' => $token[2],
                    'expression' => $token[0] === T_NEW ? 'new '.$candidate : $candidate.'::',
                ];
            }
        }

        return $offenders;
    }

    /**
     * Read the namespace declared at $index, tolerating both `namespace Foo;` and
     * `namespace Foo { }` forms for the first declaration in the file.
     */
    private function readNamespace(array $tokens, int $index): string
    {
        $parts = [];
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $parts[] = $token[1];

                continue;
            }

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            break;
        }

        return trim(implode('', $parts), '\\');
    }

    /**
     * Collect the short (unqualified) names introduced by every `use` statement,
     * covering class, alias, group and function/const imports.
     *
     * @return string[]
     */
    private function importedShortNames(array $tokens, int $useLine): array
    {
        $names = [];
        $collecting = false;
        $current = '';
        $alias = null;
        $expectAlias = false;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! $collecting) {
                if (is_array($token) && $token[0] === T_USE && $token[2] === $useLine) {
                    $collecting = true;
                }

                continue;
            }

            if ($token === ';') {
                $this->pushImportedName($names, $current, $alias);
                break;
            }

            if ($token === ',') {
                $this->pushImportedName($names, $current, $alias);
                $current = '';
                $alias = null;

                continue;
            }

            if (is_array($token) && $token[0] === T_AS) {
                $expectAlias = true;

                continue;
            }

            if ($expectAlias && is_array($token) && $token[0] === T_STRING) {
                $alias = $token[1];
                $expectAlias = false;

                continue;
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                $current .= $token[1];
            }
        }

        return $names;
    }

    /**
     * @param  string[]  $names
     */
    private function pushImportedName(array &$names, string $qualified, ?string $alias): void
    {
        if ($qualified === '') {
            return;
        }

        $parts = explode('\\', trim($qualified, '\\'));
        $short = $alias ?? end($parts);

        if (is_string($short) && $short !== '') {
            $names[] = $short;
        }
    }

    /**
     * Return the single unqualified class token that follows a T_NEW, skipping
     * whitespace. Anything else (qualified names, variables, parentheses for
     * anonymous classes) is not an unqualified class reference.
     *
     * @return string|null
     */
    private function classTokenAfter(array $tokens, int $index, int $offset): ?string
    {
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }

            return null;
        }

        return null;
    }

    /**
     * Return the single unqualified class token preceding a T_DOUBLE_COLON.
     *
     * @return string|null
     */
    private function classTokenBefore(array $tokens, int $index): ?string
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }

            return null;
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function pluginSourceFiles(): array
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src';
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        $this->assertNotEmpty($files, 'No plugin source files were discovered');

        return $files;
    }

    private function relativePath(string $file): string
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR;

        return str_replace($root, '', $file);
    }
}
