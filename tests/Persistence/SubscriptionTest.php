<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\PlanInterval;
use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Models\Plan;
use Africs\GmbPay\Models\Subscription;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;
use Illuminate\Support\Facades\Schema;

function makeSubscriptionPlan(string $slug = 'sub-test-plan'): Plan
{
    return Plan::create([
        'slug' => $slug,
        'name' => 'Test Plan',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Month,
    ]);
}

it('creates the gmb_pay_subscriptions table when migrations run', function () {
    expect(Schema::hasTable('gmb_pay_subscriptions'))->toBeTrue();

    expect(Schema::hasColumns('gmb_pay_subscriptions', [
        'id',
        'billable_type',
        'billable_id',
        'plan_id',
        'driver',
        'status',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'canceled_at',
        'trial_ends_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('persists a Subscription with casts on read', function () {
    $billable = FakeBillable::create(['name' => 'Subby']);
    $plan = makeSubscriptionPlan();

    $now = now()->startOfSecond();

    Subscription::create([
        'billable_type' => FakeBillable::class,
        'billable_id' => $billable->id,
        'plan_id' => $plan->id,
        'driver' => 'modempay',
        'status' => SubscriptionStatus::Active,
        'current_period_start' => $now,
        'current_period_end' => $now->copy()->addMonth(),
        'cancel_at_period_end' => false,
        'trial_ends_at' => $now->copy()->addDays(7),
    ]);

    $fetched = Subscription::first();

    expect($fetched->status)->toBe(SubscriptionStatus::Active)
        ->and($fetched->cancel_at_period_end)->toBeFalse()
        ->and($fetched->current_period_start->equalTo($now))->toBeTrue()
        ->and($fetched->current_period_end->equalTo($now->copy()->addMonth()))->toBeTrue()
        ->and($fetched->trial_ends_at->equalTo($now->copy()->addDays(7)))->toBeTrue()
        ->and($fetched->canceled_at)->toBeNull();
});

it('resolves billable() and plan() relations', function () {
    $billable = FakeBillable::create(['name' => 'Relations']);
    $plan = makeSubscriptionPlan('relations-plan');

    Subscription::create([
        'billable_type' => FakeBillable::class,
        'billable_id' => $billable->id,
        'plan_id' => $plan->id,
        'driver' => 'modempay',
        'status' => SubscriptionStatus::Active,
    ]);

    $sub = Subscription::first();

    expect($sub->billable)->toBeInstanceOf(FakeBillable::class)
        ->and($sub->billable->is($billable))->toBeTrue()
        ->and($sub->plan)->toBeInstanceOf(Plan::class)
        ->and($sub->plan->is($plan))->toBeTrue();
});
