<?php

/*
 * Webhook delivery lifecycle feature tests for the Server Expiry plugin.
 *
 * These run INSIDE a Pelican panel checkout (Pest + the panel's
 * IntegrationTestCase supply the Laravel application, the sqlite database and
 * RefreshDatabase). They exercise the crash-safe claim added in Checkpoint H:
 * a worker takes an atomic claim before sending, may only finalize while it
 * still owns that claim, and a claim abandoned by a crash is recoverable — as a
 * retry while budget remains, and as a recorded terminal failure once it is spent.
 *
 * NOT EXECUTED ANYWHERE YET. This development machine runs PHP 8.2.12 while the
 * panel requires PHP >= 8.3.0, so `vendor/bin/pest` aborts in the panel's
 * platform_check.php before any test loads. The CI job below has not run against
 * this file either: it exports the plugin source from `HEAD` with `git archive`,
 * so it can only ever test committed work. The framework-free claim/lease rules
 * are covered locally by tests/Support/WebhookDeliveryClaimTest.php, and the SQL
 * semantics — including the recovery CAS this file depends on — by
 * tests/Support/WebhookDeliveryClaimSqlTest.php.
 *
 * Bootstrap: this file never touches Filament, but it still cannot run without
 * two things the panel does not provide under `runningUnitTests()` — the plugin's
 * PSR-4 namespace (PluginService::loadPlugins() returns early) and the plugin's
 * tables (the same early return skips the `$migrator->path()` registration). Both
 * are supplied by the helpers declared in WebhookResourcesTest.php; Pest loads
 * every file in the directory before running any test, and the CI job runs the
 * whole directory in one process.
 */

use Illuminate\Support\Facades\Http;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookDelivery;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookEndpoint;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\WebhookDeliveryService;
use SquadronStrike\ServerExpiry\Support\WebhookDeliveryClaim;

/**
 * @param  array<string, mixed>  $overrides
 */
