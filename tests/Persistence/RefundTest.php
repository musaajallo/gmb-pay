<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Enums\RefundStatus;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\Refund;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

function makeCharge(string $reference = 'chg_for_refund'): Charge
{
    return Charge::create([
        'reference' => $reference,
        'driver' => 'modempay',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Succeeded,
    ]);
}

it('creates the gmb_pay_refunds table when migrations run', function () {
    expect(Schema::hasTable('gmb_pay_refunds'))->toBeTrue();

    expect(Schema::hasColumns('gmb_pay_refunds', [
        'id',
        'charge_id',
        'reference',
        'provider_reference',
        'amount_minor',
        'status',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('persists a refund with all required fields', function () {
    $charge = makeCharge();

    $refund = Refund::create([
        'charge_id' => $charge->id,
        'reference' => 'rfd_local_001',
        'provider_reference' => 'rfd_provider_001',
        'amount_minor' => 1500,
        'status' => RefundStatus::Pending,
    ]);

    expect($refund->exists)->toBeTrue()
        ->and($refund->charge_id)->toBe($charge->id)
        ->and($refund->reference)->toBe('rfd_local_001')
        ->and($refund->provider_reference)->toBe('rfd_provider_001')
        ->and($refund->amount_minor)->toBe(1500)
        ->and($refund->status)->toBe(RefundStatus::Pending);
});

it('casts status to the RefundStatus enum on retrieval', function () {
    $charge = makeCharge('chg_for_refund_2');

    Refund::create([
        'charge_id' => $charge->id,
        'reference' => 'rfd_local_002',
        'amount_minor' => 100,
        'status' => RefundStatus::Succeeded,
    ]);

    $fetched = Refund::where('reference', 'rfd_local_002')->firstOrFail();

    expect($fetched->status)->toBe(RefundStatus::Succeeded);
});

it('belongs to its parent charge', function () {
    $charge = makeCharge('chg_for_refund_3');

    $refund = Refund::create([
        'charge_id' => $charge->id,
        'reference' => 'rfd_local_003',
        'amount_minor' => 100,
        'status' => RefundStatus::Pending,
    ]);

    expect($refund->charge)->not->toBeNull()
        ->and($refund->charge->is($charge))->toBeTrue();
});

it('exposes refunds collection on the charge', function () {
    $charge = makeCharge('chg_with_two_refunds');

    Refund::create([
        'charge_id' => $charge->id,
        'reference' => 'rfd_partial_a',
        'amount_minor' => 1000,
        'status' => RefundStatus::Succeeded,
    ]);

    Refund::create([
        'charge_id' => $charge->id,
        'reference' => 'rfd_partial_b',
        'amount_minor' => 2000,
        'status' => RefundStatus::Succeeded,
    ]);

    expect($charge->refunds()->count())->toBe(2)
        ->and($charge->refunds->pluck('reference')->all())
        ->toEqualCanonicalizing(['rfd_partial_a', 'rfd_partial_b']);
});

it('enforces uniqueness on the local refund reference', function () {
    $charge = makeCharge('chg_for_refund_dup');

    Refund::create([
        'charge_id' => $charge->id,
        'reference' => 'rfd_dup',
        'amount_minor' => 100,
        'status' => RefundStatus::Pending,
    ]);

    expect(fn () => Refund::create([
        'charge_id' => $charge->id,
        'reference' => 'rfd_dup',
        'amount_minor' => 200,
        'status' => RefundStatus::Pending,
    ]))->toThrow(QueryException::class);
});
