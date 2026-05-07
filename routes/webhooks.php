<?php

declare(strict_types=1);

use Africs\GmbPay\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => config('gmb-pay.webhook.route_prefix', 'gmb-pay/webhook'),
    'middleware' => config('gmb-pay.webhook.middleware', ['api']),
], function (): void {
    Route::post('{driver}', [WebhookController::class, 'handle'])
        ->where('driver', '[a-z0-9_-]+')
        ->name('gmb-pay.webhook');
});
