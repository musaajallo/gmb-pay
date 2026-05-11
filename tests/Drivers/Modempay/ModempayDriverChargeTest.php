<?php

declare(strict_types=1);

use Africs\GmbPay\DataObjects\ChargeRequest;
use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Exceptions\GmbPayException;
use Africs\GmbPay\Facades\GmbPay;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function modempaySuccessResponse(string $status = 'requires_payment_method', string $paymentLink = 'https://test.checkout.modempay.com/8d4cf2a1-1111-2222-3333-444455556666'): array
{
    return [
        'status' => true,
        'message' => 'Payment intent created successfully.',
        'data' => [
            'intent_secret' => 'pi_secret_abc123',
            'payment_link' => $paymentLink,
            'amount' => 5000,
            'currency' => 'GMD',
            'expires_at' => '2030-01-01T00:00:00.000Z',
            'status' => $status,
        ],
    ];
}

function modempayChargeRequest(): ChargeRequest
{
    return new ChargeRequest(
        amountMinor: 5000,
        currency: 'GMD',
        customerPhone: '+2203000000',
        description: 'Order #42',
        returnUrl: 'https://merchant.test/success',
        metadata: ['order_id' => 42],
    );
}

beforeEach(function () {
    config([
        'gmb-pay.demo_mode' => false,
        'gmb-pay.drivers.modempay.secret_key' => 'sk_test_abc123',
        'gmb-pay.drivers.modempay.base_url' => 'https://api.modempay.com',
    ]);
});

it('sends a POST to /v1/payments with the body wrapped in data and Bearer auth', function () {
    Http::fake([
        '*' => Http::response(modempaySuccessResponse(), 200),
    ]);

    GmbPay::driver('modempay')->charge(modempayChargeRequest());

    Http::assertSent(function (Request $req) {
        return $req->url() === 'https://api.modempay.com/v1/payments'
            && $req->method() === 'POST'
            && $req->hasHeader('Authorization', 'Bearer sk_test_abc123')
            && is_array($req['data'] ?? null)
            && $req['data']['amount'] === 5000
            && $req['data']['currency'] === 'GMD'
            && $req['data']['description'] === 'Order #42'
            && $req['data']['return_url'] === 'https://merchant.test/success'
            && $req['data']['metadata'] === ['order_id' => 42]
            && $req['data']['from_sdk'] === false;
    });
});

it('maps the Modempay response to a ChargeResult with checkoutUrl and provider reference', function () {
    Http::fake([
        '*' => Http::response(modempaySuccessResponse(
            status: 'requires_payment_method',
            paymentLink: 'https://test.checkout.modempay.com/8d4cf2a1-1111-2222-3333-444455556666',
        ), 200),
    ]);

    $result = GmbPay::driver('modempay')->charge(modempayChargeRequest());

    expect($result->checkoutUrl)->toBe('https://test.checkout.modempay.com/8d4cf2a1-1111-2222-3333-444455556666')
        ->and($result->providerReference)->toBe('8d4cf2a1-1111-2222-3333-444455556666')
        ->and($result->amountMinor)->toBe(5000)
        ->and($result->currency)->toBe('GMD')
        ->and($result->status)->toBe(ChargeStatus::Pending)
        ->and($result->reference)->toStartWith('chg_');
});

it('maps every Modempay status to a ChargeStatus', function (string $modempayStatus, ChargeStatus $expected) {
    Http::fake([
        '*' => Http::response(modempaySuccessResponse(status: $modempayStatus), 200),
    ]);

    $result = GmbPay::driver('modempay')->charge(modempayChargeRequest());

    expect($result->status)->toBe($expected);
})->with([
    'requires_payment_method' => ['requires_payment_method', ChargeStatus::Pending],
    'processing' => ['processing', ChargeStatus::Pending],
    'successful' => ['successful', ChargeStatus::Succeeded],
    'failed' => ['failed', ChargeStatus::Failed],
    'cancelled' => ['cancelled', ChargeStatus::Cancelled],
]);

it('throws GmbPayException when Modempay returns a 4xx', function () {
    Http::fake([
        '*' => Http::response(['status' => false, 'message' => 'Invalid currency'], 422),
    ]);

    expect(fn () => GmbPay::driver('modempay')->charge(modempayChargeRequest()))
        ->toThrow(GmbPayException::class, 'Invalid currency');
});

it('returns the demo-mode stub and makes no HTTP call when demo_mode is on', function () {
    config(['gmb-pay.demo_mode' => true]);
    Http::fake();

    $result = GmbPay::driver('modempay')->charge(modempayChargeRequest());

    expect($result->checkoutUrl)->toStartWith('https://demo.local/checkout/');

    Http::assertNothingSent();
});
