<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Server Expiry & Auto-Suspend Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration settings for the Pelican Panel Server Expiry plugin.
    |
    */

    'auto_suspend_enabled' => env('SERVER_EXPIRY_AUTO_SUSPEND', true),

    'warning_days_notice' => array_map('intval', explode(',', (string) env('SERVER_EXPIRY_WARNING_DAYS', '7,3,1'))), // Days before expiry to send warning notification

    'grace_period_hours' => env('SERVER_EXPIRY_GRACE_HOURS', 0),

    'notify_owner_on_suspend' => env('SERVER_EXPIRY_NOTIFY_ON_SUSPEND', true),

    // Webhook delivery tuning: attempts per delivery, HTTP timeout per
    // request, and the base delay for the exponential retry backoff.
    'webhook_max_attempts' => env('SERVER_EXPIRY_WEBHOOK_MAX_ATTEMPTS', 3),

    'webhook_timeout_seconds' => env('SERVER_EXPIRY_WEBHOOK_TIMEOUT', 10),

    'webhook_backoff_base_seconds' => env('SERVER_EXPIRY_WEBHOOK_BACKOFF_BASE', 1),

    // Support / renewal contact target shown to server owners on the
    // Expiration page. Empty = the Contact Support action is hidden.
    'support_url' => env('SERVER_EXPIRY_SUPPORT_URL', ''),
];
