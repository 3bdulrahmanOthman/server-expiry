<?php

namespace PelicanDev\ServerExpiry\Console\Commands;

use App\Enums\ServerState;
use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use PelicanDev\ServerExpiry\Notifications\ServerExpiringWarningNotification;
use Throwable;

class SendExpiryWarningsCommand extends Command
{
    protected $signature = 'pelican:send-expiry-warnings';

    protected $description = 'Sends mail + panel notifications to server owners before their server expires (warning_days_notice).';

    public function handle(): int
    {
        $thresholds = collect(config('server-expiry.warning_days_notice', []))
            ->map(fn ($days) => (int) $days)
            ->filter(fn ($days) => $days > 0)
            ->sort()
            ->values()
            ->all();

        if (empty($thresholds)) {
            $this->warn('No warning thresholds configured (SERVER_EXPIRY_WARNING_DAYS). Aborting.');

            return self::SUCCESS;
        }

        $maxThreshold = max($thresholds);

        $this->info("Scanning for servers expiring within {$maxThreshold} day(s)...");

        $servers = Server::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays($maxThreshold))
            ->where(fn (Builder $query) => $query
                ->whereNull('status')
                ->orWhere('status', '!=', ServerState::Suspended->value))
            ->with('user')
            ->get();

        if ($servers->isEmpty()) {
            $this->info('No servers entering a warning window.');

            return self::SUCCESS;
        }

        $count = 0;
        foreach ($servers as $server) {
            $daysRemaining = (int) ceil(now()->diffInSeconds($server->expires_at) / 86400);
            $threshold = $this->findThreshold($thresholds, $daysRemaining);

            if ($threshold === null) {
                continue;
            }

            // Idempotency: only notify once per threshold per server.
            if ((int) $server->expiry_warning_day === $threshold) {
                continue;
            }

            if (! $server->user) {
                Log::warning("Server Expiry Plugin: Server ID {$server->id} has no owner; skipping expiry warning.");

                continue;
            }

            try {
                $server->user->notify(new ServerExpiringWarningNotification($server, $threshold, $daysRemaining));
                $server->update(['expiry_warning_day' => $threshold]);

                $this->line("   -> Expiry warning ({$threshold}d) sent for Server ID {$server->id} (Name: {$server->name}) - {$daysRemaining} day(s) remaining");
                $count++;
            } catch (Throwable $exception) {
                Log::error("Server Expiry Plugin: Failed to send expiry warning for server ID {$server->id}: {$exception->getMessage()}");
                $this->error("   -> Failed to send warning for Server ID {$server->id}: {$exception->getMessage()}");
            }
        }

        $this->info("Sent {$count} expiry warning(s).");

        return self::SUCCESS;
    }

    /**
     * Returns the smallest configured threshold that the server has entered,
     * e.g. 5 days remaining -> threshold 7 (already inside the 7-day window).
     *
     * @param  int[]  $thresholds
     */
    private function findThreshold(array $thresholds, int $daysRemaining): ?int
    {
        foreach ($thresholds as $threshold) {
            if ($threshold >= $daysRemaining) {
                return $threshold;
            }
        }

        return null;
    }
}
