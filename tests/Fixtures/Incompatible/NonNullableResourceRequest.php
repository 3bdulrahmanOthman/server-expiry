<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Fixtures\Incompatible;

use App\Http\Requests\Api\Application\ApplicationApiRequest;

/**
 * Negative-control fixture: deliberately declares `$resource` non-nullable,
 * reproducing the original v2.2.0 fatal error. Loaded only by the runtime
 * compatibility harness in a throwaway subprocess; never shipped or autoloaded
 * by production code.
 */
final class NonNullableResourceRequest extends ApplicationApiRequest
{
    protected string $resource = 'server';

    public function rules(): array
    {
        return [];
    }
}
