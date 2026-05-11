<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Enums\InvoiceStatus;
use Africs\GmbPay\Enums\PlanInterval;
use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\Customer;
use Africs\GmbPay\Models\Invoice;
use Africs\GmbPay\Models\Plan;
use Africs\GmbPay\Models\Subscription;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;

function setupInvoicedSubscription(SubscriptionStatus $status = SubscriptionStatus::Active): array
{
    $billable = FakeBillable::create(['name' => 'Invoice Listener']);

    $plan = Plan::create([
        'slug' => 'inv-listener-'.uniqid(),
        'name' => 'Plan',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Month,
    ]);

    $customer = Customer::create([
        'billable_type' => FakeBillable::class,
        'billable_id' => $billable->id,
        'driver' => 'modempay',
    ]);

    $charge = Charge::create([
        'reference' => 'chg_inv_'.uniqid(),
        'driver' => 'modempay',
        'provider_reference' => 'pi_inv_'.uniqid(),
        'customer_id' => $customer->id,
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);

    $sub = Subscription::create([
        'billable_type' => FakeBillable::class,
        'billable_id' => $billable->id,
        'plan_id' => $plan->id,
        'driver' => 'modempay',
        'status' => $status,
        'current_period_start' => now()->subDays(15),
        'current_period_end' => now()->subDay(),
    ]);

    $invoice = Invoice::create([
        'subscription_id' => $sub->id,
        'charge_id' => $charge->id,
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => InvoiceStatus::Open,
        'period_start' => now()->subDays(15),
        'period_end' => now()->subDay(),
    ]);

    return [$sub, $invoice, $charge];
}

it('marks the Invoice Paid and leaves an Active subscription untouched', function () {
    [$sub, $invoice, $charge] = setupInvoicedSubscription(SubscriptionStatus::Active);
    $originalPeriodEnd = $sub->current_period_end->copy();

    $this->postJson('/gmb-pay/webhook/modempay', [
        'event' => 'charge.succeeded',
        'payload' => [
            'id' => 'res_'.uniqid(),
            'payment_intent_id' => $charge->provider_reference,
        ],
    ])->assertOk();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    $fresh = $sub->fresh();
    expect($fresh->status)->toBe(SubscriptionStatus::Active)
        ->and($fresh->current_period_end->equalTo($originalPeriodEnd))->toBeTrue();
});

it('recovers a PastDue subscription back to Active and advances the period', function () {
    [$sub, $invoice, $charge] = setupInvoicedSubscription(SubscriptionStatus::PastDue);

    $this->postJson('/gmb-pay/webhook/modempay', [
        'event' => 'charge.succeeded',
        'payload' => [
            'id' => 'res_'.uniqid(),
            'payment_intent_id' => $charge->provider_reference,
        ],
    ])->assertOk();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    $fresh = $sub->fresh();
    expect($fresh->status)->toBe(SubscriptionStatus::Active)
        ->and(abs($fresh->current_period_start->diffInSeconds(now())))->toBeLessThan(5)
        ->and(abs($fresh->current_period_end->diffInSeconds(now()->addMonth())))->toBeLessThan(5);
});

it('is a no-op when the charge has no linked invoice', function () {
    $billable = FakeBillable::create(['name' => 'Orphan']);

    $charge = Charge::create([
        'reference' => 'chg_orphan_listener',
        'driver' => 'modempay',
        'provider_reference' => 'pi_orphan',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);

    $this->postJson('/gmb-pay/webhook/modempay', [
        'event' => 'charge.succeeded',
        'payload' => [
            'id' => 'res_orphan',
            'payment_intent_id' => $charge->provider_reference,
        ],
    ])->assertOk();

    expect(Invoice::count())->toBe(0);
});
