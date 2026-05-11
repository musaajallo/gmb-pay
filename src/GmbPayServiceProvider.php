<?php

declare(strict_types=1);

namespace Africs\GmbPay;

use Africs\GmbPay\Console\CycleCommand;
use Africs\GmbPay\Console\InstallCommand;
use Africs\GmbPay\Events\WebhookReceived;
use Africs\GmbPay\Idempotency\IdempotencyStore;
use Africs\GmbPay\Listeners\MarkInvoicePaidFromWebhook;
use Africs\GmbPay\Listeners\RetryChargeFromWebhook;
use Africs\GmbPay\Listeners\UpdateChargeFromWebhook;
use Africs\GmbPay\Listeners\UpdateRefundFromWebhook;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class GmbPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/gmb-pay.php', 'gmb-pay');

        $this->app->singleton(PaymentManager::class, function (Container $app): PaymentManager {
            return new PaymentManager($app, $app->make(IdempotencyStore::class));
        });

        $this->app->alias(PaymentManager::class, 'gmb-pay');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'gmb-pay');

        if ($this->app['config']->get('gmb-pay.events.auto_register', true)) {
            Event::listen(WebhookReceived::class, UpdateChargeFromWebhook::class);
            Event::listen(WebhookReceived::class, UpdateRefundFromWebhook::class);
            Event::listen(WebhookReceived::class, MarkInvoicePaidFromWebhook::class);
            Event::listen(WebhookReceived::class, RetryChargeFromWebhook::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/gmb-pay.php' => config_path('gmb-pay.php'),
            ], 'gmb-pay-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'gmb-pay-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/gmb-pay'),
            ], 'gmb-pay-views');

            $this->commands([
                InstallCommand::class,
                CycleCommand::class,
            ]);
        }
    }

    public function provides(): array
    {
        return [
            PaymentManager::class,
            'gmb-pay',
        ];
    }
}
