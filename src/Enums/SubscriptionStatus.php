<?php

declare(strict_types=1);

namespace Africs\GmbPay\Enums;

enum SubscriptionStatus: string
{
    case Incomplete = 'incomplete';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Paused = 'paused';
}
