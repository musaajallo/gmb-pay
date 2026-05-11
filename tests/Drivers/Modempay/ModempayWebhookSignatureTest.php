<?php

declare(strict_types=1);

use Africs\GmbPay\Facades\GmbPay;
use Illuminate\Http\Request;

function modempaySign(string $body, string $secret): string
{
    return hash_hmac('sha512', $body, $secret);
}

function modempayWebhookRequest(string $body, ?string $signature): Request
{
    $request = Request::create('/gmb-pay/webhook/modempay', 'POST', [], [], [], [], $body);

    if ($signature !== null) {
        $request->headers->set('x-modem-signature', $signature);
    }

    return $request;
}

beforeEach(function () {
    config([
        'gmb-pay.demo_mode' => false,
        'gmb-pay.drivers.modempay.webhook_secret' => 'whsec_test_abc123',
    ]);
});

it('accepts a request with a valid HMAC-SHA512 signature', function () {
    $body = '{"event":"charge.succeeded","payload":{"id":"evt_1"}}';
    $request = modempayWebhookRequest($body, modempaySign($body, 'whsec_test_abc123'));

    expect(GmbPay::driver('modempay')->webhookSignatureValid($request))->toBeTrue();
});

it('rejects a request with a wrong signature', function () {
    $body = '{"event":"charge.succeeded","payload":{"id":"evt_1"}}';
    $request = modempayWebhookRequest($body, 'definitely-not-the-right-signature');

    expect(GmbPay::driver('modempay')->webhookSignatureValid($request))->toBeFalse();
});

it('rejects a request missing the x-modem-signature header', function () {
    $body = '{"event":"charge.succeeded","payload":{"id":"evt_1"}}';
    $request = modempayWebhookRequest($body, signature: null);

    expect(GmbPay::driver('modempay')->webhookSignatureValid($request))->toBeFalse();
});

it('rejects every request when webhook_secret is not configured', function () {
    config(['gmb-pay.drivers.modempay.webhook_secret' => '']);

    $body = '{"event":"charge.succeeded","payload":{"id":"evt_1"}}';
    $request = modempayWebhookRequest($body, modempaySign($body, 'whsec_test_abc123'));

    expect(GmbPay::driver('modempay')->webhookSignatureValid($request))->toBeFalse();
});

it('accepts any signature in demo mode (parity with AbstractDriver)', function () {
    config(['gmb-pay.demo_mode' => true]);

    $request = modempayWebhookRequest('{"event":"anything"}', 'bogus');

    expect(GmbPay::driver('modempay')->webhookSignatureValid($request))->toBeTrue();
});