function serverExpiryDeliveryEndpoint(array $overrides = []): WebhookEndpoint
{
    return WebhookEndpoint::create(array_merge([
        'name' => 'Expiry receiver',
        'url' => 'https://receiver.test/hooks',
        'secret_key' => 'test-secret',
        'events' => ['server.expiry.updated'],
        'active' => true,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function serverExpiryDelivery(WebhookEndpoint $endpoint, array $overrides = []): WebhookDelivery
{
    return WebhookDelivery::create(array_merge([
        'webhook_endpoint_id' => $endpoint->id,
        'event_type' => 'server.expiry.updated',
        'server_id' => '1',
        'payload' => ['server_id' => 1],
        // `attempt` counts attempts already consumed; a new row starts at 0.
        'attempt' => 0,
        'max_attempts' => 3,
        'status' => 'failed',
        'queued_at' => now()->subMinutes(10),
        'next_attempt_at' => now()->subMinute(),
    ], $overrides));
}

/*
 * Register the plugin and apply its migrations, exactly as the sibling resource
 * test does before each of its own tests. Both helpers are declared there and
 * guarded by function_exists; see the file header for why this file cannot run on
 * its own and why the shared declaration is available by the time this runs.
 */
beforeEach(function (): void {
    if (! function_exists('serverExpiryBootPlugin') || ! function_exists('serverExpiryMigrate')) {
        throw new RuntimeException(
            'This file shares the plugin bootstrap declared in WebhookResourcesTest.php; '.
            'run the whole tests/Filament/ServerExpiry directory, as the CI job does.'
        );
    }

    serverExpiryBootPlugin();
    serverExpiryMigrate();
});

it('refuses the second worker that picks up an already-claimed due row', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = serverExpiryDeliveryEndpoint();
    $delivery = serverExpiryDelivery($endpoint);
    $service = app(WebhookDeliveryService::class);

    // Both workers are handed the same due row. This is a sequential re-entry
    // check, not true concurrency: the real proof that two racers cannot both
    // match one row is the guarded UPDATE in WebhookDeliveryClaimSqlTest.
    $first = $service->attemptDelivery($delivery->fresh());
    $second = $service->attemptDelivery($delivery->fresh());

    expect($first)->toBeTrue('The first worker owns the claim')
        ->and($second)->toBeFalse('The loser must not send')
        ->and($delivery->fresh()->attempt)->toBe(1, 'The claim consumed exactly one attempt');

    Http::assertSentCount(1);
});

it('recovers a pending row abandoned before its first claim', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = serverExpiryDeliveryEndpoint();
    // Crash between INSERT and claim: pending, never claimed, long past.
    $stranded = serverExpiryDelivery($endpoint, [
        'status' => 'pending',
        'attempt' => 0,
        'next_attempt_at' => now()->subMinutes(30),
        'claimed_at' => null,
        'claim_token' => null,
    ]);

    $processed = app(WebhookDeliveryService::class)->processPendingDeliveries();

    expect($processed)->toBe(1, 'A stranded pending row must be recovered')
        ->and($stranded->fresh()->status)->toBe('success');

    Http::assertSentCount(1);
});

it('does not reclaim a freshly created pending row', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = serverExpiryDeliveryEndpoint();
    // Just inserted by an in-flight inline attempt.
    serverExpiryDelivery($endpoint, [
        'status' => 'pending',
        'attempt' => 0,
        'next_attempt_at' => now(),
        'claimed_at' => null,
        'claim_token' => null,
    ]);

    expect(app(WebhookDeliveryService::class)->processPendingDeliveries())->toBe(0);

    Http::assertNothingSent();
});

it('reclaims an expired processing lease but never a live one', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = serverExpiryDeliveryEndpoint();
    $live = serverExpiryDelivery($endpoint, [
        'status' => 'pending',
        'attempt' => 1,
        'claimed_at' => now()->subSeconds(5),
        'claim_token' => 'live-worker',
    ]);
    $expired = serverExpiryDelivery($endpoint, [
        'status' => 'pending',
        'attempt' => 1,
        'claimed_at' => now()->subSeconds(WebhookDeliveryClaim::LEASE_SECONDS + 60),
        'claim_token' => 'dead-worker',
    ]);

    $processed = app(WebhookDeliveryService::class)->processPendingDeliveries();

    expect($processed)->toBe(1, 'Only the expired lease is recoverable')
        ->and($live->fresh()->claim_token)->toBe('live-worker', 'A live lease must not be stolen')
        ->and($expired->fresh()->status)->toBe('success');
});

/*
 * Checkpoint J: a worker that claims the final permitted attempt and then dies
 * leaves a row whose budget is spent, so the retry predicate can never reach it
 * again. These tests pin the recovery pass that closes such a delivery out as a
 * terminal failure exactly once, without granting another send.
 */

it('finalizes a delivery whose final attempt died with its worker', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = serverExpiryDeliveryEndpoint();
    $stranded = serverExpiryDelivery($endpoint, [
        'status' => 'failed',
        'attempt' => 3,
        'max_attempts' => 3,
        'claim_token' => 'dead-worker',
        'claimed_at' => now()->subSeconds(WebhookDeliveryClaim::LEASE_SECONDS + 60),
        'processed_at' => null,
    ]);

    $handled = app(WebhookDeliveryService::class)->processPendingDeliveries();

    $recovered = $stranded->fresh();

    expect($handled)->toBe(1, 'The stranded delivery is closed out')
        ->and($recovered->status)->toBe('failed', 'It ends as a terminal failure')
        ->and($recovered->processed_at)->not->toBeNull('A terminal failure records when it was processed')
        ->and($recovered->claim_token)->toBeNull('The dead claim is released')
        ->and($recovered->claimed_at)->toBeNull()
        ->and($recovered->attempt)->toBe(3, 'Recovery never consumes another attempt')
        ->and((int) $endpoint->fresh()->failure_count)->toBe(1)
        ->and($endpoint->fresh()->last_failed_at)->not->toBeNull();

    // Bad weather: another tick must change nothing and must not resend.
    expect(app(WebhookDeliveryService::class)->processPendingDeliveries())->toBe(0)
        ->and($stranded->fresh()->processed_at)->not->toBeNull()
        ->and((int) $endpoint->fresh()->failure_count)->toBe(1, 'The final failure is counted exactly once');

    Http::assertNothingSent();
});

