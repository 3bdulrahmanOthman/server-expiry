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

    /**
     * Pelican rc.3 convention (official plugins, pelican/plugins@c2c6547):
     * settings form fields declare no ->default(). The panel fills the modal
     * from getSettingsFormData(), so a default is redundant, and a config()
     * closure there would show the file value instead of the saved one when
     * they diverge. Every field key must still be present in the form data.
     */
    public function test_settings_form_declares_no_field_defaults(): void
    {
        $source = $this->source(self::PLUGIN);

        $form = $this->extractMethodBody($source, 'getSettingsForm');

        $this->assertStringNotContainsString(
            '->default(',
            $form,
            'Settings form fields must not declare ->default() (Pelican rc.3 convention; values come from getSettingsFormData)'
        );
    }

    public function test_form_data_covers_every_settings_field(): void
    {
        $source = $this->source(self::PLUGIN);

        $data = $this->extractMethodBody($source, 'getSettingsFormData');

        $this->assertStringContainsString("config('server-expiry')", $data, 'getSettingsFormData must seed from the plugin config');
        $this->assertStringContainsString("implode(',', (array) \$data['warning_days_notice'])", $data, 'warning_days_notice must be flattened for the text field');
    }

    private function extractMethodBody(string $source, string $method): string
    {
        $start = strpos($source, "function {$method}()");
        $this->assertNotFalse($start, "Method {$method} not found");

        $next = strpos($source, '\n    public function', $start);

        return substr($source, $start, $next === false ? null : $next - $start);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents($path);
    }
}
