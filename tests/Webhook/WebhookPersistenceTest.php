<?php

declare(strict_types=1);

use Africs\GmbPay\Events\WebhookReceived;
use Africs\GmbPay\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([WebhookReceived::class]);
});

it('persists a webhook row and dispatches the event', function () {
    $response = $this->postJson('/gmb-pay/webhook/modempay', [
        'id' => 'evt_persist_1',
        'type' => 'charge.succeeded',
    ]);

    $response->assertOk();

    expect(WebhookEvent::where('driver', 'modempay')
        ->where('provider_event_id', 'evt_persist_1')
        ->count())->toBe(1);

    Event::assertDispatchedTimes(WebhookReceived::class, 1);
});

it('deduplicates a repeated provider_event_id without re-dispatching', function () {
    $payload = ['id' => 'evt_dup_1', 'type' => 'charge.succeeded'];

    $this->postJson('/gmb-pay/webhook/modempay', $payload)->assertOk();
    $this->postJson('/gmb-pay/webhook/modempay', $payload)->assertOk();

    expect(WebhookEvent::where('provider_event_id', 'evt_dup_1')->count())->toBe(1);

    Event::assertDispatchedTimes(WebhookReceived::class, 1);
});

it('treats payloads without a provider_event_id as distinct', function () {
    $this->postJson('/gmb-pay/webhook/modempay', ['type' => 'charge.failed'])->assertOk();
    $this->postJson('/gmb-pay/webhook/modempay', ['type' => 'charge.failed'])->assertOk();

    expect(WebhookEvent::whereNull('provider_event_id')->count())->toBe(2);

    Event::assertDispatchedTimes(WebhookReceived::class, 2);
});
