<?php

declare(strict_types=1);

namespace Africs\GmbPay\Contracts;

use Africs\GmbPay\DataObjects\TokenizedSource;

interface SupportsTokenization
{
    public function tokenizeFromCharge(string $reference): TokenizedSource;

    public function chargeToken(string $token, int $amountMinor, string $currency, array $metadata = []): \Africs\GmbPay\DataObjects\ChargeResult;
}
