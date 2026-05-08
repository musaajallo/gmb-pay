<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('creates the gmb_pay_idempotency_keys table when migrations run', function () {
    expect(Schema::hasTable('gmb_pay_idempotency_keys'))->toBeTrue();

    expect(Schema::hasColumns('gmb_pay_idempotency_keys', [
        'id',
        'driver',
        'key',
        'target_type',
        'target_id',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('persists an idempotency row with a target', function () {
    $charge = Charge::create([
        'reference' => 'chg_idem_target',
        'driver' => 'modempay',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);

    $row = IdempotencyKey::create([
        'driver' => 'modempay',
        'key' => 'order-1234',
        'target_type' => Charge::class,
        'target_id' => $charge->id,
    ]);

    expect($row->exists)->toBeTrue()
        ->and($row->driver)->toBe('modempay')
        ->and($row->key)->toBe('order-1234')
        ->and($row->target_id)->toBe($charge->id);
});

it('resolves the morphTo target', function () {
    $charge = Charge::create([
        'reference' => 'chg_idem_morph',
        'driver' => 'modempay',
        'amount_minor' => 100,
        'currency' => 'GMD',
        'status' => ChargeStatus::Succeeded,
    ]);

    IdempotencyKey::create([
        'driver' => 'modempay',
        'key' => 'morph-key',
        'target_type' => Charge::class,
        'target_id' => $charge->id,
    ]);

    $fetched = IdempotencyKey::where('key', 'morph-key')->firstOrFail();

    expect($fetched->target)->not->toBeNull()
        ->and($fetched->target)->toBeInstanceOf(Charge::class)
        ->and($fetched->target->is($charge))->toBeTrue();
});

it('enforces uniqueness on (driver, key)', function () {
    IdempotencyKey::create([
        'driver' => 'modempay',
        'key' => 'dup-key',
    ]);

    expect(fn () => IdempotencyKey::create([
        'driver' => 'modempay',
        'key' => 'dup-key',
    ]))->toThrow(QueryException::class);
});

it('allows the same key across distinct drivers', function () {
    IdempotencyKey::create(['driver' => 'modempay', 'key' => 'shared-key']);
    IdempotencyKey::create(['driver' => 'wave', 'key' => 'shared-key']);

    expect(IdempotencyKey::where('key', 'shared-key')->count())->toBe(2);
});
