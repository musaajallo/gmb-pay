<?php

declare(strict_types=1);

namespace Africs\GmbPay;

use Africs\GmbPay\Console\InstallCommand;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

class GmbPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/gmb-pay.php', 'gmb-pay');

        $this->app->singleton(PaymentManager::class, function (Container $app): PaymentManager {
            return new PaymentManager($app);
        });

        $this->app->alias(PaymentManager::class, 'gmb-pay');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/webhooks.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'gmb-pay');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/gmb-pay.php' => config_path('gmb-pay.php'),
            ], 'gmb-pay-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'gmb-pay-migrations');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/gmb-pay'),
            ], 'gmb-pay-views');

            $this->commands([
                InstallCommand::class,
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
