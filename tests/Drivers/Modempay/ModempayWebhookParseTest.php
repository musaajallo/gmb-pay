<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Enums\WebhookEventType;
use Africs\GmbPay\Facades\GmbPay;
use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\WebhookEvent as WebhookEventModel;
use Illuminate\Http\Request;

function modempayWrappedPayload(string $event = 'charge.succeeded', string $resourceId = '23419194-7324-4c2b-a74b-d8fba736e692', string $paymentIntentId = 'pi_abc123'): array
{
    return [
        'event' => $event,
        'payload' => [
            'id' => $resourceId,
            'payment_intent_id' => $paymentIntentId,
            'amount' => 5000,
            'currency' => 'GMD',
            'status' => 'successful',
            'customer_phone' => '+2203000000',
            'metadata' => ['order_id' => 42],
        ],
    ];
}

function modempayParseRequest(array $body): Request
{
    $request = Request::create('/gmb-pay/webhook/modempay', 'POST', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
    $request->headers->set('Content-Type', 'application/json');

    return $request;
}

it('parses a wrapped charge.succeeded payload into a WebhookEvent', function () {
    $body = modempayWrappedPayload();
    $request = modempayParseRequest($body);

    $event = GmbPay::driver('modempay')->parseWebhook($request);

    expect($event->type)->toBe(WebhookEventType::ChargeSucceeded)
        ->and($event->driver)->toBe('modempay')
        ->and($event->providerReference)->toBe('pi_abc123')
        ->and($event->providerEventId)->toBe('charge.succeeded:23419194-7324-4c2b-a74b-d8fba736e692')
        ->and($event->payload)->toBe($body);
});

it('maps Modempay event strings to WebhookEventType', function (string $modempayEvent, WebhookEventType $expected) {
    $request = modempayParseRequest(modempayWrappedPayload(event: $modempayEvent));

    expect(GmbPay::driver('modempay')->parseWebhook($request)->type)->toBe($expected);
})->with([
    'charge.succeeded' => ['charge.succeeded', WebhookEventType::ChargeSucceeded],
    'charge.cancelled' => ['charge.cancelled', WebhookEventType::ChargeCancelled],
    'charge.expired' => ['charge.expired', WebhookEventType::ChargeFailed],
    'payment_intent.expired' => ['payment_intent.expired', WebhookEventType::ChargeFailed],
    'payment_intent.cancelled' => ['payment_intent.cancelled', WebhookEventType::ChargeCancelled],
    'transfer.succeeded' => ['transfer.succeeded', WebhookEventType::PayoutSucceeded],
    'transfer.failed' => ['transfer.failed', WebhookEventType::PayoutFailed],
    'transfer.cancelled' => ['transfer.cancelled', WebhookEventType::PayoutFailed],
    'customer.created' => ['customer.created', WebhookEventType::Unknown],
    'charge.created' => ['charge.created', WebhookEventType::Unknown],
]);

it('end-to-end: a wrapped charge.succeeded POST advances a matching local charge', function () {
    $charge = Charge::create([
        'reference' => 'chg_e2e',
        'driver' => 'modempay',
        'provider_reference' => 'pi_e2e_xyz',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'status' => ChargeStatus::Pending,
    ]);

    $body = modempayWrappedPayload(event: 'charge.succeeded', resourceId: 'res_e2e_1', paymentIntentId: 'pi_e2e_xyz');

    $this->postJson('/gmb-pay/webhook/modempay', $body)->assertOk();

    expect($charge->fresh()->status)->toBe(ChargeStatus::Succeeded);

    $row = WebhookEventModel::where('driver', 'modempay')
        ->where('provider_event_id', 'charge.succeeded:res_e2e_1')
        ->firstOrFail();

    expect($row->type)->toBe(WebhookEventType::ChargeSucceeded);
});

it('falls through to the flat-shape parser for legacy bodies', function () {
    $request = modempayParseRequest(['id' => 'evt_legacy', 'type' => 'charge.succeeded']);

    $event = GmbPay::driver('modempay')->parseWebhook($request);

    expect($event->type)->toBe(WebhookEventType::ChargeSucceeded)
        ->and($event->providerEventId)->toBe('evt_legacy');
});
