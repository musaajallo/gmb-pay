<?php

declare(strict_types=1);

namespace Africs\GmbPay\Jobs;

use Africs\GmbPay\DataObjects\ChargeRequest;
use Africs\GmbPay\Enums\InvoiceStatus;
use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\Invoice;
use Africs\GmbPay\Models\Subscription;
use Africs\GmbPay\PaymentManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InitiateRecurringChargeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public Subscription $subscription) {}

    public function handle(): void
    {
        $sub = $this->subscription->fresh(['plan', 'items', 'billable']);

        if ($sub === null) {
            return;
        }

        $start = now();

        if ($sub->onTrial()) {
            $sub->forceFill([
                'status' => SubscriptionStatus::Active,
                'current_period_start' => $start,
                'current_period_end' => $sub->trial_ends_at,
            ])->save();

            return;
        }

        $amount = (int) $sub->items->sum(fn ($item) => $item->quantity * $item->unit_amount_minor);
        $end = $sub->plan->nextPeriodEnd($start);

        $driver = app(PaymentManager::class)->driver($sub->driver);

        $result = $driver->charge(new ChargeRequest(
            amountMinor: $amount,
            currency: $sub->plan->currency,
            customerPhone: '',
        ));

        $customer = $sub->billable->createGmbPayCustomer($sub->driver);

        $charge = Charge::create([
            'reference' => $result->reference,
            'driver' => $sub->driver,
            'customer_id' => $customer->id,
            'provider_reference' => $result->providerReference,
            'amount_minor' => $result->amountMinor,
            'currency' => $result->currency,
            'status' => $result->status,
            'metadata' => [
                '_gmbpay_checkout_url' => $result->checkoutUrl,
                '_gmbpay_failure_reason' => $result->failureReason,
                '_gmbpay_raw' => $result->raw,
                '_gmbpay_subscription_id' => $sub->id,
            ],
        ]);

        Invoice::create([
            'subscription_id' => $sub->id,
            'charge_id' => $charge->id,
            'amount_minor' => $amount,
            'currency' => $sub->plan->currency,
            'status' => InvoiceStatus::Open,
            'period_start' => $start,
            'period_end' => $end,
        ]);

        $sub->forceFill([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $start,
            'current_period_end' => $end,
        ])->save();
    }

}
