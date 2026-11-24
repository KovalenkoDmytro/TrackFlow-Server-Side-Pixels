<?php

declare(strict_types=1);

return [
    'retention_days' => (int) env('TRACKING_RETENTION_DAYS', 90),

    // How far back to look for a click id (fbc) seen earlier for the same shop + fbp when
    // a Meta event arrives without one. Meta accepts fbc for 90 days; kept conservative.
    'fbc_lookback_days' => (int) env('TRACKING_FBC_LOOKBACK_DAYS', 7),

    // Google Ads rejects click conversions older than the conversion action's click-through
    // lookback window (EXPIRED_EVENT). Used when the real window cannot be read from the API.
    'google_ads' => [
        'default_click_window_days' => (int) env('TRACKING_GOOGLE_ADS_DEFAULT_CLICK_WINDOW_DAYS', 30),
        // Events whose age is within this many days of the window edge are not uploaded.
        'safety_margin_days' => (int) env('TRACKING_GOOGLE_ADS_SAFETY_MARGIN_DAYS', 5),
        // A conversion time later than the server clock (browser clock running ahead; Google rejects
        // future times with LATER_THAN_MAXIMUM_DATE) is sent as now minus this many seconds.
        // Past and present times are always sent unchanged.
        'future_clamp_margin_seconds' => (int) env('TRACKING_GOOGLE_ADS_FUTURE_CLAMP_MARGIN_SECONDS', 1),
    ],

    // Recipient of "Send to support" reports from issue cards. Defaults to the app author
    // (Dmytro Kovalenko, the commit author address in this repository).
    'support_email' => env('SUPPORT_EMAIL', 'dmytrokovalenko.new@gmail.com'),

    // Shown in support emails. Falls back to SENTRY_RELEASE, then the deployed git commit.
    'app_version' => env('APP_VERSION', env('SENTRY_RELEASE')),

    // Throttle for the public /api/conversions endpoint (requests per minute).
    'conversions_rate_limit' => [
        'per_ip_per_minute' => (int) env('CONVERSIONS_PER_IP_PER_MINUTE', 300),
        'per_shop_per_minute' => (int) env('CONVERSIONS_PER_SHOP_PER_MINUTE', 3000),
    ],

    'view_item_rate_limit' => [
        'per_ip_attempts' => (int) env('VIEW_ITEM_PER_IP_ATTEMPTS', 60),
        'per_ip_decay_seconds' => (int) env('VIEW_ITEM_PER_IP_DECAY_SECONDS', 300),
        'per_shop_attempts' => (int) env('VIEW_ITEM_PER_SHOP_ATTEMPTS', 300),
        'per_shop_decay_seconds' => (int) env('VIEW_ITEM_PER_SHOP_DECAY_SECONDS', 60),
    ],
];
