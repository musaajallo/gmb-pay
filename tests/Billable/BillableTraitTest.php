<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\Customer;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;

function makeFakeBillable(): FakeBillable
{
    return FakeBillable::create(['name' => 'Test User']);
}

function attachCustomer(FakeBillable $billable, string $driver = 'modempay'): Customer
{
    return Customer::create([
        'billable_type' => FakeBillable::class,
        'billable_id' => $billable->id,
        'driver' => $driver,
    ]);
}

function attachChargeTo(Customer $customer, string $reference): Charge
{
    return Charge::create([
        'reference' => $reference,
        'driver' => $customer->driver,
        'customer_id' => $customer->id,
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);
}

it('exposes the billable customers via gmbPayCustomers()', function () {
    $billable = makeFakeBillable();

    attachCustomer($billable, 'modempay');
    attachCustomer($billable, 'wave');

    expect($billable->gmbPayCustomers)->toHaveCount(2);
});

it('exposes charges via gmbPayCharges() through the Customer pivot', function () {
    $billable = makeFakeBillable();
    $customer = attachCustomer($billable);

    attachChargeTo($customer, 'chg_f20_a');

    expect($billable->gmbPayCharges)->toHaveCount(1)
        ->and($billable->gmbPayCharges->first()->reference)->toBe('chg_f20_a');
});

it('does not include orphan charges that have no customer_id', function () {
    $billable = makeFakeBillable();

    Charge::create([
        'reference' => 'chg_orphan',
        'driver' => 'modempay',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);

    expect($billable->gmbPayCharges)->toBeEmpty();
});

it('isolates customers and charges across distinct billable instances', function () {
    $a = makeFakeBillable();
    $b = makeFakeBillable();

    $ca = attachCustomer($a, 'modempay');
    $cb = attachCustomer($b, 'modempay');

    attachChargeTo($ca, 'chg_a_1');
    attachChargeTo($cb, 'chg_b_1');

    expect($a->gmbPayCharges->pluck('reference')->all())->toBe(['chg_a_1'])
        ->and($b->gmbPayCharges->pluck('reference')->all())->toBe(['chg_b_1']);
});
