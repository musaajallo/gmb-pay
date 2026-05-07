<?php

declare(strict_types=1);

return [
    'default' => env('GMB_PAY_DEFAULT', 'modempay'),

    'currency' => env('GMB_PAY_CURRENCY', 'GMD'),

    'demo_mode' => env('GMB_PAY_DEMO', false),

    'webhook' => [
        'route_prefix' => env('GMB_PAY_WEBHOOK_PREFIX', 'gmb-pay/webhook'),
        'middleware' => ['api'],
        'tolerance_seconds' => 300,
    ],

    'drivers' => [
        'modempay' => [
            'public_key' => env('MODEMPAY_PUBLIC_KEY'),
            'secret_key' => env('MODEMPAY_SECRET_KEY'),
            'webhook_secret' => env('MODEMPAY_WEBHOOK_SECRET'),
            'base_url' => env('MODEMPAY_BASE_URL', 'https://api.modempay.com'),
            'test_mode' => env('MODEMPAY_TEST_MODE', true),
        ],

        'wave' => [
            'api_key' => env('WAVE_API_KEY'),
            'webhook_secret' => env('WAVE_WEBHOOK_SECRET'),
            'base_url' => env('WAVE_BASE_URL', 'https://api.wave.com'),
            'test_mode' => env('WAVE_TEST_MODE', true),
        ],

        'waychit' => [
            'api_key' => env('WAYCHIT_API_KEY'),
            'webhook_secret' => env('WAYCHIT_WEBHOOK_SECRET'),
            'base_url' => env('WAYCHIT_BASE_URL'),
            'test_mode' => env('WAYCHIT_TEST_MODE', true),
        ],
    ],

    'subscriptions' => [
        'enabled' => true,
        'retry_attempts' => 3,
        'retry_backoff_minutes' => [60, 360, 1440],
        'grace_days' => 3,
    ],
];
