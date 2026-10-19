<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Source;

use PHPUnit\Framework\TestCase;

/**
 * Supplemental source-level guard for the Application API request contract.
 *
 * The authoritative check is the runtime inheritance test
 * (tests/Source/ApplicationApiRequestInheritanceTest.php), because PHP itself
 * enforces property-type invariance and method-signature compatibility. This
 * tokenizer test exists so a missing return type or a narrowed property type is
 * also visible without executing the classes, and so a failure points at the
 * exact declaration.
 *
 * It is deliberately not proof of runtime compatibility: a token match cannot
 * model every PHP inheritance rule. It guards the three declarations this plugin
 * overrides and is paired with the runtime test.
 */
final class ApplicationApiRequestDeclarationTest extends TestCase
{
    private const REQUEST_DIR = 'src/Http/Requests/Api/Application/Servers';

    /**
     * Every Application API request must declare the same member shapes as
     * App\Http\Requests\Api\Application\ApplicationApiRequest:
     *
     *  - protected ?string $resource   (invariant property type)
     *  - protected int $permission     (invariant property type)
     *  - public function rules(): array (covariant return type)
     */
    public function test_every_request_declares_the_parent_compatible_members(): void
    {
        $offenders = [];
        $files = $this->requestFiles();

        $this->assertCount(5, $files, 'All five Application API request classes must be present');

        foreach ($files as $file) {
            foreach ($this->declarationViolations((string) file_get_contents($file)) as $violation) {
                $offenders[] = $this->relativePath($file).'  '.$violation;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "ApplicationApiRequest subclasses must match the Pelican parent declarations:\n".
            implode("\n", $offenders)
        );
    }

    /**
     * Without this, the whole-directory guard above could pass by being blind.
     */
    public function test_detector_catches_non_nullable_resource(): void
    {
        $source = str_replace(
            'protected ?string $resource',
            'protected string $resource',
            $this->compatibleFixture()
        );

        $this->assertContains(
            '$resource must be declared protected ?string to match ApplicationApiRequest',
            $this->declarationViolations($source)
        );
    }

    /**
     * Reproduces the fatal error reported after the v2.2.0 hotfix: a child
     * rules() override without the parent's `array` return type.
     */
    public function test_detector_catches_rules_without_array_return_type(): void
    {
        $source = str_replace(
            'public function rules(): array',
            'public function rules()',
            $this->compatibleFixture()
        );

        $this->assertContains(
            'rules() must be declared public function rules(): array to match ApplicationApiRequest',
            $this->declarationViolations($source)
        );
    }

    public function test_detector_catches_non_int_permission(): void
    {
        $source = str_replace(
            'protected int $permission',
            'protected string $permission',
            $this->compatibleFixture()
        );

        $this->assertContains(
            '$permission must be declared protected int to match ApplicationApiRequest',
            $this->declarationViolations($source)
        );
    }

    public function test_detector_accepts_a_compatible_declaration(): void
    {
        $this->assertSame([], $this->declarationViolations($this->compatibleFixture()));
    }

    /**
     * Remove comments and whitespace so the checks are formatting-independent.
     */
    private function normalize(string $source): string
    {
        $normalized = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    continue;
                }

                $normalized .= $token[1];

                continue;
            }

            $normalized .= $token;
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function declarationViolations(string $source): array
    {
        $normalized = $this->normalize($source);
        $violations = [];

        if (! preg_match('/protected\?string\$resource=/', $normalized)) {
            $violations[] = '$resource must be declared protected ?string to match ApplicationApiRequest';
        }

        if (! preg_match('/protectedint\$permission=/', $normalized)) {
            $violations[] = '$permission must be declared protected int to match ApplicationApiRequest';
        }

        // The negative lookahead rejects unions such as `array|false`; the
        // parent returns exactly `array`.
        if (! preg_match('/publicfunctionrules\(\):array(?![a-zA-Z0-9_|\\\\])/', $normalized)) {
            $violations[] = 'rules() must be declared public function rules(): array to match ApplicationApiRequest';
        }

        return $violations;
    }

    private function compatibleFixture(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace SquadronStrike\ServerExpiry\Http\Requests\Api\Application\Servers;

        use App\Http\Requests\Api\Application\ApplicationApiRequest;
        use App\Services\Acl\Api\AdminAcl;

        final class FixtureRequest extends ApplicationApiRequest
        {
            protected ?string $resource = 'server';

            protected int $permission = AdminAcl::WRITE;

            public function rules(): array
            {
                return [];
            }
        }
        PHP;
    }

    /**
     * @return array<int, string>
     */
    private function requestFiles(): array
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

        return $files;
    }

    private function relativePath(string $file): string
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR;

        return str_replace($root, '', $file);
    }
}
