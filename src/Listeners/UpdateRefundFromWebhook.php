<?php

declare(strict_types=1);

namespace Africs\GmbPay\Listeners;

use Africs\GmbPay\Enums\RefundStatus;
use Africs\GmbPay\Enums\WebhookEventType;
use Africs\GmbPay\Events\WebhookReceived;
use Africs\GmbPay\Models\Refund;

class UpdateRefundFromWebhook
{
    public function __invoke(WebhookReceived $event): void
    {
        $dto = $event->event;

        $status = match ($dto->type) {
            WebhookEventType::RefundSucceeded => RefundStatus::Succeeded,
            WebhookEventType::RefundFailed => RefundStatus::Failed,
            default => null,
        };

        if ($status === null || $dto->providerReference === null) {
            return;
        }

        Refund::whereHas('charge', fn ($q) => $q->where('driver', $dto->driver))
            ->where('provider_reference', $dto->providerReference)
            ->first()
            ?->update(['status' => $status]);
    }
}
