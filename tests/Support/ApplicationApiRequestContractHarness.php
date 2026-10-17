<?php

declare(strict_types=1);

/**
 * Runtime inheritance-compatibility harness for the plugin's Application API
 * request classes.
 *
 * PHP enforces property-type invariance and method-signature compatibility when
 * a child class is declared. An incompatible declaration is a compile-time fatal
 * error that cannot be caught, so this harness must run in its own process: the
 * PHPUnit test starts it with output redirected to files and inspects the exit
 * code and messages.
 *
 * Modes:
 *   --mode=contract  Load the recorded, declaration-only Pelican parent fixture.
 *   --mode=real      Load the real parent from a Pelican panel checkout
 *                    (`--panel=/path/to/panel`, which must have vendor/autoload.php).
 *
 * Both modes verify that the loaded parent still matches
 * tests/Fixtures/PelicanContract/contract.php before loading the subclasses.
 *
 * Exit codes:
 *   0  success (JSON report on stdout)
 *   2  a requested class could not be loaded
 *   3  the loaded parent drifted from the recorded contract
 *   4  environment problem (missing autoloader/panel)
 *   5  usage error
 *   6  a loaded request class could not execute rules()
 *   255 native PHP fatal error (an incompatible declaration was loaded)
 *
 * This file is a test support script, not plugin runtime code.
 */
$options = getopt('', ['mode:', 'panel::', 'plugin::', 'require::', 'classes::']);

$mode = $options['mode'] ?? null;

if (! in_array($mode, ['contract', 'real'], true)) {
    fwrite(STDERR, "Usage: php ApplicationApiRequestContractHarness.php --mode=contract|real [--panel=PATH] [--plugin=PATH] [--require=FILE] [--classes=FQCN,...]\n");
    exit(5);
}

$pluginRoot = isset($options['plugin'])
    ? realpath((string) $options['plugin'])
    : dirname(__DIR__, 2);

if ($pluginRoot === false || ! is_dir($pluginRoot)) {
    fwrite(STDERR, "Plugin root not found.\n");
    exit(4);
}

$autoload = $pluginRoot.'/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Plugin autoloader not found at {$autoload}; run `composer install` first.\n");
    exit(4);
}

$parentClass = 'App\Http\Requests\Api\Application\ApplicationApiRequest';
$fixtureDirectory = __DIR__.'/../Fixtures/PelicanContract';

if ($mode === 'contract') {
    // Declaration-only stubs. Order matters: FormRequest and AdminAcl are
    // referenced by the parent's declaration.
    require $fixtureDirectory.'/FormRequest.php';
    require $fixtureDirectory.'/AdminAcl.php';
    require $fixtureDirectory.'/ApplicationApiRequest.php';
} else {
    $panel = isset($options['panel']) ? realpath((string) $options['panel']) : false;

    if ($panel === false || ! is_dir($panel)) {
        fwrite(STDERR, "--mode=real requires --panel=/path/to/pelican-panel.\n");
        exit(4);
    }

    $panelAutoload = $panel.'/vendor/autoload.php';

    if (! is_file($panelAutoload)) {
        fwrite(STDERR, "Panel autoloader not found at {$panelAutoload}; run `composer install` in the panel first.\n");
        exit(4);
    }

    require $panelAutoload;
}

require $autoload;

/**
 * Return a normalized, reflection-derived description of the declarations made
 * by $class itself (inherited members are ignored).
 *
 * @return array<string, mixed>
 */
function describeDeclarations(string $class): array
{
    $reflection = new ReflectionClass($class);

    $properties = [];

    foreach ($reflection->getProperties() as $property) {
        if ($property->getDeclaringClass()->getName() !== $class) {
            continue;
        }

        $properties[$property->getName()] = [
            'visibility' => visibility($property),
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

/**
 * @param  ReflectionProperty  $property
 */
function visibility($property): string
{
    return $property->isPublic() ? 'public' : ($property->isProtected() ? 'protected' : 'private');
}

/**
 * @param  array<string, mixed>  $array
 * @return array<string, mixed>
 */
function sortRecursive(array $array): array
{
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $array[$key] = sortRecursive($value);
        }
    }

    if (array_is_list($array)) {
        return $array;
    }

    ksort($array);

    return $array;
}

$recorded = require $fixtureDirectory.'/contract.php';
$source = $recorded['source'] ?? null;
unset($recorded['source']);

$actualParent = describeDeclarations($parentClass);
$expectedParent = sortRecursive($recorded);

if (sortRecursive($actualParent) !== $expectedParent) {
    fwrite(STDERR, "The loaded Pelican parent does not match the recorded contract.\n");
    fwrite(STDERR, "Expected:\n".json_encode($expectedParent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    fwrite(STDERR, "Actual:\n".json_encode(sortRecursive($actualParent), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    exit(3);
}

$defaultClasses = [
    'SquadronStrike\\ServerExpiry\\Http\\Requests\\Api\\Application\\Servers\\ShowExpirationRequest',
    'SquadronStrike\\ServerExpiry\\Http\\Requests\\Api\\Application\\Servers\\ClearExpirationRequest',
    'SquadronStrike\\ServerExpiry\\Http\\Requests\\Api\\Application\\Servers\\ExtendExpirationRequest',
    'SquadronStrike\\ServerExpiry\\Http\\Requests\\Api\\Application\\Servers\\RenewExpirationRequest',
    'SquadronStrike\\ServerExpiry\\Http\\Requests\\Api\\Application\\Servers\\UpdateExpirationRequest',
];

$classes = isset($options['classes'])
    ? array_values(array_filter(array_map('trim', explode(',', (string) $options['classes']))))
    : $defaultClasses;

if (isset($options['require'])) {
    $required = realpath((string) $options['require']);

    if ($required === false || ! is_file($required)) {
        fwrite(STDERR, "Required fixture not found: {$options['require']}\n");
        exit(4);
    }

    // Loading an intentionally incompatible fixture is expected to fatal here.
    require $required;
}

$report = [];

foreach ($classes as $class) {
    if (! class_exists($class)) {
        fwrite(STDERR, "Class could not be loaded: {$class}\n");
        exit(2);
    }

    if (! is_subclass_of($class, $parentClass)) {
        fwrite(STDERR, "Class does not extend {$parentClass}: {$class}\n");
        exit(2);
    }

    $declaration = describeDeclarations($class);

    // Signatures alone do not prove the intended validation behavior survived.
    // The parent class is loadable without a booted Laravel application, so the
    // rules() bodies are executed here and compared by the PHPUnit test.
    try {
        $declaration['rules_return'] = (new $class)->rules();
    } catch (Throwable $exception) {
        fwrite(STDERR, "Could not execute {$class}::rules(): ".$exception->getMessage()."\n");
        exit(6);
    }

    $report[$class] = $declaration;
}

$parentFile = (new ReflectionClass($parentClass))->getFileName();
$parentContents = $parentFile !== false ? file_get_contents($parentFile) : false;

echo json_encode([
    'mode' => $mode,
    'parent_class' => $parentClass,
    'parent_file' => $parentFile,
    // Normalize line endings so the hash identifies the upstream source rather
    // than the Windows checkout's CRLF conversion.
    'parent_sha256' => $parentContents !== false
        ? hash('sha256', str_replace("\r\n", "\n", $parentContents))
        : null,
    'source' => $source,
    'contract' => $actualParent,
    'classes' => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
