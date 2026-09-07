<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Http\Controllers\Api\Application\Servers;

use App\Http\Controllers\Api\Application\ApplicationApiController;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SquadronStrike\ServerExpiry\Application\Services\ExpirationService;

/**
 * Handles API endpoints for server expiration management.
 */
class ExpirationController extends ApplicationApiController
{
    public function __construct(
        protected ExpirationService $expirationService
    ) {
        parent::__construct();
    }

    public function show(Server $server)
    {
        $this->authorize('view', $server);
        
        $expirationDate = $this->expirationService->getExpiration($server->id);
        $status = $this->expirationService->getStatus($server->id);
        $isExpired = $this->expirationService->isExpired($server->id);
        $isInGracePeriod = $this->expirationService->isInGracePeriod($server->id);
        $remainingTime = $this->expirationService->getRemainingTime($server->id);

        return response()->json([
            'server_id' => $server->id,
            'expires_at' => $expirationDate->isPermanent() ? null : $expirationDate->getDateTime()->toISO8601String(),
            'status' => $status->value,
            'is_expired' => $isExpired,
            'is_in_grace_period' => $isInGracePeriod,
            'remaining_seconds' => $remainingTime ? $remainingTime->days * 86400 + $remainingTime->h * 3600 + $remainingTime->i * 60 + $remainingTime->s : null,
        ]);
    }

    public function update(Request $request, Server $server)
    {
        $this->authorize('update', $server);
        
        $data = $request->validate([
            'expires_at' => 'required_without:permanent|date|after_or_equal:today',
            'permanent' => 'sometimes|boolean',
        ]);

        if ($data['permanent'] ?? false) {
            $this->expirationService->clearExpiration($server->id);
            return response()->json(['message' => 'Expiration cleared']);
        } else {
            $expirationDate = new \SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate(
                \DateTimeImmutable::createFromInterface($data['expires_at'])
            );
            $this->expirationService->setExpiration($server->id, $expirationDate);
            return response()->json(['message' => 'Expiration set']);
        }
    }

    public function extend(Request $request, Server $server)
    {
        $this->authorize('update', $server);
        
        $data = $request->validate([
            'hours' => 'required|integer|min|1',
        ]);

        $interval = new \DateInterval('PT' . $data['hours'] . 'H');
        $newExpiration = $this->expirationService->extendExpiration($server->id, $interval);
        
        return response()->json([
            'message' => 'Expiration extended',
            'new_expires_at' => $newExpiration->isPermanent() ? null : $newExpiration->getDateTime()->toISO8601String()
        ]);
    }

    public function renew(Request $request, Server $server)
    {
        $this->authorize('renew', $server);
        
        $data = $request->validate([
            'expires_at' => 'required_without:permanent|date|after_or_equal:today',
            'permanent' => 'sometimes|boolean',
        ]);

        if (($data['permanent'] ?? false) && !$this->expirationService->getExpiration($server->id)->isPermanent()) {
            return response()->json(['message' => 'Cannot renew to permanent'], 400);
        }

        if ($data['permanent'] ?? false) {
            $this->expirationService->clearExpiration($server->id);
        } else {
            $expirationDate = new \SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate(
                \DateTimeImmutable::createFromInterface($data['expires_at'])
            );
            $this->expirationService->renew($server->id, $expirationDate);
        }
        
        return response()->json(['message' => 'Server renewed']);
    }

    public function destroy(Server $server)
    {
        $this->authorize('delete', $server);
        $this->expirationService->clearExpiration($server->id);
        return response()->json(['message' => 'Expiration cleared']);
    }
}
