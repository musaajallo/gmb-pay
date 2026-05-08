<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\PayoutStatus;
use Africs\GmbPay\Models\Payout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('creates the gmb_pay_payouts table when migrations run', function () {
    expect(Schema::hasTable('gmb_pay_payouts'))->toBeTrue();

    expect(Schema::hasColumns('gmb_pay_payouts', [
        'id',
        'reference',
        'provider_reference',
        'driver',
        'recipient_phone',
        'amount_minor',
        'currency',
        'status',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('persists a payout with all required fields', function () {
    $payout = Payout::create([
        'reference' => 'pay_local_001',
        'provider_reference' => 'pay_provider_001',
        'driver' => 'modempay',
        'recipient_phone' => '+2203000000',
        'amount_minor' => 9000,
        'currency' => 'GMD',
        'status' => PayoutStatus::Pending,
    ]);

    expect($payout->exists)->toBeTrue()
        ->and($payout->reference)->toBe('pay_local_001')
        ->and($payout->provider_reference)->toBe('pay_provider_001')
        ->and($payout->driver)->toBe('modempay')
        ->and($payout->recipient_phone)->toBe('+2203000000')
        ->and($payout->amount_minor)->toBe(9000)
        ->and($payout->currency)->toBe('GMD')
        ->and($payout->status)->toBe(PayoutStatus::Pending);
});

it('casts status to the PayoutStatus enum on retrieval', function () {
    Payout::create([
        'reference' => 'pay_local_002',
        'driver' => 'wave',
        'recipient_phone' => '+2203000001',
        'amount_minor' => 100,
        'currency' => 'GMD',
        'status' => PayoutStatus::Succeeded,
    ]);

    $fetched = Payout::where('reference', 'pay_local_002')->firstOrFail();

    expect($fetched->status)->toBe(PayoutStatus::Succeeded);
});

it('enforces uniqueness on the local payout reference', function () {
    Payout::create([
        'reference' => 'pay_dup',
        'driver' => 'modempay',
        'recipient_phone' => '+2203000000',
        'amount_minor' => 100,
        'currency' => 'GMD',
        'status' => PayoutStatus::Pending,
    ]);

    expect(fn () => Payout::create([
        'reference' => 'pay_dup',
        'driver' => 'modempay',
        'recipient_phone' => '+2203000000',
        'amount_minor' => 200,
        'currency' => 'GMD',
        'status' => PayoutStatus::Pending,
    ]))->toThrow(QueryException::class);
});

it('enforces uniqueness on (driver, provider_reference) when provider_reference is set', function () {
    Payout::create([
        'reference' => 'pay_uniq_a',
        'provider_reference' => 'pay_provider_dup',
        'driver' => 'modempay',
        'recipient_phone' => '+2203000000',
        'amount_minor' => 100,
        'currency' => 'GMD',
        'status' => PayoutStatus::Succeeded,
    ]);

    expect(fn () => Payout::create([
        'reference' => 'pay_uniq_b',
        'provider_reference' => 'pay_provider_dup',
        'driver' => 'modempay',
        'recipient_phone' => '+2203000000',
        'amount_minor' => 200,
        'currency' => 'GMD',
        'status' => PayoutStatus::Succeeded,
    ]))->toThrow(QueryException::class);
});
