<?php

declare(strict_types=1);

use Africs\GmbPay\DataObjects\RefundResult;
use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Enums\RefundStatus;
use Africs\GmbPay\Exceptions\GmbPayException;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\Customer;
use Africs\GmbPay\Models\Refund;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;

function billableForRefund(): FakeBillable
{
    return FakeBillable::create(['name' => 'Refund User']);
}

function attachRefundableCharge(FakeBillable $billable, string $reference = 'chg_refund_1'): Charge
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
        'status' => ChargeStatus::Succeeded,
    ]);
}

beforeEach(function () {
    config([
        'gmb-pay.demo_mode' => true,
        'gmb-pay.default' => 'modempay',
    ]);
});

it('drives the driver, persists a Refund row, and returns a RefundResult (demo mode)', function () {
    $billable = billableForRefund();
    $charge = attachRefundableCharge($billable);

    $result = $billable->refund('chg_refund_1');

    expect($result)->toBeInstanceOf(RefundResult::class)
        ->and($result->status)->toBe(RefundStatus::Succeeded);

    $refund = Refund::where('charge_id', $charge->id)->firstOrFail();
    expect($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->reference)->toBe($result->reference);
});

it('forwards a partial refund amount and persists it on the Refund row', function () {
    $billable = billableForRefund();
    attachRefundableCharge($billable);

    $result = $billable->refund('chg_refund_1', amountMinor: 1500);

    expect($result->amountMinor)->toBe(1500)
        ->and(Refund::where('reference', $result->reference)->firstOrFail()->amount_minor)->toBe(1500);
});

it('throws GmbPayException when the reference does not belong to this billable — no Refund row inserted', function () {
    $billable = billableForRefund();

    expect(fn () => $billable->refund('chg_does_not_exist'))
        ->toThrow(GmbPayException::class, 'chg_does_not_exist');

    expect(Refund::count())->toBe(0);
});

it('surfaces the BadMethodCallException Modempay raises in non-demo mode (F16 blocked)', function () {
    config(['gmb-pay.demo_mode' => false]);

    $billable = billableForRefund();
    attachRefundableCharge($billable);

    expect(fn () => $billable->refund('chg_refund_1'))
        ->toThrow(BadMethodCallException::class);
});
