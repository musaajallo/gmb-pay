<?php

declare(strict_types=1);

namespace Africs\GmbPay\Contracts;

interface SupportsRecurring
{
    public function recurringMode(): string;
}
