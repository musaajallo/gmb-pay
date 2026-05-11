<?php

declare(strict_types=1);

use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Exceptions\GmbPayException;
use Africs\GmbPay\Facades\GmbPay;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function modempayVerifyResponse(string $status = 'successful', bool $wrapped = true, ?string $link = 'https://test.checkout.modempay.com/8d4cf2a1-1111-2222-3333-444455556666'): array
{
    $data = [
        'status' => $status,
        'amount' => 5000,
        'currency' => 'GMD',
        'description' => 'Order #42',
        'link' => $link,
    ];

    return $wrapped ? ['status' => true, 'message' => 'OK', 'data' => $data] : $data;
}

beforeEach(function () {
    config([
        'gmb-pay.demo_mode' => false,
        'gmb-pay.drivers.modempay.secret_key' => 'sk_test_abc123',
        'gmb-pay.drivers.modempay.base_url' => 'https://api.modempay.com',
    ]);
});

it('sends a GET to /v1/payments/verify with the intent_secret as a query param and Bearer auth', function () {
    Http::fake(['*' => Http::response(modempayVerifyResponse(), 200)]);

    GmbPay::driver('modempay')->verify('pi_secret_abc123');

    Http::assertSent(function (Request $req) {
        return $req->method() === 'GET'
            && $req->url() === 'https://api.modempay.com/v1/payments/verify?intent_secret=pi_secret_abc123'
            && $req->hasHeader('Authorization', 'Bearer sk_test_abc123');
    });
});

it('maps a data-wrapped 2xx response to a ChargeResult', function () {
    Http::fake(['*' => Http::response(modempayVerifyResponse(status: 'successful'), 200)]);

    $result = GmbPay::driver('modempay')->verify('pi_secret_abc123');

    expect($result->status)->toBe(ChargeStatus::Succeeded)
        ->and($result->amountMinor)->toBe(5000)
        ->and($result->currency)->toBe('GMD')
        ->and($result->reference)->toBe('pi_secret_abc123')
        ->and($result->checkoutUrl)->toBe('https://test.checkout.modempay.com/8d4cf2a1-1111-2222-3333-444455556666');
});

it('maps every Modempay verify status to a ChargeStatus', function (string $modempayStatus, ChargeStatus $expected) {
    Http::fake(['*' => Http::response(modempayVerifyResponse(status: $modempayStatus), 200)]);

    $result = GmbPay::driver('modempay')->verify('pi_secret_abc123');

    expect($result->status)->toBe($expected);
})->with([
    'initialized' => ['initialized', ChargeStatus::Pending],
    'processing' => ['processing', ChargeStatus::Pending],
    'successful' => ['successful', ChargeStatus::Succeeded],
    'failed' => ['failed', ChargeStatus::Failed],
    'cancelled' => ['cancelled', ChargeStatus::Cancelled],
]);

it('also parses a flat (non-wrapped) response body', function () {
    Http::fake(['*' => Http::response(modempayVerifyResponse(status: 'successful', wrapped: false), 200)]);

    $result = GmbPay::driver('modempay')->verify('pi_secret_abc123');

    expect($result->status)->toBe(ChargeStatus::Succeeded)
        ->and($result->amountMinor)->toBe(5000)
        ->and($result->currency)->toBe('GMD');
});

it('throws GmbPayException when verify returns a 4xx', function () {
    Http::fake(['*' => Http::response(['status' => false, 'message' => 'Intent not found'], 404)]);

    expect(fn () => GmbPay::driver('modempay')->verify('pi_secret_missing'))
        ->toThrow(GmbPayException::class, 'Intent not found');
});

it('returns the demo-mode stub and makes no HTTP call when demo_mode is on', function () {
    config(['gmb-pay.demo_mode' => true]);
    Http::fake();

    $result = GmbPay::driver('modempay')->verify('pi_secret_abc123');

    expect($result->status)->toBe(ChargeStatus::Succeeded)
        ->and($result->reference)->toBe('pi_secret_abc123');

    Http::assertNothingSent();
});
