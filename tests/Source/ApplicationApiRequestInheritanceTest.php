<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Source;

use PHPUnit\Framework\TestCase;

/**
 * Runtime inheritance-compatibility proof for every Application API request.
 *
 * PHP raises an uncatchable compile-time fatal when a child redeclares a parent
 * property with a different type, or overrides a method with an incompatible
 * signature. A normal unit test cannot load such a class without killing the
 * test process, so this test runs the real declarations in a subprocess and
 * inspects the exit code and fatal message.
 *
 * Two parents are exercised:
 *
 *  1. The recorded declaration-only Pelican contract fixture, which runs on every
 *     PHP version in every job (no Laravel install required).
 *  2. The real App\Http\Requests\Api\Application\ApplicationApiRequest loaded from
 *     a Pelican checkout. When PELICAN_PANEL_PATH is explicitly set it is
 *     authoritative: an invalid path fails the test. Without it, sibling
 *     discovery is used for local runs, and the test skips only when no panel is
 *     available.
 *
 * Both modes first verify that the loaded parent still matches
 * tests/Fixtures/PelicanContract/contract.php, so a panel that changes the
 * contract fails this test instead of silently weakening it.
 */
final class ApplicationApiRequestInheritanceTest extends TestCase
{
    /**
     * The five subclasses, with the authorization level and validation rules
     * each must keep.
     *
     * Permission values are AdminAcl::READ (1) and AdminAcl::WRITE (2) from
     * Pelican v1.0.0-beta38; the fixture's AdminAcl stub uses the same values.
     *
     * @var array<string, array{class: string, permission: int, rules: array<string, string>}>
     */
    private const REQUESTS = [
        'ShowExpirationRequest' => [
            'class' => 'SquadronStrike\\ServerExpiry\\Http\\Requests\\Api\\Application\\Servers\\ShowExpirationRequest',
            'permission' => 1,
            'rules' => [],
        ],
        'ClearExpirationRequest' => [
            'class' => 'SquadronStrike\\ServerExpiry\\Http\\Requests\\Api\\Application\\Servers\\ClearExpirationRequest',
            'permission' => 2,
            'rules' => [],
        ],
        'ExtendExpirationRequest' => [
            'class' => 'SquadronStrike\\ServerExpiry\\Http\\Requests\\Api\\Application\\Servers\\ExtendExpirationRequest',
            'permission' => 2,
            'rules' => [
                'hours' => 'required|integer|min:1',
            ],
        ],
        'RenewExpirationRequest' => [
            'class' => 'SquadronStrike\\ServerExpiry\\Http\\Requests\\Api\\Application\\Servers\\RenewExpirationRequest',
            'permission' => 2,
            'rules' => [
                'expires_at' => 'required_without:permanent|date|after_or_equal:today',
                'permanent' => 'sometimes|boolean',
            ],
        ],
        'UpdateExpirationRequest' => [
            'class' => 'SquadronStrike\\ServerExpiry\\Http\\Requests\\Api\\Application\\Servers\\UpdateExpirationRequest',
            'permission' => 2,
            'rules' => [
                'expires_at' => 'required_without:permanent|date|after_or_equal:today',
                'permanent' => 'sometimes|boolean',
            ],
        ],
    ];

    private const PARENT_CLASS = 'App\\Http\\Requests\\Api\\Application\\ApplicationApiRequest';

    public function test_all_five_requests_load_against_the_recorded_pelican_contract(): void
    {
        $result = $this->runHarness(['--mode=contract']);
        $report = $this->decodeSuccessfulReport($result);

        $this->assertSame('contract', $report['mode']);
        $this->assertSame('v1.0.0-beta38', $report['source']['tag']);
        $this->assertSame('e84a4afd466e32f2b376774365185fe0342b73e6', $report['source']['commit']);

        $this->assertSame(
            array_column(self::REQUESTS, 'class'),
            array_keys($report['classes']),
            'The harness must load exactly the five audited request classes'
        );

        $this->assertParentContract($report['contract']);
        $this->assertDeclaredContracts($report['classes']);
    }

    public function test_all_five_requests_load_against_the_real_pelican_parent(): void
    {
        $panel = $this->locatePanel();

        if ($panel === null) {
            $this->markTestSkipped(
                'No Pelican panel checkout found. The recorded-contract runtime test still ran; '.
                'set PELICAN_PANEL_PATH to a Pelican checkout with vendor/ installed (or place it at '.
                $this->pluginRoot().DIRECTORY_SEPARATOR.'panel) to repeat this check against the real parent.'
            );
        }

        $result = $this->runHarness(['--mode=real', '--panel='.$panel]);
        $report = $this->decodeSuccessfulReport($result);

        $this->assertSame('real', $report['mode']);

        $realParentFile = strtolower((string) realpath($report['parent_file']));
        $this->assertStringStartsWith(
            strtolower((string) realpath($panel)),
            $realParentFile,
            'The real-parent run must load ApplicationApiRequest from the supplied panel checkout'
        );
        $this->assertStringEndsWith(
            'app'.DIRECTORY_SEPARATOR.'http'.DIRECTORY_SEPARATOR.'requests'.DIRECTORY_SEPARATOR.'api'.
            DIRECTORY_SEPARATOR.'application'.DIRECTORY_SEPARATOR.'applicationapirequest.php',
            $realParentFile
        );

        $this->assertParentContract($report['contract']);
        $this->assertDeclaredContracts($report['classes']);
    }

