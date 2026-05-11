<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\InvoiceStatus;
use Africs\GmbPay\Enums\PlanInterval;
use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Jobs\InitiateRecurringChargeJob;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\Invoice;
use Africs\GmbPay\Models\Plan;
use Africs\GmbPay\Models\Subscription;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;

function makeJobSubscription(array $planOverrides = [], ?\Carbon\Carbon $trialEndsAt = null): Subscription
{
    $billable = FakeBillable::create(['name' => 'Job User']);

    $plan = Plan::create(array_merge([
        'slug' => 'job-plan-' . uniqid(),
        'name' => 'Job Plan',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Month,
        'interval_count' => 1,
    ], $planOverrides));

    return Subscription::create([
        'billable_type' => FakeBillable::class,
        'billable_id' => $billable->id,
        'plan_id' => $plan->id,
        'driver' => 'modempay',
        'status' => SubscriptionStatus::Incomplete,
        'trial_ends_at' => $trialEndsAt,
    ]);
}

beforeEach(function () {
    config(['gmb-pay.demo_mode' => true, 'gmb-pay.default' => 'modempay']);
});

it('trial path: advances Active + sets period to trial_ends_at, no Charge or Invoice', function () {
    $trialEnd = now()->addDays(14)->startOfSecond();
    $sub = makeJobSubscription(trialEndsAt: $trialEnd);
    $sub->items()->create(['unit_amount_minor' => 5000]);

    (new InitiateRecurringChargeJob($sub))->handle();

    $fresh = $sub->fresh();
    expect($fresh->status)->toBe(SubscriptionStatus::Active)
        ->and(abs($fresh->current_period_start->diffInSeconds(now())))->toBeLessThan(5)
        ->and($fresh->current_period_end->equalTo($trialEnd))->toBeTrue();

    expect(Charge::count())->toBe(0)
        ->and(Invoice::count())->toBe(0);
});

it('live path: charges, persists Charge + Invoice, advances period by plan interval', function () {
    $sub = makeJobSubscription();
    $sub->items()->create(['unit_amount_minor' => 5000]);

    (new InitiateRecurringChargeJob($sub))->handle();

    $fresh = $sub->fresh(['plan']);
    expect($fresh->status)->toBe(SubscriptionStatus::Active)
        ->and(abs($fresh->current_period_start->diffInSeconds(now())))->toBeLessThan(5)
        ->and(abs($fresh->current_period_end->diffInSeconds(now()->addMonth())))->toBeLessThan(5);

    $charge = Charge::firstOrFail();
    expect($charge->amount_minor)->toBe(5000)
        ->and($charge->currency)->toBe('GMD')
        ->and($charge->driver)->toBe('modempay')
        ->and($charge->customer_id)->not->toBeNull();

    $invoice = Invoice::firstOrFail();
    expect($invoice->subscription_id)->toBe($sub->id)
        ->and($invoice->charge_id)->toBe($charge->id)
        ->and($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->amount_minor)->toBe(5000);
});

it('live path: totals multiple items including quantity > 1', function () {
    $sub = makeJobSubscription();
    $sub->items()->create(['unit_amount_minor' => 3000, 'quantity' => 1]);
    $sub->items()->create(['unit_amount_minor' => 2000, 'quantity' => 2]);

    (new InitiateRecurringChargeJob($sub))->handle();

    expect(Charge::firstOrFail()->amount_minor)->toBe(7000)
        ->and(Invoice::firstOrFail()->amount_minor)->toBe(7000);
});
