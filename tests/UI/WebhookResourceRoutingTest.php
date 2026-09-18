<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Guards the admin webhook resource route hierarchy.
 *
 * Filament derives a resource's default URL slug from the class path AFTER
 * `...\Resources\`, INCLUDING the organizational sub-namespace. With the
 * resources living in `Resources\WebhookEndpoint\` / `Resources\WebhookDelivery\`
 * that produced /admin/webhook-endpoint/webhook-endpoints and
 * /admin/webhook-delivery/webhook-deliveries in production.
 *
 * Both resources must therefore pin an explicit two-segment slug so the URLs
 * live under the Server Expiry hierarchy (/admin/server-expiry/...), while the
 * "Server Expiry" navigation group is preserved.
 */
final class WebhookResourceRoutingTest extends TestCase
{
    private const ENDPOINT = __DIR__.'/../../src/Filament/Admin/Resources/WebhookEndpoint/WebhookEndpointResource.php';

    private const DELIVERY = __DIR__.'/../../src/Filament/Admin/Resources/WebhookDelivery/WebhookDeliveryResource.php';

    public function test_endpoint_resource_pins_server_expiry_route_hierarchy(): void
    {
        $source = $this->source(self::ENDPOINT);

        $this->assertStringContainsString(
            "protected static ?string \$slug = 'server-expiry/webhook-endpoints';",
            $source,
            'WebhookEndpointResource must pin the explicit slug server-expiry/webhook-endpoints'
        );
    }

    public function test_delivery_resource_pins_server_expiry_route_hierarchy(): void
    {
        $source = $this->source(self::DELIVERY);

        $this->assertStringContainsString(
            "protected static ?string \$slug = 'server-expiry/webhook-deliveries';",
            $source,
            'WebhookDeliveryResource must pin the explicit slug server-expiry/webhook-deliveries'
        );
    }

    public function test_both_resources_keep_the_server_expiry_navigation_group(): void
    {
        foreach ([self::ENDPOINT, self::DELIVERY] as $file) {
            $this->assertStringContainsString(
                "\$navigationGroup = 'Server Expiry'",
                $this->source($file),
                $file.' must keep the "Server Expiry" navigation group'
            );
        }
    }

    private function source(string $path): string
    {
        return (string) file_get_contents($path);
    }
}
