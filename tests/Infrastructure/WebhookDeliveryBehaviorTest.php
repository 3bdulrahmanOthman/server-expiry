<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Infrastructure;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookDelivery;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookEndpoint;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\WebhookDeliveryService;

/**
 * Behavioral coverage for the two webhook bugs fixed in this change, run
 * against a real SQLite database through real Eloquent models and the real
 * delivery service, with only the HTTP edge faked:
 *
 * - The initial send happens INLINE in queueDelivery (previously the row sat
 *   unclaimed until the abandoned-row recovery ran one lease later).
 * - The body on the wire is exactly the payload X-Signature covers, so a
 *   receiver verifying the signature over the raw request body succeeds
 *   (previously the body was JSON-encoded a second time and never matched).
 *
 * The harness boots Eloquent standalone via Capsule and hands the Http facade
 * a fakeable Factory; no panel, no network.
 */
final class WebhookDeliveryBehaviorTest extends TestCase
{
    private const SECRET = 'behavioral-test-secret';

    private Capsule $capsule;

    private HttpFactory $httpFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capsule = new Capsule;
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $container = Container::getInstance();
        $container->instance('db', $this->capsule->getDatabaseManager());
        $container->instance('log', new class
        {
            public function __call(string $method, array $args): void {}
        });

        $this->httpFactory = new HttpFactory;
        $container->instance(HttpFactory::class, $this->httpFactory);

        Facade::setFacadeApplication($container);

        $schema = $this->capsule->schema();

        $schema->create('webhook_endpoints', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->string('secret_key')->nullable();
            $table->json('events')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('failure_count')->default(0);
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });

        $schema->create('webhook_deliveries', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('webhook_endpoint_id');
            $table->string('event_type');
            $table->string('server_id');
            $table->json('payload');
            $table->integer('attempt')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->string('status')->default('pending');
            $table->integer('http_status_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
        });

        set_test_config([
            'server-expiry.webhook_max_attempts' => 3,
            'server-expiry.webhook_timeout_seconds' => 10,
            'server-expiry.webhook_backoff_base_seconds' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        $this->capsule->getDatabaseManager()->purge();

        parent::tearDown();
    }

    public function test_a_queued_delivery_is_sent_inline_and_the_row_records_the_success(): void
    {
        $this->httpFactory->fake(['*' => $this->httpFactory->response('OK', 200)]);

        $endpoint = $this->endpoint();
        $payload = ['server_id' => 2, 'expiration_date' => '2026-11-27T09:43:56+00:00'];

        (new WebhookDeliveryService)->queueDelivery($endpoint, 'server.expiry.created', 2, $payload);

        // The send happened synchronously, inside queueDelivery — this is the
        // regression that used to defer every first send by one lease (300s).
        $this->httpFactory->assertSentCount(1);

        $delivery = WebhookDelivery::sole();

        $this->assertSame('success', $delivery->status);
        $this->assertSame(1, $delivery->attempt, 'The inline send consumes exactly one attempt');
        $this->assertSame(200, $delivery->http_status_code);
        $this->assertNotNull($delivery->processed_at);
        $this->assertNull($delivery->claim_token, 'A finished send releases its claim');
    }

    public function test_the_wire_body_is_exactly_what_the_signature_covers(): void
    {
        $this->httpFactory->fake(['*' => $this->httpFactory->response('OK', 200)]);

        $endpoint = $this->endpoint();
        $payload = ['server_id' => 2, 'expiration_date' => '2026-11-27T09:43:56+00:00'];

        (new WebhookDeliveryService)->queueDelivery($endpoint, 'server.expiry.created', 2, $payload);

        // Receiver-side verification, byte for byte: hash the raw body exactly
        // as it arrived, with the X-Timestamp header, against the endpoint
        // secret. This is the contract every standard HMAC receiver implements.
        $this->httpFactory->assertSent(function (Request $request) use ($payload): bool {
            $body = $request->body();
            $signature = $request->header('X-Signature')[0] ?? '';
            $timestamp = $request->header('X-Timestamp')[0] ?? '';

            $this->assertNotSame('', $signature, 'X-Signature header must be present');
            $this->assertNotSame('', $timestamp, 'X-Timestamp header must be present');
            $this->assertSame(
                $payload,
                json_decode($body, true),
                'The body must decode to the payload (a double-encoded body decodes to a string)'
            );
            $this->assertSame(
                hash_hmac('sha256', $body.$timestamp, self::SECRET),
                $signature,
                'X-Signature must verify against the raw request body'
            );

            return true;
        });
    }

    public function test_a_failed_send_is_recorded_once_with_a_scheduled_retry(): void
    {
        $this->httpFactory->fake(['*' => $this->httpFactory->response('broken', 500)]);

        $endpoint = $this->endpoint();

        (new WebhookDeliveryService)->queueDelivery($endpoint, 'server.expiry.updated', 2, ['server_id' => 2]);

        $this->httpFactory->assertSentCount(1);

        $delivery = WebhookDelivery::sole();

        $this->assertSame('failed', $delivery->status);
        $this->assertSame(1, $delivery->attempt);
        // A non-2xx response is surfaced in error_message (the generic
        // \Exception carries no response object, so http_status_code stays
        // null on this path; only transport failures distinguish themselves).
        $this->assertStringContainsString('HTTP 500', (string) $delivery->error_message);
        $this->assertNotNull($delivery->next_attempt_at, 'A retry must be scheduled');
        $this->assertNull($delivery->claim_token, 'A finished attempt releases its claim');
    }

    private function endpoint(): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'name' => 'behavioral receiver',
            'url' => 'https://receiver.test/hook',
            'secret_key' => self::SECRET,
            'events' => ['server.expiry.created', 'server.expiry.updated'],
            'active' => true,
        ]);
    }
}
