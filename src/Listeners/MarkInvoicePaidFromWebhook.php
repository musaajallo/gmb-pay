<?php

declare(strict_types=1);

namespace Africs\GmbPay\Listeners;

use Africs\GmbPay\Enums\InvoiceStatus;
use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Enums\WebhookEventType;
use Africs\GmbPay\Events\WebhookReceived;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\Invoice;

class MarkInvoicePaidFromWebhook
{
    public function __invoke(WebhookReceived $event): void
    {
        $dto = $event->event;

        if ($dto->type !== WebhookEventType::ChargeSucceeded) {
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

        $invoice->update(['status' => InvoiceStatus::Paid]);

        $subscription = $invoice->subscription;

        if ($subscription !== null && $subscription->status === SubscriptionStatus::PastDue) {
            $now = now();
            $subscription->forceFill([
                'status' => SubscriptionStatus::Active,
                'current_period_start' => $now,
                'current_period_end' => $subscription->plan->nextPeriodEnd($now),
            ])->save();
        }
    }
}
