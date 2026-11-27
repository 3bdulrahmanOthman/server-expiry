<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Http\Controllers\Api\Application\Servers;

use App\Enums\SuspendAction;
use App\Http\Controllers\Api\Application\ApplicationApiController;
use App\Models\Server;
use App\Services\Servers\SuspensionService;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use SquadronStrike\ServerExpiry\Application\Services\ExpirationService;
use SquadronStrike\ServerExpiry\Domain\Expiration\Exceptions\InvalidExpirationOperation;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;
use SquadronStrike\ServerExpiry\Http\Requests\Api\Application\Servers\ClearExpirationRequest;
use SquadronStrike\ServerExpiry\Http\Requests\Api\Application\Servers\ExtendExpirationRequest;
use SquadronStrike\ServerExpiry\Http\Requests\Api\Application\Servers\RenewExpirationRequest;
use SquadronStrike\ServerExpiry\Http\Requests\Api\Application\Servers\ShowExpirationRequest;
use SquadronStrike\ServerExpiry\Http\Requests\Api\Application\Servers\UpdateExpirationRequest;
use Throwable;

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

    public function show(ShowExpirationRequest $request, Server $server)
    {
        $expirationDate = $this->expirationService->getExpiration($server->id);
        $status = $this->expirationService->getStatus($server->id);
        $isExpired = $this->expirationService->isExpired($server->id);
        $isInGracePeriod = $this->expirationService->isInGracePeriod($server->id);
        $remainingTime = $this->expirationService->getRemainingTime($server->id);

        return response()->json([
            'server_id' => $server->id,
            'expires_at' => $expirationDate->isPermanent() ? null : $expirationDate->getDateTime()->format(DateTimeInterface::ATOM),
            'status' => $status->value,
            'is_expired' => $isExpired,
            'is_in_grace_period' => $isInGracePeriod,
            'remaining_seconds' => $remainingTime
                ? ($remainingTime->invert === 1 ? 1 : -1) * ($remainingTime->days * 86400 + $remainingTime->h * 3600 + $remainingTime->i * 60 + $remainingTime->s)
                : null,
        ]);
    }

    public function update(UpdateExpirationRequest $request, Server $server)
    {
        $data = $request->validated();

        if ($data['permanent'] ?? false) {
            $this->expirationService->clearExpiration($server->id);

            return response()->json(['message' => 'Expiration cleared']);
        } else {
            if (! isset($data['expires_at'])) {
                return response()->json([
                    'error' => 'The expires_at field is required when permanent is false.',
                ], 422);
            }

            $expirationDate = ExpirationDate::fromString($data['expires_at']);
            $this->expirationService->setExpiration($server->id, $expirationDate);

            return response()->json(['message' => 'Expiration set']);
        }
    }

    public function extend(ExtendExpirationRequest $request, Server $server)
    {
        $data = $request->validated();

        try {
            $interval = new \DateInterval('PT'.$data['hours'].'H');
            $newExpiration = $this->expirationService->extendExpiration($server->id, $interval);
        } catch (InvalidExpirationOperation $exception) {
            // e.g. extending a permanent server — a domain rule violation,
            // not a server error.
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Expiration extended',
            'new_expires_at' => $newExpiration->isPermanent() ? null : $newExpiration->getDateTime()->format(DateTimeInterface::ATOM),
        ]);
    }

    public function renew(RenewExpirationRequest $request, Server $server)
    {
        $data = $request->validated();

        if (($data['permanent'] ?? false) && ! $this->expirationService->getExpiration($server->id)->isPermanent()) {
            return response()->json(['message' => 'Cannot renew to permanent'], 400);
        }

        if ($data['permanent'] ?? false) {
            $this->expirationService->clearExpiration($server->id);

            $this->reviveAfterRenewal($server, ExpirationDate::permanent());
        } else {
            if (! isset($data['expires_at'])) {
                return response()->json([
                    'error' => 'The expires_at field is required when permanent is false.',
                ], 422);
            }

            $expirationDate = ExpirationDate::fromString($data['expires_at']);
            $this->expirationService->renew($server->id, $expirationDate);

            $this->reviveAfterRenewal($server, $expirationDate);
        }

        return response()->json(['message' => 'Server renewed']);
    }

    /**
     * Mirror the admin UI's renewal behaviour: a renewed server that was
     * suspended because it expired comes back up, and its warning thresholds
     * reset so reminders start over for the new date.
     *
     * Without this the API and the UI disagree on the same operation: the UI
     * revives on renewal, the API used to leave the server suspended.
     */
    private function reviveAfterRenewal(Server $server, ExpirationDate $newExpirationDate): void
    {
        // A past date is not a revival; the lifecycle will handle the expiry.
        if (! $newExpirationDate->isPermanent() && $newExpirationDate->isExpired()) {
            return;
        }

        $server->forceFill(['expiry_warning_day' => null])->saveQuietly();

        // Only unsuspend if the suspension was due to expiration.
        if (! $server->isSuspended() || $server->suspension_reason !== 'expiration') {
            return;
        }

        try {
            app(SuspensionService::class)->handle($server, SuspendAction::Unsuspend);
        } catch (Throwable $exception) {
            // The renewal itself succeeded; a daemon sync failure here must not
            // fail the request. The panel applies the unsuspension to its
            // database before talking to the daemon.
            report($exception);

            Log::warning("Server Expiry Plugin: revival of server ID {$server->id} failed after renewal: {$exception->getMessage()}");
        }
    }

    public function destroy(ClearExpirationRequest $request, Server $server)
    {
        $this->expirationService->clearExpiration($server->id);

        return response()->json(['message' => 'Expiration cleared']);
    }
}
