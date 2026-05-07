<?php

declare(strict_types=1);

namespace Africs\GmbPay\Enums;

enum WebhookEventType: string
{
    case ChargeSucceeded = 'charge.succeeded';
    case ChargeFailed = 'charge.failed';
    case ChargeCancelled = 'charge.cancelled';
    case RefundSucceeded = 'refund.succeeded';
    case RefundFailed = 'refund.failed';
    case PayoutSucceeded = 'payout.succeeded';
    case PayoutFailed = 'payout.failed';
    case Unknown = 'unknown';
}
