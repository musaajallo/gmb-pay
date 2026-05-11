<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\PlanInterval;
use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Models\Plan;
use Africs\GmbPay\Models\Subscription;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;
use Illuminate\Support\Facades\Bus;

function billableForSubscribed(): FakeBillable
{
    return FakeBillable::create(['name' => 'Sub User']);
}

function makePlanForSubscribed(string $slug = 'pro-monthly'): Plan
{
    return Plan::create([
        'slug' => $slug,
        'name' => 'Pro',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Month,
    ]);
}

function attachSubscription(FakeBillable $billable, Plan $plan, SubscriptionStatus $status): Subscription
{
    return Subscription::create([
        'billable_type' => FakeBillable::class,
        'billable_id' => $billable->id,
        'plan_id' => $plan->id,
        'driver' => 'modempay',
        'status' => $status,
    ]);
}

beforeEach(function () {
    Bus::fake();
});

it('exposes all subscriptions via subscriptions() — isolated per billable', function () {
    $a = billableForSubscribed();
    $b = billableForSubscribed();
    $plan = makePlanForSubscribed();

    attachSubscription($a, $plan, SubscriptionStatus::Active);
    attachSubscription($a, $plan, SubscriptionStatus::Canceled);
    attachSubscription($b, $plan, SubscriptionStatus::Active);

    expect($a->subscriptions)->toHaveCount(2)
        ->and($b->subscriptions)->toHaveCount(1);
});

it('subscribed() returns true when at least one Active subscription exists', function () {
    $billable = billableForSubscribed();
    $plan = makePlanForSubscribed();

    attachSubscription($billable, $plan, SubscriptionStatus::Active);

    expect($billable->subscribed())->toBeTrue();
});

it('subscribed() returns false for non-Active statuses', function () {
    $billable = billableForSubscribed();
    $plan = makePlanForSubscribed();

    foreach ([SubscriptionStatus::Incomplete, SubscriptionStatus::Canceled, SubscriptionStatus::PastDue, SubscriptionStatus::Paused] as $status) {
        $b = billableForSubscribed();
        attachSubscription($b, $plan, $status);
        expect($b->subscribed())->toBeFalse();
    }
});

it('subscribed($slug) requires the active subscription to be on that plan', function () {
    $billable = billableForSubscribed();
    $pro = makePlanForSubscribed('pro');
    $basic = makePlanForSubscribed('basic');

    attachSubscription($billable, $basic, SubscriptionStatus::Active);

    expect($billable->subscribed('pro'))->toBeFalse()
        ->and($billable->subscribed('basic'))->toBeTrue();
});
