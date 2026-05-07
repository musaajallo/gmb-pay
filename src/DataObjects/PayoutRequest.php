<?php

declare(strict_types=1);

namespace Africs\GmbPay\DataObjects;

final readonly class PayoutRequest
{
    public function __construct(
        public int $amountMinor,
        public string $currency,
        public string $recipientPhone,
        public ?string $recipientName = null,
        public ?string $description = null,
        public ?string $idempotencyKey = null,
        public array $metadata = [],
    ) {}
}
