<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\WebhookEventType;
use Africs\GmbPay\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

it('creates the gmb_pay_webhook_events table when migrations run', function () {
    expect(Schema::hasTable('gmb_pay_webhook_events'))->toBeTrue();

    expect(Schema::hasColumns('gmb_pay_webhook_events', [
        'id',
        'driver',
        'provider_event_id',
        'type',
        'payload',
        'received_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('persists a webhook event with all required fields', function () {
    $row = WebhookEvent::create([
        'driver' => 'modempay',
        'provider_event_id' => 'evt_123',
        'type' => WebhookEventType::ChargeSucceeded,
        'payload' => ['id' => 'evt_123', 'data' => ['amount' => 5000]],
        'received_at' => Carbon::parse('2026-05-08 10:00:00'),
    ]);

    expect($row->exists)->toBeTrue()
        ->and($row->driver)->toBe('modempay')
        ->and($row->provider_event_id)->toBe('evt_123')
        ->and($row->type)->toBe(WebhookEventType::ChargeSucceeded);
});

it('casts type to the enum and payload to array on retrieval', function () {
    WebhookEvent::create([
        'driver' => 'wave',
        'provider_event_id' => 'evt_456',
        'type' => WebhookEventType::RefundSucceeded,
        'payload' => ['nested' => ['ok' => true]],
        'received_at' => now(),
    ]);

    $fetched = WebhookEvent::where('provider_event_id', 'evt_456')->firstOrFail();

    expect($fetched->type)->toBe(WebhookEventType::RefundSucceeded)
        ->and($fetched->payload)->toBe(['nested' => ['ok' => true]]);
});

it('round-trips received_at as a Carbon instance', function () {
    WebhookEvent::create([
        'driver' => 'modempay',
        'provider_event_id' => 'evt_789',
        'type' => WebhookEventType::Unknown,
        'payload' => [],
        'received_at' => Carbon::parse('2026-05-08 12:34:56'),
    ]);

    $fetched = WebhookEvent::where('provider_event_id', 'evt_789')->firstOrFail();

    expect($fetched->received_at)->toBeInstanceOf(Carbon::class)
        ->and($fetched->received_at->format('Y-m-d H:i:s'))->toBe('2026-05-08 12:34:56');
});

it('enforces uniqueness on (driver, provider_event_id)', function () {
    WebhookEvent::create([
        'driver' => 'modempay',
        'provider_event_id' => 'evt_dup',
        'type' => WebhookEventType::ChargeSucceeded,
        'payload' => [],
        'received_at' => now(),
    ]);

    expect(fn () => WebhookEvent::create([
        'driver' => 'modempay',
        'provider_event_id' => 'evt_dup',
        'type' => WebhookEventType::ChargeFailed,
        'payload' => [],
        'received_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('allows null provider_event_id and treats multiple nulls as distinct', function () {
    WebhookEvent::create([
        'driver' => 'modempay',
        'provider_event_id' => null,
        'type' => WebhookEventType::Unknown,
        'payload' => ['raw' => 'one'],
        'received_at' => now(),
    ]);

    WebhookEvent::create([
        'driver' => 'modempay',
        'provider_event_id' => null,
        'type' => WebhookEventType::Unknown,
        'payload' => ['raw' => 'two'],
        'received_at' => now(),
    ]);

    expect(WebhookEvent::whereNull('provider_event_id')->count())->toBe(2);
});
