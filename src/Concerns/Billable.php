<?php

declare(strict_types=1);

namespace Africs\GmbPay\Concerns;

use Africs\GmbPay\DataObjects\ChargeRequest;
use Africs\GmbPay\DataObjects\ChargeResult;
use Africs\GmbPay\DataObjects\RefundRequest;
use Africs\GmbPay\DataObjects\RefundResult;
use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Exceptions\GmbPayException;
use Africs\GmbPay\Jobs\InitiateRecurringChargeJob;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\Customer;
use Africs\GmbPay\Models\Plan;
use Africs\GmbPay\Models\Refund;
use Africs\GmbPay\Models\Subscription;
use Africs\GmbPay\PaymentManager;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Billable
{
    public function gmbPayCustomers(): MorphMany
    {
        return $this->morphMany(Customer::class, 'billable');
    }

    public function gmbPayCharges(): HasManyThrough
    {
        return $this->hasManyThrough(
            Charge::class,
            Customer::class,
            'billable_id',
            'customer_id',
            $this->getKeyName(),
            'id',
        )->where('gmb_pay_customers.billable_type', $this->getMorphClass());
    }

    public function createGmbPayCustomer(?string $driver = null, array $opts = []): Customer
    {
        $driver = $driver ?? (string) config('gmb-pay.default', 'modempay');

        return Customer::firstOrCreate(
            [
                'billable_type' => $this->getMorphClass(),
                'billable_id' => $this->getKey(),
                'driver' => $driver,
            ],
            [
                'metadata' => $opts['metadata'] ?? [],
            ],
        );
    }

    public function charge(int $amountMinor, string $currency = 'GMD', array $opts = []): ChargeResult
    {
        $driverName = isset($opts['driver']) && is_string($opts['driver']) && $opts['driver'] !== ''
            ? $opts['driver']
            : (string) config('gmb-pay.default', 'modempay');

        $customer = $this->createGmbPayCustomer($driverName);

        $request = new ChargeRequest(
            amountMinor: $amountMinor,
            currency: $currency,
            customerPhone: (string) ($opts['customerPhone'] ?? ''),
            customerName: $opts['customerName'] ?? null,
            customerEmail: $opts['customerEmail'] ?? null,
            description: $opts['description'] ?? null,
            callbackUrl: $opts['callbackUrl'] ?? null,
            returnUrl: $opts['returnUrl'] ?? null,
            idempotencyKey: $opts['idempotencyKey'] ?? null,
            metadata: (array) ($opts['metadata'] ?? []),
        );

        $result = app(PaymentManager::class)->charge($request, $driverName);

        $charge = Charge::firstOrNew(['reference' => $result->reference]);
        $charge->fill([
            'driver' => $driverName,
            'customer_id' => $customer->id,
            'provider_reference' => $result->providerReference,
            'amount_minor' => $result->amountMinor,
            'currency' => $result->currency,
            'status' => $result->status,
        ]);

        if (! $charge->exists) {
            $charge->metadata = array_merge($request->metadata, [
                '_gmbpay_checkout_url' => $result->checkoutUrl,
                '_gmbpay_failure_reason' => $result->failureReason,
                '_gmbpay_raw' => $result->raw,
            ]);
        }

        $charge->save();

        return $result;
    }

    public function findChargeByReference(string $reference): ?Charge
    {
        return $this->gmbPayCharges()
            ->where('gmb_pay_charges.reference', $reference)
            ->first();
    }

    public function subscribeToPlan(Plan|string $plan, array $opts = []): Subscription
    {
        $plan = $plan instanceof Plan ? $plan : Plan::where('slug', $plan)->firstOrFail();

        $driverName = isset($opts['driver']) && is_string($opts['driver']) && $opts['driver'] !== ''
            ? $opts['driver']
            : (string) config('gmb-pay.default', 'modempay');

        $subscription = Subscription::create([
            'billable_type' => $this->getMorphClass(),
            'billable_id' => $this->getKey(),
            'plan_id' => $plan->id,
            'driver' => $driverName,
            'status' => SubscriptionStatus::Incomplete,
            'trial_ends_at' => $plan->trial_days > 0 ? now()->addDays($plan->trial_days) : null,
        ]);

        $subscription->items()->create([
            'unit_amount_minor' => $plan->amount_minor,
        ]);

        InitiateRecurringChargeJob::dispatch($subscription);

        return $subscription->fresh(['items']);
    }

    public function refund(string $reference, ?int $amountMinor = null): RefundResult
    {
        $charge = $this->findChargeByReference($reference);

        if ($charge === null) {
            throw new GmbPayException(
                "No Charge with reference [{$reference}] found for this Billable."
            );
        }

        $driver = app(PaymentManager::class)->driver((string) $charge->driver);

        $result = $driver->refund(new RefundRequest(
            chargeReference: $reference,
            amountMinor: $amountMinor,
        ));

        Refund::create([
            'charge_id' => $charge->id,
            'reference' => $result->reference,
            'provider_reference' => $result->providerReference,
            'amount_minor' => $result->amountMinor,
            'status' => $result->status,
        ]);

        return $result;
    }
}
