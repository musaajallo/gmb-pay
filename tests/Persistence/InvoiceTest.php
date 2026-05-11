<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Enums\InvoiceStatus;
use Africs\GmbPay\Enums\PlanInterval;
use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\Invoice;
use Africs\GmbPay\Models\Plan;
use Africs\GmbPay\Models\Subscription;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;
use Illuminate\Support\Facades\Schema;

function makeInvoiceSubscription(): Subscription
{
    $billable = FakeBillable::create(['name' => 'Invoice Billable']);

    $plan = Plan::create([
        'slug' => 'invoice-plan-'.uniqid(),
        'name' => 'Invoice Plan',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Month,
    ]);

    return Subscription::create([
        'billable_type' => FakeBillable::class,
        'billable_id' => $billable->id,
        'plan_id' => $plan->id,
        'driver' => 'modempay',
        'status' => SubscriptionStatus::Active,
    ]);
}

it('creates the gmb_pay_invoices table when migrations run', function () {
    expect(Schema::hasTable('gmb_pay_invoices'))->toBeTrue();

    expect(Schema::hasColumns('gmb_pay_invoices', [
        'id',
        'subscription_id',
        'charge_id',
        'amount_minor',
        'currency',
        'status',
        'period_start',
        'period_end',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('persists an Invoice with casts and accepts a null charge_id', function () {
    $sub = makeInvoiceSubscription();
    $start = now()->startOfSecond();

    Invoice::create([
        'subscription_id' => $sub->id,
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => InvoiceStatus::Open,
        'period_start' => $start,
        'period_end' => $start->copy()->addMonth(),
    ]);

    $fetched = Invoice::firstOrFail();

    expect($fetched->status)->toBe(InvoiceStatus::Open)
        ->and($fetched->amount_minor)->toBe(5000)
        ->and($fetched->charge_id)->toBeNull()
        ->and($fetched->period_start->equalTo($start))->toBeTrue()
        ->and($fetched->period_end->equalTo($start->copy()->addMonth()))->toBeTrue();
});

it('resolves subscription(), charge(), and Subscription::invoices() relations', function () {
    $sub = makeInvoiceSubscription();

    $charge = Charge::create([
        'reference' => 'chg_inv_1',
        'driver' => 'modempay',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Succeeded,
    ]);

    $invoice = Invoice::create([
        'subscription_id' => $sub->id,
        'charge_id' => $charge->id,
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => InvoiceStatus::Paid,
        'period_start' => now(),
        'period_end' => now()->addMonth(),
    ]);

    expect($invoice->subscription)->toBeInstanceOf(Subscription::class)
        ->and($invoice->subscription->is($sub))->toBeTrue()
        ->and($invoice->charge)->toBeInstanceOf(Charge::class)
        ->and($invoice->charge->is($charge))->toBeTrue()
        ->and($sub->fresh()->invoices)->toHaveCount(1);
});

it('cascades on Subscription delete and nulls on Charge delete', function () {
    $sub = makeInvoiceSubscription();

    $charge = Charge::create([
        'reference' => 'chg_inv_cascade',
        'driver' => 'modempay',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Succeeded,
    ]);

    Invoice::create([
        'subscription_id' => $sub->id,
        'charge_id' => $charge->id,
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => InvoiceStatus::Paid,
        'period_start' => now(),
        'period_end' => now()->addMonth(),
    ]);

    $charge->delete();

    expect(Invoice::count())->toBe(1)
        ->and(Invoice::first()->charge_id)->toBeNull();

    $sub->delete();

    expect(Invoice::count())->toBe(0);
});
