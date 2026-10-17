<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SquadronStrike\ServerExpiry\Support\Url;

/**
 * Scheme policy for SERVER_EXPIRY_SUPPORT_URL.
 *
 * The value reaches the owner-facing Expiration page's `href` from `.env`,
 * bypassing the admin settings form. These tests pin the HTTP(S)-only policy
 * that both boundaries enforce: an unusable scheme must yield null ("hide the
 * support action") and must never crash the page.
 */
final class SupportUrlSchemeTest extends TestCase
{
    private const PAGE = __DIR__.'/../../src/Filament/Server/Pages/ExpirySettingsPage.php';

    private const PLUGIN = __DIR__.'/../../src/ServerExpiryPlugin.php';

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedUrls(): array
    {
        return [
            'https' => ['https://example.com/support'],
            'http' => ['http://example.com/support'],
            'https with port and path' => ['https://example.com:8443/support?a=b#c'],
            'https with credentials' => ['https://user:pass@example.com/support'],
            'https subdomain' => ['https://support.example.co.uk/tickets'],
            'uppercase scheme' => ['HTTPS://EXAMPLE.COM/support'],
        ];
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function hiddenUrls(): array
    {
        return [
            'empty string' => [''],
            'null' => [null],
            'whitespace only' => ['   '],
            'data url' => ['data:text/html,<script>alert(1)</script>'],
            'file url' => ['file:///C:/Windows/win.ini'],
            'file url unix' => ['file:///etc/passwd'],
            'javascript url' => ['javascript:alert(1)'],
            'javascript url with host' => ['javascript://example.com/%0aalert(1)'],
            'vbscript url' => ['vbscript:msgbox(1)'],
            'malformed url' => ['not a url'],
            'scheme only' => ['http://'],
            'space inside' => ['https://example.com/a b'],
            'protocol relative' => ['//evil.com/support'],
            'relative path' => ['/support-us'],
            'ftp url' => ['ftp://example.com/support'],
            'mailto url' => ['mailto:support@example.com'],
            'blob url' => ['blob:https://example.com/uuid'],
            'view-source url' => ['view-source:https://example.com'],
        ];
    }

    #[DataProvider('allowedUrls')]
    public function test_allows_http_and_https_urls(string $url): void
    {
        self::assertSame($url, Url::external($url));
    }

    #[DataProvider('hiddenUrls')]
    public function test_hides_unsafe_empty_and_malformed_urls(?string $url): void
    {
        self::assertNull(
            Url::external($url),
            'A value that is not an absolute HTTP(S) URL must not reach the owner-facing href'
        );
    }

    public function test_trims_surrounding_whitespace_from_allowed_urls(): void
    {
        self::assertSame('https://example.com/support', Url::external('  https://example.com/support  '));
    }

    /**
     * The config value bypasses the settings form, so the page must route it
     * through the scheme policy instead of reading config() directly.
     */
    public function test_owner_page_validates_the_configured_value_before_rendering(): void
    {
        $source = (string) file_get_contents(self::PAGE);

        self::assertStringContainsString(
            'Url::external(config(',
            $source,
            'ExpirySettingsPage must pass the configured support URL through Url::external()'
        );

        self::assertStringNotContainsString(
            "trim((string) config('server-expiry.support_url'",
            $source,
            'The owner page must not feed the raw config value to the view model'
        );
    }

    /**
     * Filament's url() takes no protocol list, so the form needs an explicit
     * protocol-scoped rule.
     */
    public function test_settings_form_restricts_the_url_rule_to_http_and_https(): void
    {
        $source = (string) file_get_contents(self::PLUGIN);

        self::assertStringContainsString("->rule('url:http,https')", $source);
    }
}
