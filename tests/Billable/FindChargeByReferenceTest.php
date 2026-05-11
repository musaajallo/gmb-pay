<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\Customer;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;

function billableForLookup(): FakeBillable
{
    return FakeBillable::create(['name' => 'Lookup User']);
}

function attachLookupCharge(FakeBillable $billable, string $reference): Charge
{
    $customer = Customer::firstOrCreate([
        'billable_type' => FakeBillable::class,
        'billable_id' => $billable->id,
        'driver' => 'modempay',
    ]);

    return Charge::create([
        'reference' => $reference,
        'driver' => 'modempay',
        'customer_id' => $customer->id,
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);
}

it('finds a Charge that belongs to this billable', function () {
    $billable = billableForLookup();
    attachLookupCharge($billable, 'chg_lookup_1');

    $found = $billable->findChargeByReference('chg_lookup_1');

    expect($found)->toBeInstanceOf(Charge::class)
        ->and($found->reference)->toBe('chg_lookup_1');
});

it('returns null when no Charge with that reference exists', function () {
    $billable = billableForLookup();

    expect($billable->findChargeByReference('chg_does_not_exist'))->toBeNull();
});

it('returns null when the Charge exists but belongs to a different billable', function () {
    $a = billableForLookup();
    $b = billableForLookup();

    attachLookupCharge($b, 'chg_lookup_other');

    expect($a->findChargeByReference('chg_lookup_other'))->toBeNull();
});

it('returns null for orphan charges with no customer_id', function () {
    $billable = billableForLookup();

    Charge::create([
        'reference' => 'chg_orphan_lookup',
        'driver' => 'modempay',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);

    expect($billable->findChargeByReference('chg_orphan_lookup'))->toBeNull();
});
