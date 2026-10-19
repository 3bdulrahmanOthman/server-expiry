<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Source;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Inheritance-compatibility proof for every Application API request.
 *
 * PHP raises an uncatchable compile-time fatal when a child redeclares a parent
 * property with a different type, or overrides a method with an incompatible
 * signature. A test suite therefore cannot load an incompatible class safely.
 *
 * This test runs entirely in the current process: it loads the Pelican parent
 * (the real one when a checkout is available, the recorded contract fixture
 * otherwise), verifies the parent still matches tests/Fixtures/PelicanContract/
 * contract.php, then loads the five request classes and inspects them with
 * reflection.
 *
 * Before each class is autoloaded, its source is checked against the two
 * inherited members PHP enforces here (`$resource`, `$permission` and
 * `rules(): array`). A regression therefore fails as an assertion with a clear
 * message instead of killing the test process with a fatal error. The negative
 * controls in tests/Fixtures/Incompatible are checked from source only and are
 * never loaded.
 *
 * No subprocess, temporary file or filesystem write is required, so the test is
 * safe for environments that forbid process execution (for example the Pelican
 * Hub source scan).
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

    private const REQUEST_DIR = 'src/Http/Requests/Api/Application/Servers';

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $report = null;

    public function test_all_five_requests_load_against_the_available_pelican_parent(): void
    {
        $report = $this->report();

        $this->assertContains($report['mode'], ['contract', 'real']);
        $this->assertSame('v1.0.0-beta38', $report['source']['tag']);
        $this->assertSame('e84a4afd466e32f2b376774365185fe0342b73e6', $report['source']['commit']);

        $this->assertSame(
            array_column(self::REQUESTS, 'class'),
            array_keys($report['classes']),
            'The test must load exactly the five audited request classes'
        );

        $this->assertParentContract($report['contract']);
        $this->assertDeclaredContracts($report['classes']);

        if ($report['mode'] !== 'real') {
            return;
        }

        $panel = realpath((string) $report['panel']);
        $parentFile = realpath((string) $report['parent_file']);

        $this->assertNotFalse($panel, 'The panel checkout must resolve to a real path');
        $this->assertNotFalse($parentFile, 'The loaded parent must resolve to a real file');
        $this->assertStringStartsWith(
            strtolower($panel),
            strtolower($parentFile),
            'The real-parent run must load ApplicationApiRequest from the supplied panel checkout'
        );
        $this->assertStringEndsWith(
            'app'.DIRECTORY_SEPARATOR.'http'.DIRECTORY_SEPARATOR.'requests'.DIRECTORY_SEPARATOR.'api'.
            DIRECTORY_SEPARATOR.'application'.DIRECTORY_SEPARATOR.'applicationapirequest.php',
            strtolower($parentFile)
        );
    }

    /**
     * Reproduces the original v2.2.0 fatal error: `protected string $resource`
     * against the parent's invariant `?string` property type.
     */
    public function test_detector_rejects_a_non_nullable_resource(): void
    {
        $violations = $this->inheritanceViolations(
            (string) file_get_contents($this->fixturePath('Incompatible/NonNullableResourceRequest.php'))
        );

        $this->assertContains(
            '$resource must be declared protected ?string to match ApplicationApiRequest',
            $violations
        );
    }

    /**
     * Reproduces the fatal error reported after the v2.2.0 hotfix: a child
     * `rules()` override without the parent's `array` return type.
     */
    public function test_detector_rejects_rules_without_the_array_return_type(): void
    {
        $violations = $this->inheritanceViolations(
            (string) file_get_contents($this->fixturePath('Incompatible/UntypedRulesRequest.php'))
        );

        $this->assertContains(
            'rules() must be declared public function rules(): array to match ApplicationApiRequest',
            $violations
        );
    }

    /**
     * Load the parent, verify the recorded contract, then load and describe the
     * five request classes. The work happens once per process and is shared by
     * every assertion above.
     *
     * @return array<string, mixed>
     */
    private function report(): array
    {
        if (self::$report !== null) {
            return self::$report;
        }

        $panel = $this->locatePanel();
        $mode = $panel === null ? 'contract' : 'real';

        $problems = [];
        $contract = [];
        $source = null;
        $parentFile = null;
        $classes = [];

        try {
            $before = spl_autoload_functions() ?: [];

            if ($mode === 'contract') {
                // Declaration-only stubs. Order matters: FormRequest and AdminAcl
                // are referenced by the parent's declaration.
                require_once $this->fixturePath('PelicanContract/FormRequest.php');
                require_once $this->fixturePath('PelicanContract/AdminAcl.php');
                require_once $this->fixturePath('PelicanContract/ApplicationApiRequest.php');
            } else {
                // The panel ships its own dev dependencies (including another
                // PHPUnit). Its autoloader is used only while the parent and the
                // request classes are loaded, then unregistered so PHPUnit keeps
                // resolving its own classes from this project's vendor tree.
                require $panel.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
            }

            if (! class_exists(self::PARENT_CLASS)) {
                $problems[] = 'The '.$mode.' Pelican parent could not be loaded: '.self::PARENT_CLASS;
            } else {
                $parentFile = (new ReflectionClass(self::PARENT_CLASS))->getFileName();

                $recorded = require $this->fixturePath('PelicanContract/contract.php');
                $source = $recorded['source'] ?? null;
                unset($recorded['source']);

                $contract = $this->declarationsOf(self::PARENT_CLASS);
                $expected = self::sortRecursive($recorded);

                if (self::sortRecursive($contract) !== $expected) {
                    $problems[] =
                        "The loaded Pelican parent does not match the recorded contract.\n".
                        "Expected:\n".json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n".
                        "Actual:\n".json_encode(self::sortRecursive($contract), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                } else {
                    foreach (self::REQUESTS as $shortName => $expectedRequest) {
                        $class = $expectedRequest['class'];
                        $file = $this->pluginRoot().DIRECTORY_SEPARATOR.self::REQUEST_DIR.DIRECTORY_SEPARATOR.$shortName.'.php';

                        $violations = $this->inheritanceViolations((string) file_get_contents($file));

                        if ($violations !== []) {
                            $problems[] = $shortName.' would fatal against '.self::PARENT_CLASS.":\n".implode("\n", $violations);

                            continue;
                        }

                        if (! class_exists($class)) {
                            $problems[] = 'Class could not be loaded: '.$class;

                            continue;
                        }

                        if (! is_subclass_of($class, self::PARENT_CLASS)) {
                            $problems[] = 'Class does not extend '.self::PARENT_CLASS.': '.$class;

                            continue;
                        }

                        $declaration = $this->declarationsOf($class);

                        // Signatures alone do not prove the intended validation
                        // behavior survived. The parent class is loadable without a
                        // booted Laravel application, so the rules() bodies are
                        // executed here.
                        try {
                            $declaration['rules_return'] = (new $class)->rules();
                        } catch (\Throwable $exception) {
                            $problems[] = 'Could not execute '.$class.'::rules(): '.$exception->getMessage();

                            continue;
                        }

                        $classes[$class] = $declaration;
                    }
                }
            }
        } finally {
            foreach (spl_autoload_functions() ?: [] as $autoloader) {
                if (! in_array($autoloader, $before, true)) {
                    spl_autoload_unregister($autoloader);
                }
            }
        }

        if ($problems !== []) {
            $this->fail(implode("\n\n", $problems));
        }

        return self::$report = [
            'mode' => $mode,
            'panel' => $panel,
            'parent_file' => $parentFile,
            'source' => $source,
            'contract' => $contract,
            'classes' => $classes,
        ];
    }

    /**
     * Return a normalized, reflection-derived description of the declarations
     * made by $class itself (inherited members are ignored).
     *
     * @return array<string, mixed>
     */
    private function declarationsOf(string $class): array
    {
        $reflection = new ReflectionClass($class);

        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $properties[$property->getName()] = [
                'visibility' => self::visibility($property),
                'static' => $property->isStatic(),
                'readonly' => $property->isReadOnly(),
                'type' => $property->hasType() ? (string) $property->getType() : null,
                'has_default' => $property->hasDefaultValue(),
                'default' => $property->hasDefaultValue() ? $property->getDefaultValue() : null,
            ];
        }

        $methods = [];

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $parameters = [];

            foreach ($method->getParameters() as $parameter) {
                $parameters[] = [
                    'name' => $parameter->getName(),
                    'type' => $parameter->hasType() ? (string) $parameter->getType() : null,
                    'optional' => $parameter->isOptional(),
                    'variadic' => $parameter->isVariadic(),
                    'by_reference' => $parameter->isPassedByReference(),
                ];
            }

            $methods[$method->getName()] = [
                'visibility' => $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private'),
                'static' => $method->isStatic(),
                'final' => $method->isFinal(),
                'abstract' => $method->isAbstract(),
                'return' => $method->hasReturnType() ? (string) $method->getReturnType() : null,
                'parameters' => $parameters,
            ];
        }

        ksort($properties);
        ksort($methods);

        return [
            'abstract' => $reflection->isAbstract(),
            'parent' => $reflection->getParentClass()?->getName(),
            'properties' => $properties,
            'methods' => $methods,
        ];
    }

    private static function visibility(ReflectionProperty $property): string
    {
        return $property->isPublic() ? 'public' : ($property->isProtected() ? 'protected' : 'private');
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private static function sortRecursive(array $array): array
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = self::sortRecursive($value);
            }
        }

        if (array_is_list($array)) {
            return $array;
        }

        ksort($array);

        return $array;
    }

    /**
     * Check a request source against the inherited declarations before PHP
     * autoloads it. These are the members every Application API request
     * overrides; matching them is what keeps the class loadable.
     *
     * @return array<int, string>
     */
    private function inheritanceViolations(string $source): array
    {
        $normalized = self::normalize($source);
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

    /**
     * Remove comments and whitespace so the checks are formatting-independent.
     */
    private static function normalize(string $source): string
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

            $this->assertArrayHasKey($class, $classes, $shortName.' was not loaded');

            $declaration = $classes[$class];

            $this->assertFalse($declaration['abstract']);
            $this->assertSame(self::PARENT_CLASS, $declaration['parent']);
            $this->assertSame(
                ['permission', 'resource'],
                array_keys($declaration['properties']),
                $shortName.' must not declare properties beyond the audited ones'
            );
            $this->assertSame(
                ['rules'],
                array_keys($declaration['methods']),
                $shortName.' must not override methods beyond the audited ones'
            );

            $resource = $declaration['properties']['resource'];
            $this->assertSame('protected', $resource['visibility'], $shortName.'::$resource visibility');
            $this->assertSame('?string', $resource['type'], $shortName.'::$resource type');
            $this->assertFalse($resource['static'], $shortName.'::$resource must not be static');
            $this->assertFalse($resource['readonly'], $shortName.'::$resource must not be readonly');
            $this->assertSame('server', $resource['default'], $shortName.'::$resource default');

            $permission = $declaration['properties']['permission'];
            $this->assertSame('protected', $permission['visibility'], $shortName.'::$permission visibility');
            $this->assertSame('int', $permission['type'], $shortName.'::$permission type');
            $this->assertFalse($permission['static'], $shortName.'::$permission must not be static');
            $this->assertSame(
                $expected['permission'],
                $permission['default'],
                $shortName.'::$permission authorization level changed'
            );

            $rules = $declaration['methods']['rules'];
            $this->assertSame('public', $rules['visibility'], $shortName.'::rules() visibility');
            $this->assertFalse($rules['static'], $shortName.'::rules() must not be static');
            $this->assertSame('array', $rules['return'], $shortName.'::rules() return type');
            $this->assertSame([], $rules['parameters'], $shortName.'::rules() must take no parameters');

            $this->assertSame(
                $expected['rules'],
                $declaration['rules_return'],
                $shortName.'::rules() validation behavior changed'
            );
        }
    }

    /**
     * Resolve a Pelican checkout.
     *
     * An explicit PELICAN_PANEL_PATH is authoritative: when it is set, it must
     * resolve to a complete panel or the test fails. Falling back to a sibling
     * checkout is for local runs; only a run without either uses the recorded
     * contract fixture.
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

    private function pluginRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
