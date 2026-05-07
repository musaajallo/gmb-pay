<?php

declare(strict_types=1);

namespace Africs\GmbPay\DataObjects;

use Africs\GmbPay\Enums\PayoutStatus;

final readonly class PayoutResult
{
    public function __construct(
        public string $reference,
        public PayoutStatus $status,
        public int $amountMinor,
        public string $currency,
        public ?string $providerReference = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}
}
