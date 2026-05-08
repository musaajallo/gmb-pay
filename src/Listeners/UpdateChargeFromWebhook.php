<?php

declare(strict_types=1);

namespace Africs\GmbPay\Listeners;

use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Enums\WebhookEventType;
use Africs\GmbPay\Events\WebhookReceived;
use Africs\GmbPay\Models\Charge;

class UpdateChargeFromWebhook
{
    public function __invoke(WebhookReceived $event): void
    {
        $dto = $event->event;

        $status = match ($dto->type) {
            WebhookEventType::ChargeSucceeded => ChargeStatus::Succeeded,
            WebhookEventType::ChargeFailed => ChargeStatus::Failed,
            WebhookEventType::ChargeCancelled => ChargeStatus::Cancelled,
            default => null,
        };

        if ($status === null || $dto->providerReference === null) {
            return;
        }

        Charge::where('driver', $dto->driver)
            ->where('provider_reference', $dto->providerReference)
            ->first()
            ?->update(['status' => $status]);
    }
}
