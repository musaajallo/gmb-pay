<?php

declare(strict_types=1);

namespace Africs\GmbPay\DataObjects;

final readonly class RefundRequest
{
    public function __construct(
        public string $chargeReference,
        public ?int $amountMinor = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
        public array $metadata = [],
    ) {}
}
