<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\PlanInterval;
use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Jobs\InitiateRecurringChargeJob;
use Africs\GmbPay\Models\Plan;
use Africs\GmbPay\Models\Subscription;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

function makeCycleSub(SubscriptionStatus $status, \Carbon\Carbon $periodEnd): Subscription
{
    $billable = FakeBillable::create(['name' => 'Cycle ' . $status->value]);

    $plan = Plan::create([
        'slug' => 'cycle-' . uniqid(),
        'name' => 'Cycle Plan',
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
        'current_period_end' => $periodEnd,
    ]);
}

beforeEach(function () {
    Bus::fake();
});

it('dispatches InitiateRecurringChargeJob for each Active subscription that is due', function () {
    $sub = makeCycleSub(SubscriptionStatus::Active, now()->subHour());

    $exit = Artisan::call('gmb-pay:cycle');

    expect($exit)->toBe(0);

    Bus::assertDispatched(InitiateRecurringChargeJob::class, function ($job) use ($sub) {
        return $job->subscription->is($sub);
    });
    Bus::assertDispatchedTimes(InitiateRecurringChargeJob::class, 1);
});

it('does not dispatch when the period_end is in the future', function () {
    makeCycleSub(SubscriptionStatus::Active, now()->addDay());

    Artisan::call('gmb-pay:cycle');

    Bus::assertNotDispatched(InitiateRecurringChargeJob::class);
});

it('skips subscriptions whose status is not Active even if their period is due', function () {
    foreach ([SubscriptionStatus::Incomplete, SubscriptionStatus::Canceled, SubscriptionStatus::PastDue, SubscriptionStatus::Paused] as $status) {
        makeCycleSub($status, now()->subDay());
    }

    Artisan::call('gmb-pay:cycle');

    Bus::assertNotDispatched(InitiateRecurringChargeJob::class);
});

it('exits with status 0 even when no subscriptions are processed', function () {
    expect(Artisan::call('gmb-pay:cycle'))->toBe(0);
});