it('finalizes an initial send that died with a single-attempt budget', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = serverExpiryDeliveryEndpoint();
    $stranded = serverExpiryDelivery($endpoint, [
        'status' => 'pending',
        'attempt' => 1,
        'max_attempts' => 1,
        'claim_token' => 'dead-worker',
        'claimed_at' => now()->subSeconds(WebhookDeliveryClaim::LEASE_SECONDS + 60),
        'processed_at' => null,
    ]);

    expect(app(WebhookDeliveryService::class)->processPendingDeliveries())->toBe(1)
        ->and($stranded->fresh()->status)->toBe('failed')
        ->and($stranded->fresh()->processed_at)->not->toBeNull()
        ->and($stranded->fresh()->claim_token)->toBeNull();

    Http::assertNothingSent();
});

it('never finalizes an exhausted claim whose lease is still live', function () {
    Http::fake();

    $endpoint = serverExpiryDeliveryEndpoint();
    $live = serverExpiryDelivery($endpoint, [
        'status' => 'failed',
        'attempt' => 3,
        'max_attempts' => 3,
        'claim_token' => 'live-worker',
        'claimed_at' => now()->subSeconds(5),
        'processed_at' => null,
    ]);

    expect(app(WebhookDeliveryService::class)->processPendingDeliveries())->toBe(0)
        ->and($live->fresh()->processed_at)->toBeNull()
        ->and($live->fresh()->claim_token)->toBe('live-worker', 'A running worker keeps its claim')
        ->and((int) $endpoint->fresh()->failure_count)->toBe(0);

    Http::assertNothingSent();
});

it('retries an abandoned claim that still has budget instead of finalizing it', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = serverExpiryDeliveryEndpoint();
    $abandoned = serverExpiryDelivery($endpoint, [
        'status' => 'failed',
        'attempt' => 1,
        'max_attempts' => 3,
        'claim_token' => 'dead-worker',
        'claimed_at' => now()->subSeconds(WebhookDeliveryClaim::LEASE_SECONDS + 60),
        'next_attempt_at' => now()->subMinute(),
        'processed_at' => null,
    ]);

    expect(app(WebhookDeliveryService::class)->processPendingDeliveries())->toBe(1)
        ->and($abandoned->fresh()->status)->toBe('success', 'It is retried, not written off')
        ->and($abandoned->fresh()->attempt)->toBe(2, 'The retry claim consumes exactly one attempt');

    Http::assertSentCount(1);
});

it('discards a slow worker result after its exhausted claim was finalized', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = serverExpiryDeliveryEndpoint();
    $stranded = serverExpiryDelivery($endpoint, [
        'status' => 'failed',
        'attempt' => 3,
        'max_attempts' => 3,
        'claim_token' => 'slow-worker',
        'claimed_at' => now()->subSeconds(WebhookDeliveryClaim::LEASE_SECONDS + 60),
        'processed_at' => null,
    ]);

    app(WebhookDeliveryService::class)->processPendingDeliveries();

    // The "dead" worker turns out to be merely slow and now reports success.
    $overwritten = WebhookDelivery::where('id', $stranded->id)
        ->where('claim_token', 'slow-worker')
        ->update(['status' => 'success', 'processed_at' => null]);

    expect($overwritten)->toBe(0, 'A stale owner must not overwrite the finalization')
        ->and($stranded->fresh()->status)->toBe('failed')
        ->and($stranded->fresh()->processed_at)->not->toBeNull()
        ->and((int) $endpoint->fresh()->failure_count)->toBe(1);
});

