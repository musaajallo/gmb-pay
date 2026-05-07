<?php

declare(strict_types=1);

namespace Africs\GmbPay\DataObjects;

final readonly class TokenizedSource
{
    public function __construct(
        public string $token,
        public string $driver,
        public ?string $maskedIdentifier = null,
        public ?string $expiresAt = null,
        public array $raw = [],
    ) {}
}
