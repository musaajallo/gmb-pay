<?php

declare(strict_types=1);

namespace Africs\GmbPay\Enums;

enum PlanInterval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
