<?php

declare(strict_types=1);

namespace Africs\GmbPay\DataObjects;

final readonly class ChargeRequest
{
    public function __construct(
        public int $amountMinor,
        public string $currency,
        public string $customerPhone,
        public ?string $customerName = null,
        public ?string $customerEmail = null,
        public ?string $description = null,
        public ?string $callbackUrl = null,
        public ?string $returnUrl = null,
        public ?string $idempotencyKey = null,
        public array $metadata = [],
    ) {}
}
