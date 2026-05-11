<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\PlanInterval;
use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Jobs\InitiateRecurringChargeJob;
use Africs\GmbPay\Models\Plan;
use Africs\GmbPay\Models\Subscription;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;

function billableForSubscribe(): FakeBillable
{
    return FakeBillable::create(['name' => 'Subscriber']);
}

function makePlan(array $overrides = []): Plan
{
    return Plan::create(array_merge([
        'slug' => 'plan-'.uniqid(),
        'name' => 'Test Plan',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Month,
        'trial_days' => 0,
    ], $overrides));
}

beforeEach(function () {
    Bus::fake();
    config(['gmb-pay.default' => 'modempay']);
});

it('creates a Subscription + SubscriptionItem from a Plan model', function () {
    $billable = billableForSubscribe();
    $plan = makePlan(['amount_minor' => 7500]);

    $sub = $billable->subscribeToPlan($plan);

    expect($sub)->toBeInstanceOf(Subscription::class)
        ->and($sub->status)->toBe(SubscriptionStatus::Incomplete)
        ->and($sub->driver)->toBe('modempay')
        ->and($sub->plan_id)->toBe($plan->id);

    expect($sub->items)->toHaveCount(1)
        ->and($sub->items->first()->unit_amount_minor)->toBe(7500)
        ->and($sub->items->first()->quantity)->toBe(1);
});

it('resolves a plan slug string to a Plan', function () {
    $billable = billableForSubscribe();
    $plan = makePlan(['slug' => 'pro-monthly']);

    $sub = $billable->subscribeToPlan('pro-monthly');

    expect($sub->plan_id)->toBe($plan->id);
});

it('sets trial_ends_at when plan has trial_days > 0; null otherwise', function () {
    $billable = billableForSubscribe();

    $trialPlan = makePlan(['slug' => 'trial-plan', 'trial_days' => 14]);
    $sub = $billable->subscribeToPlan($trialPlan);
    expect($sub->trial_ends_at)->not->toBeNull()
        ->and(abs($sub->trial_ends_at->diffInSeconds(now()->addDays(14))))->toBeLessThan(5);

    $noTrialPlan = makePlan(['slug' => 'no-trial', 'trial_days' => 0]);
    $sub2 = $billable->subscribeToPlan($noTrialPlan);
    expect($sub2->trial_ends_at)->toBeNull();
});

it('throws ModelNotFoundException for an unknown plan slug', function () {
    $billable = billableForSubscribe();

    expect(fn () => $billable->subscribeToPlan('does-not-exist'))
        ->toThrow(ModelNotFoundException::class);
});

it('dispatches InitiateRecurringChargeJob with the created subscription', function () {
    $billable = billableForSubscribe();
    $plan = makePlan();

    $sub = $billable->subscribeToPlan($plan);

    Bus::assertDispatched(InitiateRecurringChargeJob::class, function ($job) use ($sub) {
        return $job->subscription->is($sub);
    });
});

it('respects an explicit driver override in $opts', function () {
    $billable = billableForSubscribe();
    $plan = makePlan();

    $sub = $billable->subscribeToPlan($plan, ['driver' => 'wave']);

    expect($sub->driver)->toBe('wave');
});
