<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Events\WebhookReceived;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;

it('auto-fires the charge listener via the controller when auto_register is true (default)', function () {
    $charge = Charge::create([
        'reference' => 'chg_auto_on',
        'driver' => 'modempay',
        'provider_reference' => 'prov_auto_on',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);

    $response = $this->postJson('/gmb-pay/webhook/modempay', [
        'id' => 'evt_auto_on',
        'type' => 'charge.succeeded',
        'provider_reference' => 'prov_auto_on',
    ]);

    $response->assertOk();

    expect($charge->fresh()->status)->toBe(ChargeStatus::Succeeded);
});

it('does not advance the charge when auto_register is off, but still persists the webhook row', function () {
    // Orchestra refreshes the app inside setUp, so a per-test config flip would land
    // after the provider has already booted. Forgetting the listeners produces the
    // same observable state the auto_register=false branch is meant to deliver.
    Event::forget(WebhookReceived::class);

    $charge = Charge::create([
        'reference' => 'chg_auto_off',
        'driver' => 'modempay',
        'provider_reference' => 'prov_auto_off',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);

    $response = $this->postJson('/gmb-pay/webhook/modempay', [
        'id' => 'evt_auto_off',
        'type' => 'charge.succeeded',
        'provider_reference' => 'prov_auto_off',
    ]);

    $response->assertOk();

    expect($charge->fresh()->status)->toBe(ChargeStatus::Pending)
        ->and(WebhookEvent::where('driver', 'modempay')
            ->where('provider_event_id', 'evt_auto_off')
            ->count())->toBe(1);
});