/*
 * Checkpoint L: each finalization commits together with its endpoint counter inside
 * one short transaction, and the counter itself is written by a single statement.
 *
 * The listener below is the failure seam. The service writes the counter through the
 * model, so aborting the `updating` event stands in for a database error on exactly
 * that statement — there is no other way to make an UPDATE fail on demand.
 *
 * The fault is armed through a process-global flag instead of being removed with
 * `forget()`: the panel's IntegrationTestCase calls Event::fake(), so the model
 * dispatcher is an EventFake whose forget() does not remove a listener. Each test
 * also runs against a fresh application (and therefore a fresh EventFake), so arming
 * registers the listener on the current dispatcher every time; disarming only clears
 * the flag, leaving earlier registrations inert.
 */
function serverExpiryFailEndpointWrites(): void
{
    if (! isset($GLOBALS['serverExpiryEndpointWriteFault'])) {
        $GLOBALS['serverExpiryEndpointWriteFault'] = (object) ['enabled' => false];
    }

    $GLOBALS['serverExpiryEndpointWriteFault']->enabled = true;

    WebhookEndpoint::getEventDispatcher()->listen(
        'eloquent.updating: '.WebhookEndpoint::class,
        function (): void {
            if ($GLOBALS['serverExpiryEndpointWriteFault']->enabled) {
                throw new RuntimeException('webhook_endpoints is unavailable');
            }
        }
    );
}

function serverExpiryAllowEndpointWrites(): void
{
    if (isset($GLOBALS['serverExpiryEndpointWriteFault'])) {
        $GLOBALS['serverExpiryEndpointWriteFault']->enabled = false;
    }
}

it('rolls the recovery finalization back when the endpoint update fails', function () {
    Http::fake();

    $endpoint = serverExpiryDeliveryEndpoint();
    $stranded = serverExpiryDelivery($endpoint, [
        'status' => 'failed',
        'attempt' => 3,
        'max_attempts' => 3,
        'claim_token' => 'dead-worker',
        'claimed_at' => now()->subSeconds(WebhookDeliveryClaim::LEASE_SECONDS + 60),
        'processed_at' => null,
    ]);
    $claimedAt = $stranded->fresh()->claimed_at;

    serverExpiryFailEndpointWrites();

    try {
        expect(fn () => app(WebhookDeliveryService::class)->processPendingDeliveries())
            ->toThrow(RuntimeException::class, 'webhook_endpoints is unavailable');

        // The delivery write rolled back with the counter write: the row is the same
        // abandoned claim it was, not a finalized delivery the endpoint never counted.
        $rolledBack = $stranded->fresh();
        expect($rolledBack->processed_at)->toBeNull('The pair rolls back together')
            ->and($rolledBack->status)->toBe('failed')
            ->and($rolledBack->claim_token)->toBe('dead-worker')
            ->and($rolledBack->claimed_at->equalTo($claimedAt))->toBeTrue()
            ->and($rolledBack->attempt)->toBe(3, 'The consumed budget is not restored')
            ->and((int) $endpoint->fresh()->failure_count)->toBe(0)
            ->and($endpoint->fresh()->last_failed_at)->toBeNull();
    } finally {
        serverExpiryAllowEndpointWrites();
    }

    // With the endpoint writable again the next pass closes the row out, exactly once.
    expect(app(WebhookDeliveryService::class)->processPendingDeliveries())->toBe(1)
        ->and($stranded->fresh()->processed_at)->not->toBeNull()
        ->and((int) $endpoint->fresh()->failure_count)->toBe(1)
        ->and($endpoint->fresh()->last_failed_at)->not->toBeNull();

    Http::assertNothingSent();
});

