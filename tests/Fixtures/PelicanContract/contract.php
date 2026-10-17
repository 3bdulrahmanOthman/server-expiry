<?php

declare(strict_types=1);

/**
 * Recorded inheritance contract of
 * App\Http\Requests\Api\Application\ApplicationApiRequest at the Pelican version
 * this plugin targets.
 *
 * The harness reflects the loaded parent (fixture or real) and compares it to
 * this array. A drift here means the target panel's contract changed and the
 * plugin's request classes must be audited again.
 */
return [
    'source' => [
        'package' => 'pelican-dev/panel',
        'tag' => 'v1.0.0-beta38',
        'commit' => 'e84a4afd466e32f2b376774365185fe0342b73e6',
        'also_verified_at' => [
            'v1.0.0-rc.1' => '46fc1cf0ebce3f43a5dd38bb2c4a89ad0f668e00',
            'main' => '2026-10-17',
        ],
    ],
    'abstract' => true,
    'parent' => 'Illuminate\Foundation\Http\FormRequest',
    'properties' => [
        'permission' => [
            'visibility' => 'protected',
            'static' => false,
            'readonly' => false,
            'type' => 'int',
            'has_default' => true,
            'default' => 0,
        ],
        'resource' => [
            'visibility' => 'protected',
            'static' => false,
            'readonly' => false,
            'type' => '?string',
            'has_default' => false,
            'default' => null,
        ],
    ],
    'methods' => [
        'authorize' => [
            'visibility' => 'public',
            'static' => false,
            'final' => false,
            'abstract' => false,
            'return' => 'bool',
            'parameters' => [],
        ],
        'parameter' => [
            'visibility' => 'public',
            'static' => false,
            'final' => false,
            'abstract' => false,
            'return' => null,
            'parameters' => [
                [
                    'name' => 'key',
                    'type' => 'string',
                    'optional' => false,
                    'variadic' => false,
                    'by_reference' => false,
                ],
                [
                    'name' => 'expect',
                    'type' => 'string',
                    'optional' => false,
                    'variadic' => false,
                    'by_reference' => false,
                ],
            ],
        ],
        'rules' => [
            'visibility' => 'public',
            'static' => false,
            'final' => false,
            'abstract' => false,
            'return' => 'array',
            'parameters' => [],
        ],
        'withValidator' => [
            'visibility' => 'public',
            'static' => false,
            'final' => false,
            'abstract' => false,
            'return' => 'void',
            'parameters' => [
                [
                    'name' => 'validator',
                    'type' => 'Illuminate\Validation\Validator',
                    'optional' => false,
                    'variadic' => false,
                    'by_reference' => false,
                ],
            ],
        ],
    ],
];
