<?php

declare(strict_types=1);

use Africs\GmbPay\DataObjects\WebhookEvent as WebhookEventDto;
use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Enums\WebhookEventType;
use Africs\GmbPay\Events\WebhookReceived;
use Africs\GmbPay\Listeners\UpdateChargeFromWebhook;
use Africs\GmbPay\Models\Charge;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::listen(WebhookReceived::class, UpdateChargeFromWebhook::class);
});

function dispatchChargeWebhook(WebhookEventType $type, string $providerRef, string $driver = 'modempay'): void
{
    event(new WebhookReceived(new WebhookEventDto(
        type: $type,
        driver: $driver,
        providerReference: $providerRef,
    )));
}

function makePendingCharge(string $providerRef, string $driver = 'modempay'): Charge
{
    return Charge::create([
        'reference' => "chg_local_{$driver}_{$providerRef}",
        'provider_reference' => $providerRef,
        'driver' => $driver,
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);
}

it('updates a matching charge to Succeeded on charge.succeeded', function () {
    $charge = makePendingCharge('prov_ok');

    dispatchChargeWebhook(WebhookEventType::ChargeSucceeded, 'prov_ok');

    expect($charge->fresh()->status)->toBe(ChargeStatus::Succeeded);
});

it('updates a matching charge to Failed on charge.failed', function () {
    $charge = makePendingCharge('prov_fail');

    dispatchChargeWebhook(WebhookEventType::ChargeFailed, 'prov_fail');

    expect($charge->fresh()->status)->toBe(ChargeStatus::Failed);
});

it('updates a matching charge to Cancelled on charge.cancelled', function () {
    $charge = makePendingCharge('prov_cancel');

    dispatchChargeWebhook(WebhookEventType::ChargeCancelled, 'prov_cancel');

    expect($charge->fresh()->status)->toBe(ChargeStatus::Cancelled);
});

it('is a no-op when no matching local charge exists', function () {
    $countBefore = Charge::count();

    dispatchChargeWebhook(WebhookEventType::ChargeSucceeded, 'prov_unknown');

    expect(Charge::count())->toBe($countBefore);
});

it('ignores refund.* and unknown events', function () {
    $charge = makePendingCharge('prov_ignore');

    event(new WebhookReceived(new WebhookEventDto(
        type: WebhookEventType::RefundSucceeded,
        driver: 'modempay',
        providerReference: 'prov_ignore',
    )));

    event(new WebhookReceived(new WebhookEventDto(
        type: WebhookEventType::Unknown,
        driver: 'modempay',
        providerReference: 'prov_ignore',
    )));

    expect($charge->fresh()->status)->toBe(ChargeStatus::Pending);
});

it('does not update charges from a different driver with the same provider_reference', function () {
    $modempayCharge = makePendingCharge('prov_shared', 'modempay');
    $waveCharge = makePendingCharge('prov_shared', 'wave');

    dispatchChargeWebhook(WebhookEventType::ChargeSucceeded, 'prov_shared', 'modempay');

    expect($modempayCharge->fresh()->status)->toBe(ChargeStatus::Succeeded)
        ->and($waveCharge->fresh()->status)->toBe(ChargeStatus::Pending);
});