it('rolls the terminal failure back when the endpoint update fails', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $endpoint = serverExpiryDeliveryEndpoint();
    // A single-attempt budget makes the first failure terminal, so the counter update
    // is part of the same finalization.
    $delivery = serverExpiryDelivery($endpoint, ['max_attempts' => 1]);

    serverExpiryFailEndpointWrites();

    try {
        expect(fn () => app(WebhookDeliveryService::class)->attemptDelivery($delivery->fresh()))
            ->toThrow(RuntimeException::class, 'webhook_endpoints is unavailable');

        $rolledBack = $delivery->fresh();
        expect($rolledBack->processed_at)->toBeNull('The terminal state rolls back with the counter')
            ->and($rolledBack->status)->toBe('failed')
            ->and($rolledBack->claim_token)->not->toBeNull('The claim survives, so the row is not stranded')
            ->and($rolledBack->attempt)->toBe(1)
            ->and((int) $endpoint->fresh()->failure_count)->toBe(0)
            ->and($endpoint->fresh()->last_failed_at)->toBeNull();
    } finally {
        serverExpiryAllowEndpointWrites();
    }

    // Age the restored lease as the vanished worker's would be, and the abandoned
    // claim is then recovered and counted once.
    $delivery->fresh()->update(['claimed_at' => now()->subSeconds(WebhookDeliveryClaim::LEASE_SECONDS + 60)]);

    expect(app(WebhookDeliveryService::class)->processPendingDeliveries())->toBe(1)
        ->and($delivery->fresh()->processed_at)->not->toBeNull()
        ->and((int) $endpoint->fresh()->failure_count)->toBe(1);

    Http::assertSentCount(1);
});

it('rolls the success outcome back when the endpoint update fails, then retries', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = serverExpiryDeliveryEndpoint();
    $delivery = serverExpiryDelivery($endpoint);

    serverExpiryFailEndpointWrites();

    try {
        // The 2xx is not recorded as a success the endpoint never saw: the rollback
        // leaves the claim in place, so the failure handler turns this attempt into a
        // retryable failure instead.
        expect(app(WebhookDeliveryService::class)->attemptDelivery($delivery->fresh()))->toBeTrue();

        $rolledBack = $delivery->fresh();
        expect($rolledBack->status)->toBe('failed', 'The success rolled back with the endpoint write')
            ->and($rolledBack->processed_at)->toBeNull()
            ->and($rolledBack->claim_token)->toBeNull('The retry finalization released the claim')
            ->and($rolledBack->error_message)->toContain('webhook_endpoints is unavailable')
            ->and($rolledBack->next_attempt_at)->not->toBeNull()
            ->and((int) $endpoint->fresh()->failure_count)->toBe(0);
    } finally {
        serverExpiryAllowEndpointWrites();
    }

    // Once the endpoint is writable again the retry is delivered and recorded, and
    // the delivery state and the endpoint's success tracking commit together.
    $delivery->fresh()->update(['next_attempt_at' => now()->subMinute()]);
    expect(app(WebhookDeliveryService::class)->attemptDelivery($delivery->fresh()))->toBeTrue()
        ->and($delivery->fresh()->status)->toBe('success')
        ->and((int) $endpoint->fresh()->failure_count)->toBe(0)
        ->and($endpoint->fresh()->last_success_at)->not->toBeNull();

    Http::assertSentCount(2);
});

/*
 * Checkpoint M: the success path writes endpoint state only when the claim-owned
 * finalization succeeded. A worker whose lease ran out mid-request no longer owns the
 * outcome even though the receiver answered 2xx, so it must not reset counters the
 * current owner may have just written.
 */
