<?php

declare(strict_types=1);

use Africs\GmbPay\DataObjects\WebhookEvent as WebhookEventDto;
use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Enums\RefundStatus;
use Africs\GmbPay\Enums\WebhookEventType;
use Africs\GmbPay\Events\WebhookReceived;
use Africs\GmbPay\Listeners\UpdateRefundFromWebhook;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\Refund;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::listen(WebhookReceived::class, UpdateRefundFromWebhook::class);
});

function makeRefund(string $providerRef, string $driver = 'modempay'): Refund
{
    $charge = Charge::create([
        'reference' => "chg_for_refund_{$driver}_{$providerRef}",
        'driver' => $driver,
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Succeeded,
    ]);

    return Refund::create([
        'charge_id' => $charge->id,
        'reference' => "rfd_local_{$driver}_{$providerRef}",
        'provider_reference' => $providerRef,
        'amount_minor' => 1000,
        'status' => RefundStatus::Pending,
    ]);
}

function dispatchRefundWebhook(WebhookEventType $type, string $providerRef, string $driver = 'modempay'): void
{
    event(new WebhookReceived(new WebhookEventDto(
        type: $type,
        driver: $driver,
        providerReference: $providerRef,
    )));
}

it('updates a matching refund to Succeeded on refund.succeeded', function () {
    $refund = makeRefund('rfd_ok');

    dispatchRefundWebhook(WebhookEventType::RefundSucceeded, 'rfd_ok');

    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded);
});

it('updates a matching refund to Failed on refund.failed', function () {
    $refund = makeRefund('rfd_bad');

    dispatchRefundWebhook(WebhookEventType::RefundFailed, 'rfd_bad');

    expect($refund->fresh()->status)->toBe(RefundStatus::Failed);
});

it('is a no-op when no matching local refund exists', function () {
    $countBefore = Refund::count();

    dispatchRefundWebhook(WebhookEventType::RefundSucceeded, 'rfd_unknown');

    expect(Refund::count())->toBe($countBefore);
});

it('ignores charge.* and unknown events', function () {
    $refund = makeRefund('rfd_ignore');

    event(new WebhookReceived(new WebhookEventDto(
        type: WebhookEventType::ChargeSucceeded,
        driver: 'modempay',
        providerReference: 'rfd_ignore',
    )));

    event(new WebhookReceived(new WebhookEventDto(
        type: WebhookEventType::Unknown,
        driver: 'modempay',
        providerReference: 'rfd_ignore',
    )));

    expect($refund->fresh()->status)->toBe(RefundStatus::Pending);
});

it('does not update refunds whose parent charge is on a different driver', function () {
    $modempayRefund = makeRefund('rfd_shared', 'modempay');
    $waveRefund = makeRefund('rfd_shared', 'wave');

    dispatchRefundWebhook(WebhookEventType::RefundSucceeded, 'rfd_shared', 'modempay');

    expect($modempayRefund->fresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($waveRefund->fresh()->status)->toBe(RefundStatus::Pending);
});
