<?php

declare(strict_types=1);

namespace Africs\GmbPay\DataObjects;

use Africs\GmbPay\Enums\WebhookEventType;

final readonly class WebhookEvent
{
    public function __construct(
        public WebhookEventType $type,
        public string $driver,
        public ?string $reference = null,
        public ?string $providerReference = null,
        public array $payload = [],
        public ?string $providerEventId = null,
    ) {}
}
