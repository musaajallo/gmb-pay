<?php

declare(strict_types=1);

namespace Africs\GmbPay\Listeners;

use Africs\GmbPay\Enums\WebhookEventType;
use Africs\GmbPay\Events\WebhookReceived;
use Africs\GmbPay\Jobs\RetryFailedChargeJob;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\Invoice;

class RetryChargeFromWebhook
{
    public function __invoke(WebhookReceived $event): void
    {
        $dto = $event->event;

        if ($dto->type !== WebhookEventType::ChargeFailed) {
            return;
        }

        if ($dto->providerReference === null) {
            return;
        }

        $charge = Charge::where('driver', $dto->driver)
            ->where('provider_reference', $dto->providerReference)
            ->first();

        if ($charge === null) {
            return;
        }

        $invoice = Invoice::where('charge_id', $charge->id)->first();

        if ($invoice === null) {
            return;
        }

        $subscription = $invoice->subscription;

        if ($subscription === null) {
            return;
        }

        $subscription->markPastDue();

        RetryFailedChargeJob::dispatch($subscription);
    }
}
