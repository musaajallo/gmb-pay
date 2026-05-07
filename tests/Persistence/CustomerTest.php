<?php

declare(strict_types=1);

use Africs\GmbPay\Models\Customer;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;
use Illuminate\Support\Facades\Schema;

it('creates the gmb_pay_customers table when migrations run', function () {
    expect(Schema::hasTable('gmb_pay_customers'))->toBeTrue();

    expect(Schema::hasColumns('gmb_pay_customers', [
        'id',
        'billable_type',
        'billable_id',
        'driver',
        'provider_customer_id',
        'metadata',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('persists a customer linked to a polymorphic billable', function () {
    $billable = FakeBillable::create(['name' => 'Acme Ltd']);

    $customer = Customer::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'driver' => 'modempay',
        'provider_customer_id' => 'cus_test_123',
        'metadata' => ['source' => 'unit-test'],
    ]);

    expect($customer->exists)->toBeTrue()
        ->and($customer->driver)->toBe('modempay')
        ->and($customer->provider_customer_id)->toBe('cus_test_123')
        ->and($customer->metadata)->toBe(['source' => 'unit-test']);
});

it('resolves the polymorphic billable owner', function () {
    $billable = FakeBillable::create(['name' => 'Acme Ltd']);

    $customer = Customer::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'driver' => 'wave',
        'provider_customer_id' => null,
    ]);

    $owner = $customer->billable;

    expect($owner)->toBeInstanceOf(FakeBillable::class)
        ->and($owner->is($billable))->toBeTrue();
});

it('enforces uniqueness on (billable_type, billable_id, driver)', function () {
    $billable = FakeBillable::create(['name' => 'Acme Ltd']);

    Customer::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'driver' => 'modempay',
        'provider_customer_id' => 'cus_first',
    ]);

    expect(fn () => Customer::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'driver' => 'modempay',
        'provider_customer_id' => 'cus_dup',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
