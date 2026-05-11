<?php

declare(strict_types=1);

namespace Africs\GmbPay\Tests;

use Africs\GmbPay\Facades\GmbPay;
use Africs\GmbPay\GmbPayServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            GmbPayServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'GmbPay' => GmbPay::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('gmb-pay.demo_mode', true);
        $app['config']->set('gmb-pay.default', 'modempay');
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
