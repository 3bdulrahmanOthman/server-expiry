<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Console\Commands;

use Illuminate\Console\Command;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\WebhookDeliveryService;

/**
 * Processes webhook deliveries that failed and are due for a retry
 * (exponential backoff from the configured base delay). Wired into the
 * scheduler by ServerExpiryServiceProvider; safe to run more than once.
 */
class ProcessWebhookDeliveriesCommand extends Command
{
    protected $signature = 'pelican:process-webhook-deliveries';

    protected $description = 'Processes pending webhook deliveries that are due for retry.';

    public function __construct(
        private readonly WebhookDeliveryService $deliveryService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $processed = $this->deliveryService->processPendingDeliveries();

        $this->info("Processed {$processed} due webhook delivery(ies).");

        return self::SUCCESS;
    }
}
