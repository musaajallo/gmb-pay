<?php

declare(strict_types=1);

use Africs\GmbPay\DataObjects\ChargeResult;
use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;

function makeBillableForCharging(): FakeBillable
{
    return FakeBillable::create(['name' => 'Charging User']);
}

beforeEach(function () {
    config([
        'gmb-pay.demo_mode' => true,
        'gmb-pay.default' => 'modempay',
    ]);
});

it('drives the default driver, persists a Charge linked to the customer, and returns a ChargeResult', function () {
    $billable = makeBillableForCharging();

    $result = $billable->charge(5000, 'GMD', ['customerPhone' => '+2203000000']);

    expect($result)->toBeInstanceOf(ChargeResult::class)
        ->and($result->status)->toBe(ChargeStatus::Pending)
        ->and($result->checkoutUrl)->toStartWith('https://demo.local/checkout/');

    $customer = $billable->gmbPayCustomers()->where('driver', 'modempay')->firstOrFail();

    $charge = Charge::where('reference', $result->reference)->firstOrFail();

    expect($charge->driver)->toBe('modempay')
        ->and((int) $charge->customer_id)->toBe($customer->id)
        ->and((int) $charge->amount_minor)->toBe(5000)
        ->and($charge->currency)->toBe('GMD')
        ->and($charge->status)->toBe(ChargeStatus::Pending);
});

it('respects an explicit driver option', function () {
    $billable = makeBillableForCharging();

    $result = $billable->charge(5000, 'GMD', ['driver' => 'wave']);

    $charge = Charge::where('reference', $result->reference)->firstOrFail();
    expect($charge->driver)->toBe('wave');

    $customer = $billable->gmbPayCustomers()->where('driver', 'wave')->firstOrFail();
    expect((int) $charge->customer_id)->toBe($customer->id);
});

it('deduplicates repeat calls with the same idempotencyKey via F12', function () {
    $billable = makeBillableForCharging();

    $first = $billable->charge(5000, 'GMD', ['idempotencyKey' => 'order-22-a']);
    $second = $billable->charge(5000, 'GMD', ['idempotencyKey' => 'order-22-a']);

    expect($second->reference)->toBe($first->reference)
        ->and(Charge::where('driver', 'modempay')->count())->toBe(1);

    $charge = Charge::where('reference', $first->reference)->firstOrFail();
    $customer = $billable->gmbPayCustomers()->where('driver', 'modempay')->firstOrFail();
    expect((int) $charge->customer_id)->toBe($customer->id);
});

it('produces distinct Charges for distinct idempotencyKeys', function () {
    $billable = makeBillableForCharging();

    $billable->charge(5000, 'GMD', ['idempotencyKey' => 'order-22-x']);
    $billable->charge(5000, 'GMD', ['idempotencyKey' => 'order-22-y']);

    $customer = $billable->gmbPayCustomers()->where('driver', 'modempay')->firstOrFail();
    expect(Charge::where('customer_id', $customer->id)->count())->toBe(2);
});

it('passes through caller-supplied metadata onto the persisted Charge', function () {
    $billable = makeBillableForCharging();

    $result = $billable->charge(5000, 'GMD', [
        'metadata' => ['order_id' => 999, 'cart' => 'A'],
    ]);

    $charge = Charge::where('reference', $result->reference)->firstOrFail();

    expect($charge->metadata)->toMatchArray([
        'order_id' => 999,
        'cart' => 'A',
    ]);
});
