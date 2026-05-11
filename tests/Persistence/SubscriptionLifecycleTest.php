<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\PlanInterval;
use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Models\Plan;
use Africs\GmbPay\Models\Subscription;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;
use Carbon\Carbon;

function makeLifecycleSub(SubscriptionStatus $status = SubscriptionStatus::Active, ?Carbon $trialEndsAt = null): Subscription
{
    $billable = FakeBillable::create(['name' => 'Lifecycle']);

    $plan = Plan::create([
        'slug' => 'lifecycle-'.uniqid(),
        'name' => 'Lifecycle Plan',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Month,
    ]);

    return Subscription::create([
        'billable_type' => FakeBillable::class,
        'billable_id' => $billable->id,
        'plan_id' => $plan->id,
        'driver' => 'modempay',
        'status' => $status,
        'trial_ends_at' => $trialEndsAt,
    ]);
}

it('cancel() sets Canceled + canceled_at and clears cancel_at_period_end', function () {
    $sub = makeLifecycleSub();
    $sub->update(['cancel_at_period_end' => true]);

    $sub->cancel();

    $fresh = $sub->fresh();
    expect($fresh->status)->toBe(SubscriptionStatus::Canceled)
        ->and($fresh->canceled_at)->not->toBeNull()
        ->and($fresh->cancel_at_period_end)->toBeFalse();
});

it('cancelAtPeriodEnd() flips the flag without touching status or canceled_at', function () {
    $sub = makeLifecycleSub(SubscriptionStatus::Active);

    $sub->cancelAtPeriodEnd();

    $fresh = $sub->fresh();
    expect($fresh->cancel_at_period_end)->toBeTrue()
        ->and($fresh->status)->toBe(SubscriptionStatus::Active)
        ->and($fresh->canceled_at)->toBeNull();
});

it('resume() reactivates a Canceled sub and clears canceled_at', function () {
    $sub = makeLifecycleSub(SubscriptionStatus::Canceled);
    $sub->update(['canceled_at' => now()]);

    $sub->resume();

    $fresh = $sub->fresh();
    expect($fresh->status)->toBe(SubscriptionStatus::Active)
        ->and($fresh->canceled_at)->toBeNull()
        ->and($fresh->cancel_at_period_end)->toBeFalse();
});

it('resume() also clears cancel_at_period_end when status is Active', function () {
    $sub = makeLifecycleSub(SubscriptionStatus::Active);
    $sub->update(['cancel_at_period_end' => true]);

    $sub->resume();

    $fresh = $sub->fresh();
    expect($fresh->cancel_at_period_end)->toBeFalse()
        ->and($fresh->status)->toBe(SubscriptionStatus::Active);
});

it('onTrial() reflects trial_ends_at vs now', function () {
    $future = makeLifecycleSub(trialEndsAt: now()->addDays(7));
    $past = makeLifecycleSub(trialEndsAt: now()->subDays(1));
    $none = makeLifecycleSub();

    expect($future->onTrial())->toBeTrue()
        ->and($past->onTrial())->toBeFalse()
        ->and($none->onTrial())->toBeFalse();
});

it('active() and pastDue() reflect the status enum', function () {
    expect(makeLifecycleSub(SubscriptionStatus::Active)->active())->toBeTrue()
        ->and(makeLifecycleSub(SubscriptionStatus::Canceled)->active())->toBeFalse()
        ->and(makeLifecycleSub(SubscriptionStatus::PastDue)->pastDue())->toBeTrue()
        ->and(makeLifecycleSub(SubscriptionStatus::Active)->pastDue())->toBeFalse();
});

it('markPastDue() and markCanceled() change status as documented', function () {
    $sub = makeLifecycleSub(SubscriptionStatus::Active);
    $sub->markPastDue();
    expect($sub->fresh()->status)->toBe(SubscriptionStatus::PastDue);

    $sub2 = makeLifecycleSub(SubscriptionStatus::Active);
    $sub2->markCanceled();
    $fresh = $sub2->fresh();
    expect($fresh->status)->toBe(SubscriptionStatus::Canceled)
        ->and($fresh->canceled_at)->not->toBeNull();
});
