<?php

declare(strict_types=1);

namespace Africs\GmbPay\Tests;

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
            'GmbPay' => \Africs\GmbPay\Facades\GmbPay::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('gmb-pay.demo_mode', true);
        $app['config']->set('gmb-pay.default', 'modempay');
    }
}
