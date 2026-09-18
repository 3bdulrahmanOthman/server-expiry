<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Guards the v2 plugin settings model (v2.1, H1).
 *
 * The settings modal previously exposed only the four v1-era lifecycle knobs;
 * the webhook delivery parameters were hardcoded in the service and the owner
 * page had no configurable support target. Every added setting must map to
 * real backend behavior via the documented env keys.
 */
final class PluginSettingsTest extends TestCase
{
    private const PLUGIN = __DIR__.'/../../src/ServerExpiryPlugin.php';

    private const TOGGLE_FIELDS = ['auto_suspend_enabled', 'notify_owner_on_suspend'];

    private const TEXT_FIELDS = [
        'grace_period_hours',
        'warning_days_notice',
        'webhook_max_attempts',
        'webhook_timeout_seconds',
        'webhook_backoff_base_seconds',
        'support_url',
    ];

    private const ENV_MAPPINGS = [
        "'SERVER_EXPIRY_AUTO_SUSPEND' => \$data['auto_suspend_enabled'],",
        "'SERVER_EXPIRY_GRACE_HOURS' => (int) \$data['grace_period_hours'],",
        "'SERVER_EXPIRY_WARNING_DAYS' => \$data['warning_days_notice'],",
        "'SERVER_EXPIRY_NOTIFY_ON_SUSPEND' => \$data['notify_owner_on_suspend'],",
        "'SERVER_EXPIRY_WEBHOOK_MAX_ATTEMPTS' => (int) \$data['webhook_max_attempts'],",
        "'SERVER_EXPIRY_WEBHOOK_TIMEOUT' => (int) \$data['webhook_timeout_seconds'],",
        "'SERVER_EXPIRY_WEBHOOK_BACKOFF_BASE' => (int) \$data['webhook_backoff_base_seconds'],",
        "'SERVER_EXPIRY_SUPPORT_URL' => \$data['support_url'] ?? '',",
    ];

    public function test_settings_form_exposes_all_fields(): void
    {
        $source = $this->source(self::PLUGIN);

        foreach (self::TOGGLE_FIELDS as $field) {
            $this->assertStringContainsString("Toggle::make('{$field}')", $source, "Missing settings toggle: {$field}");
        }

        foreach (self::TEXT_FIELDS as $field) {
            $this->assertStringContainsString("TextInput::make('{$field}')", $source, "Missing settings field: {$field}");
        }
    }

    public function test_support_url_is_optional_and_url_validated(): void
    {
        $source = $this->source(self::PLUGIN);

        $this->assertMatchesRegularExpression(
            "/TextInput::make\('support_url'\).*?->url\(\).*?->nullable\(\)/s",
            $source,
            'support_url must be an optional URL field'
        );
    }

    public function test_save_settings_maps_all_fields_to_env_keys(): void
    {
        $source = $this->source(self::PLUGIN);

        foreach (self::ENV_MAPPINGS as $mapping) {
            $this->assertStringContainsString(
                $mapping,
                $source,
                "saveSettings must contain: {$mapping}"
            );
        }
    }

    public function test_settings_are_grouped_into_coherent_sections(): void
    {
        $source = $this->source(self::PLUGIN);

        foreach (['settings_section_lifecycle', 'settings_section_webhooks', 'settings_section_owner_page'] as $key) {
            $this->assertStringContainsString(
                "trans('server-expiry::strings.{$key}')",
                $source,
                "Settings form must define the {$key} section"
            );
        }
    }

    private function source(string $path): string
    {
        return (string) file_get_contents($path);
    }
}
