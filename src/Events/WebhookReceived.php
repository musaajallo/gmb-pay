<?php

declare(strict_types=1);

namespace Africs\GmbPay\Events;

use Africs\GmbPay\DataObjects\WebhookEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebhookReceived
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly WebhookEvent $event,
    ) {}
}
