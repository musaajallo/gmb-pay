<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\PlanInterval;
use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Jobs\InitiateRecurringChargeJob;
use Africs\GmbPay\Models\Plan;
use Africs\GmbPay\Models\Subscription;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

function makeGraceSub(SubscriptionStatus $status, Carbon $updatedAt, ?Carbon $periodEnd = null): Subscription
{
    $billable = FakeBillable::create(['name' => 'Grace '.$status->value]);

    $plan = Plan::create([
        'slug' => 'grace-'.uniqid(),
        'name' => 'Grace Plan',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Month,
    ]);

    $sub = Subscription::create([
        'billable_type' => FakeBillable::class,
        'billable_id' => $billable->id,
        'plan_id' => $plan->id,
        'driver' => 'modempay',
        'status' => $status,
        'current_period_end' => $periodEnd,
    ]);

    $sub->forceFill(['updated_at' => $updatedAt])->save();

    return $sub;
}

beforeEach(function () {
    Bus::fake();
    config(['gmb-pay.subscriptions.grace_days' => 3]);
});

it('cancels PastDue subs whose updated_at is older than grace_days', function () {
    $sub = makeGraceSub(SubscriptionStatus::PastDue, now()->subDays(5));

    Artisan::call('gmb-pay:cycle');

    $fresh = $sub->fresh();
    expect($fresh->status)->toBe(SubscriptionStatus::Canceled)
        ->and($fresh->canceled_at)->not->toBeNull();
});

it('keeps PastDue subs within the grace window untouched', function () {
    $sub = makeGraceSub(SubscriptionStatus::PastDue, now()->subDay());

    Artisan::call('gmb-pay:cycle');

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});

it('processes Active dispatches and PastDue grace cancellations in a single command run', function () {
    $activeDue = makeGraceSub(SubscriptionStatus::Active, now(), now()->subHour());
    $expiredPastDue = makeGraceSub(SubscriptionStatus::PastDue, now()->subDays(10));

    Artisan::call('gmb-pay:cycle');

    Bus::assertDispatched(InitiateRecurringChargeJob::class, function ($job) use ($activeDue) {
        return $job->subscription->is($activeDue);
    });

    expect($expiredPastDue->fresh()->status)->toBe(SubscriptionStatus::Canceled);
});
