<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Support;

/**
 * Scheme policy for configuration-supplied URLs.
 *
 * `SERVER_EXPIRY_SUPPORT_URL` reaches the owner-facing Expiration page and is
 * rendered into an `href`. The value comes from `.env`/config, not from a
 * validated Filament form, so it cannot rely on the settings form's rules:
 * Laravel's `url` rule alone accepts a large protocol allowlist (`data:`,
 * `file:`, `blob:`, `view-source:`, `mailto:`, `ftp:`, ...), any of which is
 * unsuitable for a support link on a fresh install.
 *
 * Kept free of framework and domain dependencies so it can be unit tested by
 * the plugin's standalone suite, which has no Laravel application.
 */
final class Url
{
    /**
     * Return the URL only when it is an absolute HTTP(S) URL; null otherwise.
     *
     * Returning null is the documented "hide the support action" signal, so an
     * unusable scheme disables the link instead of rendering it.
     */
    public static function external(?string $url): ?string
    {
        $candidate = trim((string) $url);

        if ($candidate === '') {
            return null;
        }

        // The scheme check is what excludes `file:` and `mailto:`, which
        // FILTER_VALIDATE_URL accepts. The filter is what excludes malformed
        // input that parses as HTTP(S) but has no valid host, e.g. `http://`.
        if (! in_array(strtolower((string) parse_url($candidate, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return null;
        }

        return filter_var($candidate, FILTER_VALIDATE_URL) === false ? null : $candidate;
    }
}
