<?php

declare(strict_types=1);

namespace Africs\GmbPay\Drivers\Waychit;

use Africs\GmbPay\Drivers\AbstractDriver;

class WaychitDriver extends AbstractDriver
{
    public function name(): string
    {
        return 'waychit';
    }
}
