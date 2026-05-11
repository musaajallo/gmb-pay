<?php

declare(strict_types=1);

use Africs\GmbPay\DataObjects\ChargeRequest;
use Africs\GmbPay\DataObjects\ChargeResult;
use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Facades\GmbPay;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\IdempotencyKey;

function makeIdempotentChargeRequest(?string $key): ChargeRequest
{
    return new ChargeRequest(
        amountMinor: 5000,
        currency: 'GMD',
        customerPhone: '+2203000000',
        idempotencyKey: $key,
    );
}

it('runs the driver exactly once and replays the same ChargeResult when idempotencyKey is set', function () {
    $request = makeIdempotentChargeRequest('order-1');

    $first = GmbPay::charge($request);
    $second = GmbPay::charge($request);

    expect($first)->toBeInstanceOf(ChargeResult::class)
        ->and($second)->toBeInstanceOf(ChargeResult::class)
        ->and($second->reference)->toBe($first->reference)
        ->and($second->status)->toBe($first->status)
        ->and($second->amountMinor)->toBe($first->amountMinor)
        ->and($second->currency)->toBe($first->currency)
        ->and($second->checkoutUrl)->toBe($first->checkoutUrl);

    expect(Charge::where('driver', 'modempay')->count())->toBe(1)
        ->and(IdempotencyKey::where('driver', 'modempay')->where('key', 'order-1')->count())->toBe(1);

    $key = IdempotencyKey::where('driver', 'modempay')->where('key', 'order-1')->firstOrFail();
    expect($key->target_type)->toBe(Charge::class)
        ->and($key->target?->reference)->toBe($first->reference);
});

it('preserves pure-passthrough behavior when idempotencyKey is null', function () {
    $request = makeIdempotentChargeRequest(null);

    $result = GmbPay::charge($request);

    expect($result)->toBeInstanceOf(ChargeResult::class)
        ->and($result->status)->toBe(ChargeStatus::Pending)
        ->and($result->checkoutUrl)->toStartWith('https://demo.local/checkout/');

    expect(Charge::count())->toBe(0)
        ->and(IdempotencyKey::count())->toBe(0);
});

it('treats distinct idempotencyKeys under the same driver as independent calls', function () {
    $a = GmbPay::charge(makeIdempotentChargeRequest('key-a'));
    $b = GmbPay::charge(makeIdempotentChargeRequest('key-b'));

    expect($b->reference)->not->toBe($a->reference);

    expect(Charge::where('driver', 'modempay')->count())->toBe(2)
        ->and(IdempotencyKey::where('driver', 'modempay')->count())->toBe(2);
});
