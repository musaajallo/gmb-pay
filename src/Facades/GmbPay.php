<?php

declare(strict_types=1);

namespace Africs\GmbPay\Facades;

use Africs\GmbPay\Contracts\PaymentDriver;
use Africs\GmbPay\PaymentManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PaymentDriver driver(?string $driver = null)
 * @method static \Africs\GmbPay\DataObjects\ChargeResult charge(\Africs\GmbPay\DataObjects\ChargeRequest $request)
 * @method static \Africs\GmbPay\DataObjects\ChargeResult verify(string $reference)
 * @method static \Africs\GmbPay\DataObjects\RefundResult refund(\Africs\GmbPay\DataObjects\RefundRequest $request)
 * @method static \Africs\GmbPay\DataObjects\PayoutResult payout(\Africs\GmbPay\DataObjects\PayoutRequest $request)
 *
 * @see PaymentManager
 */
class GmbPay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PaymentManager::class;
    }
}
