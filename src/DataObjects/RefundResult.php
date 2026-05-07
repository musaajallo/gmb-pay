<?php

declare(strict_types=1);

namespace Africs\GmbPay\DataObjects;

use Africs\GmbPay\Enums\RefundStatus;

final readonly class RefundResult
{
    public function __construct(
        public string $reference,
        public RefundStatus $status,
        public int $amountMinor,
        public string $currency,
        public ?string $providerReference = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}
}
