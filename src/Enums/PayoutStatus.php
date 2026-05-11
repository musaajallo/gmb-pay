<?php

declare(strict_types=1);

namespace Africs\GmbPay\Enums;

enum PayoutStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
