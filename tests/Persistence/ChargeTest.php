<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\Customer;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('creates the gmb_pay_charges table when migrations run', function () {
    expect(Schema::hasTable('gmb_pay_charges'))->toBeTrue();

    expect(Schema::hasColumns('gmb_pay_charges', [
        'id',
        'reference',
        'provider_reference',
        'driver',
        'customer_id',
        'amount_minor',
        'currency',
        'status',
        'metadata',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('persists a charge with all required fields', function () {
    $charge = Charge::create([
        'reference' => 'chg_local_001',
        'provider_reference' => 'pi_provider_001',
        'driver' => 'modempay',
        'customer_id' => null,
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
        'metadata' => ['order_id' => 42],
    ]);

    expect($charge->exists)->toBeTrue()
        ->and($charge->reference)->toBe('chg_local_001')
        ->and($charge->provider_reference)->toBe('pi_provider_001')
        ->and($charge->driver)->toBe('modempay')
        ->and($charge->amount_minor)->toBe(5000)
        ->and($charge->currency)->toBe('GMD')
        ->and($charge->status)->toBe(ChargeStatus::Pending)
        ->and($charge->metadata)->toBe(['order_id' => 42]);
});

it('casts status to the ChargeStatus enum on retrieval', function () {
    Charge::create([
        'reference' => 'chg_local_002',
        'driver' => 'modempay',
        'amount_minor' => 1000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Succeeded,
    ]);

    $fetched = Charge::where('reference', 'chg_local_002')->firstOrFail();

    expect($fetched->status)->toBe(ChargeStatus::Succeeded);
});

it('belongs to a customer', function () {
    $billable = FakeBillable::create(['name' => 'Acme Ltd']);
    $customer = Customer::create([
        'billable_type' => $billable->getMorphClass(),
        'billable_id' => $billable->getKey(),
        'driver' => 'modempay',
        'provider_customer_id' => 'cus_X',
    ]);

    $charge = Charge::create([
        'reference' => 'chg_local_003',
        'driver' => 'modempay',
        'customer_id' => $customer->id,
        'amount_minor' => 2500,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);

    expect($charge->customer)->not->toBeNull()
        ->and($charge->customer->is($customer))->toBeTrue();
});

it('enforces uniqueness on the local reference', function () {
    Charge::create([
        'reference' => 'chg_dup',
        'driver' => 'modempay',
        'amount_minor' => 100,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);

    expect(fn () => Charge::create([
        'reference' => 'chg_dup',
        'driver' => 'wave',
        'amount_minor' => 200,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]))->toThrow(QueryException::class);
});

it('enforces uniqueness on (driver, provider_reference) when provider_reference is set', function () {
    Charge::create([
        'reference' => 'chg_a',
        'provider_reference' => 'pi_shared',
        'driver' => 'modempay',
        'amount_minor' => 100,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);

    expect(fn () => Charge::create([
        'reference' => 'chg_b',
        'provider_reference' => 'pi_shared',
        'driver' => 'modempay',
        'amount_minor' => 200,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]))->toThrow(QueryException::class);
});
