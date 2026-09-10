<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Http\Requests\Api\Application\Servers;

use App\Services\Acl\Api\AdminAcl;
use App\Http\Requests\Api\Application\ApplicationApiRequest;

/**
 * Request class for clearing server expiration.
 */
class ClearExpirationRequest extends ApplicationApiRequest
{
    protected string $resource = 'server';

    protected int $permission = AdminAcl::WRITE;

    public function rules()
    {
        return [];
    }
}
