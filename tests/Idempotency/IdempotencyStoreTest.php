<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Idempotency\IdempotencyStore;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\IdempotencyKey;

function makeIdempotencyTestCharge(string $reference, string $driver = 'modempay'): Charge
{
    return Charge::create([
        'reference' => $reference,
        'driver' => $driver,
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);
}

it('runs the callback once, returns the model, and persists a row pointing at it', function () {
    $store = new IdempotencyStore;
    $calls = 0;

    $result = $store->remember('modempay', 'order-1', function () use (&$calls) {
        $calls++;

        return makeIdempotencyTestCharge('chg_order_1');
    });

    expect($calls)->toBe(1)
        ->and($result)->toBeInstanceOf(Charge::class)
        ->and($result->reference)->toBe('chg_order_1');

    $row = IdempotencyKey::where('driver', 'modempay')
        ->where('key', 'order-1')
        ->firstOrFail();

    expect($row->target_type)->toBe(Charge::class)
        ->and($row->target_id)->toBe($result->id);
});

it('does not re-run the callback on a repeat (driver, key), and returns the prior model', function () {
    $store = new IdempotencyStore;
    $calls = 0;
    $callback = function () use (&$calls) {
        $calls++;

        return makeIdempotencyTestCharge('chg_repeat_'.$calls);
    };

    $first = $store->remember('modempay', 'order-2', $callback);
    $second = $store->remember('modempay', 'order-2', $callback);

    expect($calls)->toBe(1)
        ->and($second->is($first))->toBeTrue()
        ->and(IdempotencyKey::where('driver', 'modempay')->where('key', 'order-2')->count())->toBe(1);
});

it('treats the same key under different drivers as independent', function () {
    $store = new IdempotencyStore;
    $calls = 0;

    $modempay = $store->remember('modempay', 'shared-key', function () use (&$calls) {
        $calls++;

        return makeIdempotencyTestCharge('chg_modempay_shared', 'modempay');
    });

    $wave = $store->remember('wave', 'shared-key', function () use (&$calls) {
        $calls++;

        return makeIdempotencyTestCharge('chg_wave_shared', 'wave');
    });

    expect($calls)->toBe(2)
        ->and($wave->is($modempay))->toBeFalse()
        ->and(IdempotencyKey::where('key', 'shared-key')->count())->toBe(2);
});

it('treats different keys under the same driver as independent', function () {
    $store = new IdempotencyStore;
    $calls = 0;

    $first = $store->remember('modempay', 'key-a', function () use (&$calls) {
        $calls++;

        return makeIdempotencyTestCharge('chg_key_a');
    });

    $second = $store->remember('modempay', 'key-b', function () use (&$calls) {
        $calls++;

        return makeIdempotencyTestCharge('chg_key_b');
    });

    expect($calls)->toBe(2)
        ->and($second->is($first))->toBeFalse();
});
