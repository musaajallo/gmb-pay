<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\PlanInterval;
use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Models\Plan;
use Africs\GmbPay\Models\Subscription;
use Africs\GmbPay\Models\SubscriptionItem;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;
use Illuminate\Support\Facades\Schema;

function makeItemSubscription(): Subscription
{
    $billable = FakeBillable::create(['name' => 'Item Sub']);

    $plan = Plan::create([
        'slug' => 'items-plan-' . uniqid(),
        'name' => 'Items Plan',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Month,
    ]);

    return Subscription::create([
        'billable_type' => FakeBillable::class,
        'billable_id' => $billable->id,
        'plan_id' => $plan->id,
        'driver' => 'modempay',
        'status' => SubscriptionStatus::Active,
    ]);
}

it('creates the gmb_pay_subscription_items table when migrations run', function () {
    expect(Schema::hasTable('gmb_pay_subscription_items'))->toBeTrue();

    expect(Schema::hasColumns('gmb_pay_subscription_items', [
        'id',
        'subscription_id',
        'quantity',
        'unit_amount_minor',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('persists a SubscriptionItem with int casts', function () {
    $subscription = makeItemSubscription();

    SubscriptionItem::create([
        'subscription_id' => $subscription->id,
        'unit_amount_minor' => 5000,
        'quantity' => 2,
    ]);

    $fetched = SubscriptionItem::firstOrFail();

    expect($fetched->subscription_id)->toBe($subscription->id)
        ->and($fetched->quantity)->toBe(2)
        ->and($fetched->unit_amount_minor)->toBe(5000);
});

it('resolves subscription() and Subscription::items() relations', function () {
    $subscription = makeItemSubscription();

    $item = SubscriptionItem::create([
        'subscription_id' => $subscription->id,
        'unit_amount_minor' => 5000,
    ]);

    expect($item->subscription)->toBeInstanceOf(Subscription::class)
        ->and($item->subscription->is($subscription))->toBeTrue()
        ->and($subscription->fresh()->items)->toHaveCount(1)
        ->and($subscription->fresh()->items->first()->is($item))->toBeTrue();
});

it('cascades delete from Subscription to its items', function () {
    $subscription = makeItemSubscription();

    SubscriptionItem::create([
        'subscription_id' => $subscription->id,
        'unit_amount_minor' => 5000,
    ]);

    expect(SubscriptionItem::count())->toBe(1);

    $subscription->delete();

    expect(SubscriptionItem::count())->toBe(0);
});
