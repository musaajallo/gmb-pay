<?php

declare(strict_types=1);

namespace Africs\GmbPay\Drivers\Modempay;

use Africs\GmbPay\Drivers\AbstractDriver;

class ModempayDriver extends AbstractDriver
{
    public function name(): string
    {
        return 'modempay';
    }
}