    /**
     * Reproduces the original v2.2.0 fatal error: `protected string $resource`
     * against the parent's invariant `?string` property type.
     */
    public function test_detector_rejects_a_non_nullable_resource_at_runtime(): void
    {
        $fixture = $this->fixturePath('Incompatible/NonNullableResourceRequest.php');
        $class = 'SquadronStrike\\ServerExpiry\\Tests\\Fixtures\\Incompatible\\NonNullableResourceRequest';

        $result = $this->runHarness([
            '--mode=contract',
            '--require='.$fixture,
            '--classes='.$class,
        ]);

        $output = $this->combinedOutput($result);

        $this->assertNotSame(0, $result['exit'], 'The harness must fail for a non-nullable $resource');
        $this->assertStringContainsString('::$resource must be ?string', $output);
        $this->assertStringContainsString('NonNullableResourceRequest', $output);
    }

    /**
     * Reproduces the fatal error reported after the v2.2.0 hotfix: a child
     * `rules()` override without the parent's `array` return type.
     */
    public function test_detector_rejects_rules_without_the_array_return_type_at_runtime(): void
    {
        $fixture = $this->fixturePath('Incompatible/UntypedRulesRequest.php');
        $class = 'SquadronStrike\\ServerExpiry\\Tests\\Fixtures\\Incompatible\\UntypedRulesRequest';

        $result = $this->runHarness([
            '--mode=contract',
            '--require='.$fixture,
            '--classes='.$class,
        ]);

        $output = $this->combinedOutput($result);

        $this->assertNotSame(0, $result['exit'], 'The harness must fail for an untyped rules() override');
        $this->assertStringContainsString('rules() must be compatible with', $output);
        $this->assertStringContainsString('rules(): array', $output);
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private function assertParentContract(array $contract): void
    {
        $this->assertTrue($contract['abstract'], 'ApplicationApiRequest is abstract');
        $this->assertSame('Illuminate\\Foundation\\Http\\FormRequest', $contract['parent']);

        $this->assertSame(['permission', 'resource'], array_keys($contract['properties']));

        $resource = $contract['properties']['resource'];
        $this->assertSame('protected', $resource['visibility']);
        $this->assertSame('?string', $resource['type']);
        $this->assertFalse($resource['has_default'], 'The parent $resource has no default value');

        $permission = $contract['properties']['permission'];
        $this->assertSame('protected', $permission['visibility']);
        $this->assertSame('int', $permission['type']);
        $this->assertSame(0, $permission['default']);

        $this->assertSame(['authorize', 'parameter', 'rules', 'withValidator'], array_keys($contract['methods']));

        $this->assertSame('bool', $contract['methods']['authorize']['return']);
        $this->assertSame('array', $contract['methods']['rules']['return']);
        $this->assertSame([], $contract['methods']['rules']['parameters']);
        $this->assertSame('void', $contract['methods']['withValidator']['return']);
        $this->assertNull($contract['methods']['parameter']['return']);
    }

    /**
     * Assert every subclass declares only the three audited overrides, with the
     * exact shapes PHP already accepted above.
     *
     * @param  array<string, array<string, mixed>>  $classes
     */
    private function assertDeclaredContracts(array $classes): void
    {
        foreach (self::REQUESTS as $shortName => $expected) {
            $class = $expected['class'];

            $this->assertArrayHasKey($class, $classes, "{$shortName} was not loaded");

            $declaration = $classes[$class];

            $this->assertFalse($declaration['abstract']);
            $this->assertSame(self::PARENT_CLASS, $declaration['parent']);
            $this->assertSame(
                ['permission', 'resource'],
                array_keys($declaration['properties']),
                "{$shortName} must not declare properties beyond the audited ones"
            );
            $this->assertSame(
                ['rules'],
                array_keys($declaration['methods']),
                "{$shortName} must not override methods beyond the audited ones"
            );

            $resource = $declaration['properties']['resource'];
            $this->assertSame('protected', $resource['visibility'], "{$shortName}::\$resource visibility");
            $this->assertSame('?string', $resource['type'], "{$shortName}::\$resource type");
            $this->assertFalse($resource['static'], "{$shortName}::\$resource must not be static");
            $this->assertFalse($resource['readonly'], "{$shortName}::\$resource must not be readonly");
            $this->assertSame('server', $resource['default'], "{$shortName}::\$resource default");

            $permission = $declaration['properties']['permission'];
            $this->assertSame('protected', $permission['visibility'], "{$shortName}::\$permission visibility");
            $this->assertSame('int', $permission['type'], "{$shortName}::\$permission type");
            $this->assertFalse($permission['static'], "{$shortName}::\$permission must not be static");
            $this->assertSame(
                $expected['permission'],
                $permission['default'],
                "{$shortName}::\$permission authorization level changed"
            );

            $rules = $declaration['methods']['rules'];
            $this->assertSame('public', $rules['visibility'], "{$shortName}::rules() visibility");
            $this->assertFalse($rules['static'], "{$shortName}::rules() must not be static");
            $this->assertSame('array', $rules['return'], "{$shortName}::rules() return type");
            $this->assertSame([], $rules['parameters'], "{$shortName}::rules() must take no parameters");

            $this->assertSame(
                $expected['rules'],
                $declaration['rules_return'],
                "{$shortName}::rules() validation behavior changed"
            );
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function decodeSuccessfulReport(array $result): array
    {
        $this->assertSame(
            0,
            $result['exit'],
            "The compatibility harness failed.\n".$this->combinedOutput($result)
        );

        try {
            $report = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->fail('The harness did not produce valid JSON: '.$exception->getMessage()."\n".$this->combinedOutput($result));
        }

        $this->assertIsArray($report);
        $this->assertArrayHasKey('classes', $report);
        $this->assertArrayHasKey('contract', $report);
        $this->assertArrayHasKey('source', $report);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function combinedOutput(array $result): string
    {
        return "exit: {$result['exit']}\nSTDOUT:\n{$result['stdout']}\nSTDERR:\n{$result['stderr']}";
    }

    /**
     * @param  array<int, string>  $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runHarness(array $arguments): array
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is required to isolate the uncatchable inheritance fatal.');
        }

        $stdout = $this->temporaryFile('se-contract-out-');
        $stderr = $this->temporaryFile('se-contract-err-');

        $descriptors = [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', $stdout, 'w'],
            2 => ['file', $stderr, 'w'],
        ];

        $command = array_merge([PHP_BINARY, $this->harnessPath()], $arguments);
        $process = proc_open($command, $descriptors, $pipes, $this->pluginRoot());

        if (! is_resource($process)) {
            @unlink($stdout);
            @unlink($stderr);

            $this->fail('Could not start the compatibility harness.');
        }

        $exit = proc_close($process);
        $stdoutContents = (string) file_get_contents($stdout);
        $stderrContents = (string) file_get_contents($stderr);

        @unlink($stdout);
        @unlink($stderr);

        return [
            'exit' => $exit,
            'stdout' => $stdoutContents,
            'stderr' => $stderrContents,
        ];
    }

    /**
     * Create a harness output file.
     *
     * Some locked-down or portable PHP builds cannot create files in the OS
     * temporary directory even though it is readable. Fall back to a workspace
     * directory so the test still runs; the files are deleted afterwards.
     */
    private function temporaryFile(string $prefix): string
    {
        $file = @tempnam(sys_get_temp_dir(), $prefix);

        if ($file !== false) {
            return $file;
        }

        $fallback = $this->pluginRoot().DIRECTORY_SEPARATOR.'.phpunit-tmp';

        if (! is_dir($fallback) && ! @mkdir($fallback, 0777, true) && ! is_dir($fallback)) {
            $this->fail("Could not create a temporary directory at {$fallback}.");
        }

        $file = @tempnam($fallback, $prefix);

        if ($file === false) {
            $this->fail('Could not create temporary files for the compatibility harness.');
        }

        return $file;
    }

    /**
     * Resolve a Pelican checkout.
     *
     * An explicit PELICAN_PANEL_PATH is authoritative: when it is set, it must
     * resolve to a complete panel or the test fails. Falling back to a sibling
     * checkout or skipping would hide a broken CI configuration. Only a local
     * run without an explicit path uses sibling discovery, and only that case
     * may skip when no panel is available.
     */
    private function locatePanel(): ?string
    {
        $configured = getenv('PELICAN_PANEL_PATH');

        if ($configured !== false) {
            $panel = $this->validatedPanel(realpath($configured));

            if ($panel === null) {
                $this->fail(
                    'PELICAN_PANEL_PATH is set to "'.$configured.'" but it is not a Pelican panel '.
                    'checkout containing app/Http/Requests/Api/Application/ApplicationApiRequest.php '.
                    'and vendor/autoload.php.'
                );
            }

            return $panel;
        }

        foreach ([
            $this->pluginRoot().DIRECTORY_SEPARATOR.'panel',
            dirname($this->pluginRoot()).DIRECTORY_SEPARATOR.'panel',
        ] as $candidate) {
            $panel = $this->validatedPanel(realpath($candidate));

            if ($panel !== null) {
                return $panel;
            }
        }

        return null;
    }

    private function validatedPanel(string|false $path): ?string
    {
        if ($path === false || ! is_dir($path)) {
            return null;
        }

        if (
            is_file($path.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Http'.DIRECTORY_SEPARATOR.'Requests'.DIRECTORY_SEPARATOR.'Api'.DIRECTORY_SEPARATOR.'Application'.DIRECTORY_SEPARATOR.'ApplicationApiRequest.php')
            && is_file($path.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php')
        ) {
            return $path;
        }

        return null;
    }

    private function fixturePath(string $relative): string
    {
        return $this->pluginRoot().DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function harnessPath(): string
    {
        return $this->pluginRoot().DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'ApplicationApiRequestContractHarness.php';
    }

    private function pluginRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
