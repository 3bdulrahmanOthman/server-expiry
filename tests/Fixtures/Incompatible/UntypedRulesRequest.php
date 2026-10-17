<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Fixtures\Incompatible;

use App\Http\Requests\Api\Application\ApplicationApiRequest;

/**
 * Negative-control fixture: deliberately omits the parent's `array` return type
 * on `rules()`, reproducing the fatal error reported after the v2.2.0 hotfix.
 * Loaded only by the runtime compatibility harness in a throwaway subprocess;
 * never shipped or autoloaded by production code.
 */
final class UntypedRulesRequest extends ApplicationApiRequest
{
    protected ?string $resource = 'server';

    public function rules()
    {
        return [];
    }
}
