<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Enums\InvoiceStatus;
use Africs\GmbPay\Enums\PlanInterval;
use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Jobs\RetryFailedChargeJob;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\Invoice;
use Africs\GmbPay\Models\Plan;
use Africs\GmbPay\Models\Subscription;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;
use Illuminate\Support\Facades\Bus;

function setupFailingSubscription(): array
{
    $billable = FakeBillable::create(['name' => 'Failing']);

    $plan = Plan::create([
        'slug' => 'fail-'.uniqid(),
        'name' => 'Fail Plan',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Month,
    ]);

    $charge = Charge::create([
        'reference' => 'chg_fail_'.uniqid(),
        'driver' => 'modempay',
        'provider_reference' => 'pi_fail_'.uniqid(),
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);

    $sub = Subscription::create([
        'billable_type' => FakeBillable::class,
        'billable_id' => $billable->id,
        'plan_id' => $plan->id,
        'driver' => 'modempay',
        'status' => SubscriptionStatus::Active,
        'current_period_end' => now()->addMonth(),
    ]);

    $invoice = Invoice::create([
        'subscription_id' => $sub->id,
        'charge_id' => $charge->id,
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => InvoiceStatus::Open,
        'period_start' => now(),
        'period_end' => now()->addMonth(),
    ]);

    return [$sub, $invoice, $charge];
}

beforeEach(function () {
    Bus::fake();
});

it('marks subscription PastDue and dispatches RetryFailedChargeJob on charge.failed', function () {
    [$sub, , $charge] = setupFailingSubscription();

    $this->postJson('/gmb-pay/webhook/modempay', [
        'event' => 'charge.expired',
        'payload' => [
            'id' => 'res_'.uniqid(),
            'payment_intent_id' => $charge->provider_reference,
        ],
    ])->assertOk();

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::PastDue);

    Bus::assertDispatched(RetryFailedChargeJob::class, function ($job) use ($sub) {
        return $job->subscription->is($sub);
    });
    Bus::assertDispatchedTimes(RetryFailedChargeJob::class, 1);
});

it('is a no-op for an orphan Charge with no Invoice', function () {
    $billable = FakeBillable::create(['name' => 'Orphan']);

    $charge = Charge::create([
        'reference' => 'chg_orphan_fail',
        'driver' => 'modempay',
        'provider_reference' => 'pi_orphan_fail',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);

    $this->postJson('/gmb-pay/webhook/modempay', [
        'event' => 'charge.expired',
        'payload' => [
            'id' => 'res_orphan_fail',
            'payment_intent_id' => $charge->provider_reference,
        ],
    ])->assertOk();

    Bus::assertNotDispatched(RetryFailedChargeJob::class);
});

it('is a no-op when the Invoice has no Subscription (defensive)', function () {
    $billable = FakeBillable::create(['name' => 'Subless']);

    $plan = Plan::create([
        'slug' => 'subless-'.uniqid(),
        'name' => 'Subless',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Month,
    ]);

    $sub = Subscription::create([
        'billable_type' => FakeBillable::class,
        'billable_id' => $billable->id,
        'plan_id' => $plan->id,
        'driver' => 'modempay',
        'status' => SubscriptionStatus::Active,
    ]);

    $charge = Charge::create([
        'reference' => 'chg_subless',
        'driver' => 'modempay',
        'provider_reference' => 'pi_subless',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);

    Invoice::create([
        'subscription_id' => $sub->id,
        'charge_id' => $charge->id,
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => InvoiceStatus::Open,
        'period_start' => now(),
        'period_end' => now()->addMonth(),
    ]);

    $sub->delete();

    $this->postJson('/gmb-pay/webhook/modempay', [
        'event' => 'charge.expired',
        'payload' => [
            'id' => 'res_subless',
            'payment_intent_id' => $charge->provider_reference,
        ],
    ])->assertOk();

    Bus::assertNotDispatched(RetryFailedChargeJob::class);
});
