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
];
