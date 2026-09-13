<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Http\Requests\Api\Application\Servers;

use App\Services\Acl\Api\AdminAcl;
use App\Http\Requests\Api\Application\ApplicationApiRequest;

/**
 * Request class for renewing server expiration.
 */
class RenewExpirationRequest extends ApplicationApiRequest
{
    protected string $resource = 'server';

    protected int $permission = AdminAcl::WRITE;

    public function rules()
    {
        return [
            'expires_at' => 'required_without:permanent|date|after_or_equal:today',
            'permanent' => 'sometimes|boolean',
        ];
    }
}
