<?php

declare(strict_types=1);

use Africs\GmbPay\Models\Customer;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;

function makeBillableForCustomer(): FakeBillable
{
    return FakeBillable::create(['name' => 'Test User']);
}

it('creates a local Customer row tied to the billable and the default driver', function () {
    config(['gmb-pay.default' => 'modempay']);

    $billable = makeBillableForCustomer();

    $customer = $billable->createGmbPayCustomer();

    expect($customer)->toBeInstanceOf(Customer::class)
        ->and($customer->billable_type)->toBe(FakeBillable::class)
        ->and((int) $customer->billable_id)->toBe($billable->id)
        ->and($customer->driver)->toBe('modempay');
});

it('respects an explicit driver argument', function () {
    $billable = makeBillableForCustomer();

    $customer = $billable->createGmbPayCustomer('wave');

    expect($customer->driver)->toBe('wave');
});

it('returns the same row when called twice with the same driver', function () {
    $billable = makeBillableForCustomer();

    $first = $billable->createGmbPayCustomer('modempay');
    $second = $billable->createGmbPayCustomer('modempay');

    expect($second->is($first))->toBeTrue()
        ->and(Customer::where('billable_type', FakeBillable::class)
            ->where('billable_id', $billable->id)
            ->where('driver', 'modempay')
            ->count())->toBe(1);
});

it('persists opts[metadata] on first create and does not overwrite it on subsequent calls', function () {
    $billable = makeBillableForCustomer();

    $first = $billable->createGmbPayCustomer('modempay', ['metadata' => ['plan' => 'pro']]);
    $second = $billable->createGmbPayCustomer('modempay', ['metadata' => ['plan' => 'free']]);

    expect($first->fresh()->metadata)->toBe(['plan' => 'pro'])
        ->and($second->fresh()->metadata)->toBe(['plan' => 'pro']);
});

it('allows distinct customers under different drivers for the same billable', function () {
    $billable = makeBillableForCustomer();

    $modempay = $billable->createGmbPayCustomer('modempay');
    $wave = $billable->createGmbPayCustomer('wave');

    expect($modempay->is($wave))->toBeFalse()
        ->and($billable->gmbPayCustomers()->count())->toBe(2);
});