it('does not reset endpoint counters when a stale success loses the claim', function () {
    $endpoint = serverExpiryDeliveryEndpoint();
    $delivery = serverExpiryDelivery($endpoint);

    // Start from a failure history, so a stale reset would be visible.
    $endpoint->update(['failure_count' => 4, 'last_failed_at' => now()->subHour()]);
    $lastFailedAt = $endpoint->fresh()->last_failed_at;

    // The reclaim lands while the request is in flight: between the claim and the
    // response a newer worker takes the row over.
    Http::fake(function ($request) use ($delivery) {
        WebhookDelivery::where('id', $delivery->id)->update([
            'claim_token' => 'newer-worker',
            'claimed_at' => now(),
        ]);

        return Http::response('ok', 200);
    });

    expect(app(WebhookDeliveryService::class)->attemptDelivery($delivery->fresh()))
        ->toBeTrue('This worker did take the claim before losing it');

    $after = $delivery->fresh();

    expect($after->status)->toBe('failed', 'A stale worker does not finalize the delivery')
        ->and($after->processed_at)->toBeNull()
        ->and($after->claim_token)->toBe('newer-worker', 'The newer owner keeps its claim')
        ->and((int) $endpoint->fresh()->failure_count)->toBe(4, 'Endpoint counters must not be reset')
        ->and($endpoint->fresh()->last_success_at)->toBeNull()
        ->and($endpoint->fresh()->last_failed_at->equalTo($lastFailedAt))->toBeTrue();

    Http::assertSentCount(1);
});

/*
 * max_attempts is the exact number of HTTP sends, the initial one included, and
 * the delivery always ends in a recorded terminal state rather than in limbo.
 */
it('sends exactly max_attempts times and then closes the delivery', function (int $maxAttempts) {
    Http::fake(['*' => Http::response('boom', 500)]);

    $endpoint = serverExpiryDeliveryEndpoint();
    $delivery = serverExpiryDelivery($endpoint, ['max_attempts' => $maxAttempts]);
    $service = app(WebhookDeliveryService::class);

    for ($round = 0; $round < $maxAttempts + 3; $round++) {
        $record = $delivery->fresh();

        if ($record->processed_at !== null) {
            break;
        }

        $record->update(['next_attempt_at' => now()->subMinute()]);
        $service->attemptDelivery($record);
    }

    Http::assertSentCount($maxAttempts);

    expect($delivery->fresh()->attempt)->toBe($maxAttempts)
        ->and($delivery->fresh()->processed_at)->not->toBeNull('The budget ends in a terminal failure, never in limbo')
        ->and($delivery->fresh()->claim_token)->toBeNull()
        ->and((int) $endpoint->fresh()->failure_count)->toBe(1, 'One delivery, one failure, however many sends');
})->with([1, 2, 3]);

it('prevents a stale worker from finalizing a claim owned by a newer worker', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = serverExpiryDeliveryEndpoint();
    $delivery = serverExpiryDelivery($endpoint, [
        'status' => 'pending',
        'attempt' => 0,
        'claimed_at' => now()->subSeconds(WebhookDeliveryClaim::LEASE_SECONDS + 60),
        'claim_token' => 'slow-worker',
    ]);

    // A newer worker reclaims the expired lease and succeeds.
    expect(app(WebhookDeliveryService::class)->attemptDelivery($delivery->fresh()))->toBeTrue();
    expect($delivery->fresh()->status)->toBe('success');

    // The slow worker's late response now tries to write its own outcome.
    $overwritten = WebhookDelivery::where('id', $delivery->id)
        ->where('claim_token', 'slow-worker')
        ->update(['status' => 'failed', 'error_message' => 'late stale failure']);

    expect($overwritten)->toBe(0, 'A stale owner must not overwrite the newer claim')
        ->and($delivery->fresh()->status)->toBe('success')
        ->and($delivery->fresh()->error_message)->toBeNull();
});

it('retries after a simulated crash once the remote already accepted', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = serverExpiryDeliveryEndpoint();
    $delivery = serverExpiryDelivery($endpoint);
    $service = app(WebhookDeliveryService::class);

    expect($service->attemptDelivery($delivery->fresh()))->toBeTrue();

    // Simulate the crash AFTER the receiver accepted but BEFORE success was
    // persisted: restore the row to the state it had while the request was in
    // flight, i.e. with the claim already consumed (attempt = 1).
    $delivery->fresh()->update([
        'status' => 'failed',
        'processed_at' => null,
        'claimed_at' => null,
        'claim_token' => null,
        'attempt' => 1,
        'next_attempt_at' => now()->subMinute(),
    ]);

    expect($service->attemptDelivery($delivery->fresh()))->toBeTrue('At-least-once: the delivery is sent again');

    Http::assertSentCount(2);
});

