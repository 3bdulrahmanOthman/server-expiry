<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Source;

use PHPUnit\Framework\TestCase;

/**
 * H(P0-2) regression guard: every Application API request extending
 * `App\Http\Requests\Api\Application\ApplicationApiRequest` must declare
 * `$resource` with the same nullable type (`?string`) as the Pelican base class.
 *
 * v2.2.0 regressed because `ShowExpirationRequest` declared
 * `protected string $resource = 'server'` while the Pelican parent declares
 * `protected ?string $resource`. PHP makes property types invariant across
 * inheritance, so loading any such request class fatals with:
 *
 *   Type of ...::$resource must be ?string (as in class ApplicationApiRequest)
 *
 * The parent class lives in the Pelican Panel (`App\...`) and is not loadable
 * from this standalone plugin suite, so this test tokenises the plugin's own
 * request source files and asserts each `$resource` declaration is nullable.
 * If a future change reintroduces `string` (or drops `?`), the test fails.
 */
final class ApplicationApiRequestResourceTypeTest extends TestCase
{
    private const REQUEST_DIR = 'src/Http/Requests/Api/Application/Servers';

    /**
     * Every request class extending ApplicationApiRequest declares $resource
     * as ?string (nullable), matching the Pelican base class.
     */
    public function test_all_application_api_requests_declare_nullable_resource(): void
    {
        $offenders = [];

        foreach ($this->applicationApiRequestFiles() as $file) {
            $declarations = $this->resourcePropertyDeclarations((string) file_get_contents($file));

            foreach ($declarations as $declaration) {
                if (! $this->isNullableString($declaration['type'])) {
                    $offenders[] = sprintf(
                        '%s:%d  %s  declared `$resource` as "%s", not "?string"',
                        $this->relativePath($file),
                        $declaration['line'],
                        $declaration['class'],
                        $declaration['type']
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'ApplicationApiRequest subclasses must declare $resource as ?string to match the Pelican base class:'.
            "\n".implode("\n", $offenders)
        );
    }

    /**
     * Without this, the whole-repo guard above could pass by being blind.
     */
    public function test_detector_catches_non_nullable_resource(): void
    {
        $all = $this->resourcePropertyDeclarations(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace SquadronStrike\ServerExpiry\Http\Requests\Api\Application\Servers;

        use App\Http\Requests\Api\Application\ApplicationApiRequest;

        final class Broken extends ApplicationApiRequest
        {
            protected string $resource = 'server';
        }
        PHP);

        $nonNullable = array_filter($all, fn (array $d): bool => ! $this->isNullableString($d['type']));

        $this->assertCount(1, $nonNullable, 'Exactly one non-nullable $resource declaration should be flagged');
        $this->assertSame('string', $nonNullable[0]['type']);
        $this->assertSame('Broken', $nonNullable[0]['class']);
    }

    /**
     * And it must not produce false positives on valid code.
     */
    public function test_detector_accepts_nullable_resource(): void
    {
        $all = $this->resourcePropertyDeclarations(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace SquadronStrike\ServerExpiry\Http\Requests\Api\Application\Servers;

        use App\Http\Requests\Api\Application\ApplicationApiRequest;

        final class Fine extends ApplicationApiRequest
        {
            protected ?string $resource = 'server';
        }
        PHP);

        $nonNullable = array_filter($all, fn (array $d): bool => ! $this->isNullableString($d['type']));

        $this->assertSame([], $nonNullable, 'A nullable ?string $resource declaration should pass');
    }

    /**
     * @return array<int, string>
     */
    private function applicationApiRequestFiles(): array
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.self::REQUEST_DIR;
        $files = [];

        if (! is_dir($root)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        $this->assertNotEmpty($files, 'No Application API request files were discovered');

        return $files;
    }

    /**
     * @return array<int, array{class: string, line: int, type: string}>
     */
    private function resourcePropertyDeclarations(string $source): array
    {
        $tokens = token_get_all($source);
        $class = '';
        $offenders = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_CLASS) {
                $class = $this->classNameAfter($tokens, $i);
            }

            // Look for `$resource` (T_VARIABLE) with a type hint before it.
            if (! is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$resource') {
                continue;
            }

            // Walk backwards to find the type hint preceding `$resource`.
            $type = $this->typeBeforeResource($tokens, $i);

            if ($type !== null) {
                $offenders[] = [
                    'class' => $class,
                    'line' => $token[2],
                    'type' => $type,
                ];
            }
        }

        return $offenders;
    }

    private function classNameAfter(array $tokens, int $index): string
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

            break;
        }

        return '';
    }

    /**
     * Return the type hint immediately before `$resource`, e.g. `string` or
     * `?string`, or null when no type hint is present.
     */
    private function typeBeforeResource(array $tokens, int $index): ?string
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_WHITESPACE) {
                continue;
            }

            if ($token[0] === T_NS_SEPARATOR) {
                continue;
            }

            if ($token[0] === T_STRING) {
                $type = $token[1];

                // Include a leading `?` nullable marker. The `?` tokenises as a
                // single-character string (not an array), so check both forms.
                $previous = $tokens[$i - 1] ?? null;
                if ($previous === '?' || (is_array($previous) && $previous[0] === '?')) {
                    return '?'.$type;
                }

                return $type;
            }

            return null;
        }

        return null;
    }

    private function isNullableString(string $type): bool
    {
        return $type === '?string';
    }

    private function relativePath(string $file): string
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR;

        return str_replace($root, '', $file);
    }
}
