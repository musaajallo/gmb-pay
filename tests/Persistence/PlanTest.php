<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\PlanInterval;
use Africs\GmbPay\Models\Plan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('creates the gmb_pay_plans table when migrations run', function () {
    expect(Schema::hasTable('gmb_pay_plans'))->toBeTrue();

    expect(Schema::hasColumns('gmb_pay_plans', [
        'id',
        'slug',
        'name',
        'amount_minor',
        'currency',
        'interval',
        'interval_count',
        'trial_days',
        'active',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('persists a Plan with required fields and casts on read', function () {
    $plan = Plan::create([
        'slug' => 'pro-monthly',
        'name' => 'Pro Monthly',
        'amount_minor' => 50000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Month,
        'interval_count' => 1,
        'trial_days' => 7,
        'active' => true,
    ]);

    $fetched = Plan::where('slug', 'pro-monthly')->firstOrFail();

    expect($fetched->amount_minor)->toBe(50000)
        ->and($fetched->interval)->toBe(PlanInterval::Month)
        ->and($fetched->interval_count)->toBe(1)
        ->and($fetched->trial_days)->toBe(7)
        ->and($fetched->active)->toBeTrue();
});

it('enforces uniqueness on slug', function () {
    Plan::create([
        'slug' => 'dup-slug',
        'name' => 'A',
        'amount_minor' => 1000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Month,
    ]);

    expect(fn () => Plan::create([
        'slug' => 'dup-slug',
        'name' => 'B',
        'amount_minor' => 2000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Year,
    ]))->toThrow(QueryException::class);
});

it('applies database defaults for interval_count, trial_days, and active', function () {
    Plan::create([
        'slug' => 'defaults-only',
        'name' => 'Defaults',
        'amount_minor' => 1000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Month,
    ]);

    $fetched = Plan::where('slug', 'defaults-only')->firstOrFail();

    expect($fetched->interval_count)->toBe(1)
        ->and($fetched->trial_days)->toBe(0)
        ->and($fetched->active)->toBeTrue();
});
