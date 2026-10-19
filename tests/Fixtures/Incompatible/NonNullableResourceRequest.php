<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Fixtures\Incompatible;

use App\Http\Requests\Api\Application\ApplicationApiRequest;

/**
 * Negative-control fixture: deliberately declares `$resource` non-nullable,
 * reproducing the original v2.2.0 fatal error. The inheritance test reads this
 * file's source instead of loading it, because an incompatible declaration
 * cannot be loaded without killing the process. Never shipped or autoloaded by
 * production code.
 */
final class NonNullableResourceRequest extends ApplicationApiRequest
{
    protected string $resource = 'server';

    public function rules(): array
    {
        return [];
    }
}
