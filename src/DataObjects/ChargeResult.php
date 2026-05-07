<?php

declare(strict_types=1);

namespace Africs\GmbPay\DataObjects;

use Africs\GmbPay\Enums\ChargeStatus;

final readonly class ChargeResult
{
    public function __construct(
        public string $reference,
        public ChargeStatus $status,
        public int $amountMinor,
        public string $currency,
        public ?string $checkoutUrl = null,
        public ?string $providerReference = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}
}