it('preserves the backoff schedule, attempt limits and terminal failure', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $endpoint = serverExpiryDeliveryEndpoint();
    $delivery = serverExpiryDelivery($endpoint);
    $service = app(WebhookDeliveryService::class);

    // Attempt 1 of 3 fails -> scheduled for retry, not terminal. The clock is
    // anchored to a whole second because the database stores seconds only: a frozen
    // `now()` carrying microseconds would otherwise read back ~0.9s short.
    $firstAttemptAt = now()->startOfSecond();
    expect($service->attemptDelivery($delivery->fresh()))->toBeTrue();
    $afterFirst = $delivery->fresh();
    // Read before the update below, which mutates this same model instance.
    $firstScheduled = $afterFirst->next_attempt_at;
    expect($afterFirst->status)->toBe('failed')
        ->and($afterFirst->processed_at)->toBeNull()
        ->and($afterFirst->claim_token)->toBeNull()
        ->and($afterFirst->next_attempt_at)->not->toBeNull();
    expect((int) $endpoint->fresh()->failure_count)->toBe(0);

    // Attempt 2 of 3.
    $afterFirst->update(['next_attempt_at' => now()->subMinute()]);
    $secondAttemptAt = now()->startOfSecond();
    expect($service->attemptDelivery($afterFirst->fresh()))->toBeTrue();
    $afterSecond = $delivery->fresh();
    $secondScheduled = $afterSecond->next_attempt_at;

    // Attempt 3 of 3 fails -> terminal.
    $third = $delivery->fresh();
    $third->update(['next_attempt_at' => now()->subMinute()]);
    expect($service->attemptDelivery($third->fresh()))->toBeTrue();
    $terminal = $delivery->fresh();

    // Backoff still grows one base interval (1s) at a time, each delay measured
    // from the moment its own attempt was made. A terminal failure schedules no
    // further retry — `next_attempt_at` is written only by the retry branch — so
    // there is no third due time to measure against. The exact schedule is pinned
    // without a database by WebhookDeliveryClaimTest::test_backoff_schedule_is_preserved.
    $firstDelay = $firstAttemptAt->diffInSeconds($firstScheduled, true);
    $secondDelay = $secondAttemptAt->diffInSeconds($secondScheduled, true);
    expect($firstDelay)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2, 'First retry waits one base interval')
        ->and($secondDelay)->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(3, 'Second retry waits two')
        ->and($secondDelay)->toBeGreaterThan($firstDelay, 'The backoff grows');

    expect($terminal->attempt)->toBe(3)
        ->and($terminal->processed_at)->not->toBeNull();
    expect((int) $endpoint->fresh()->failure_count)->toBe(1);
    expect($endpoint->fresh()->last_failed_at)->not->toBeNull();

    // Exhausted rows are never selected again.
    $terminal->update(['next_attempt_at' => now()->subMinute()]);
    expect($service->processPendingDeliveries())->toBe(0);

    Http::assertSentCount(3);
});

it('sends a stable per-delivery identity without changing the endpoint header', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = serverExpiryDeliveryEndpoint();
    $delivery = serverExpiryDelivery($endpoint);

    app(WebhookDeliveryService::class)->attemptDelivery($delivery->fresh());

    Http::assertSent(function ($request) use ($endpoint, $delivery) {
        return $request->hasHeader('X-Webhook-ID', (string) $endpoint->id)
            && $request->hasHeader('X-Webhook-Delivery-ID', (string) $delivery->id);
    });
});
