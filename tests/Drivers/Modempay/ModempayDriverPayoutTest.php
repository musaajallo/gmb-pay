<?php

declare(strict_types=1);

use Africs\GmbPay\DataObjects\PayoutRequest;
use Africs\GmbPay\Enums\PayoutStatus;
use Africs\GmbPay\Exceptions\GmbPayException;
use Africs\GmbPay\Facades\GmbPay;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function modempayPayoutRequest(array $metadata = ['network' => 'africell']): PayoutRequest
{
    return new PayoutRequest(
        amountMinor: 5000,
        currency: 'GMD',
        recipientPhone: '+2207000000',
        recipientName: 'Awa Touray',
        description: 'Vendor payout #17',
        metadata: $metadata,
    );
}

function modempayTransferResponse(string $status = 'pending', string $id = 'tr_abc123'): array
{
    return [
        'status' => true,
        'message' => 'Transfer created',
        'data' => [
            'id' => $id,
            'transfer_reference' => 'TRF-2030-0001',
            'status' => $status,
            'amount' => 5000,
            'currency' => 'GMD',
            'network' => 'africell',
            'account_number' => '+2207000000',
            'account_name' => 'Awa Touray',
        ],
    ];
}

beforeEach(function () {
    config([
        'gmb-pay.demo_mode' => false,
        'gmb-pay.drivers.modempay.secret_key' => 'sk_test_abc123',
        'gmb-pay.drivers.modempay.base_url' => 'https://api.modempay.com',
    ]);
});

it('POSTs to /v1/transfers with a flat body including network from metadata', function () {
    Http::fake(['*' => Http::response(modempayTransferResponse(), 200)]);

    GmbPay::driver('modempay')->payout(modempayPayoutRequest());

    Http::assertSent(function (Request $req) {
        return $req->url() === 'https://api.modempay.com/v1/transfers'
            && $req->method() === 'POST'
            && $req->hasHeader('Authorization', 'Bearer sk_test_abc123')
            && $req['amount'] === 5000
            && $req['currency'] === 'GMD'
            && $req['network'] === 'africell'
            && $req['account_number'] === '+2207000000'
            && $req['beneficiary_name'] === 'Awa Touray'
            && $req['narration'] === 'Vendor payout #17'
            && ($req['data'] ?? null) === null;
    });
});

it('maps every Modempay payout status to a PayoutStatus', function (string $modempayStatus, PayoutStatus $expected) {
    Http::fake(['*' => Http::response(modempayTransferResponse(status: $modempayStatus), 200)]);

    $result = GmbPay::driver('modempay')->payout(modempayPayoutRequest());

    expect($result->status)->toBe($expected)
        ->and($result->providerReference)->toBe('tr_abc123')
        ->and($result->reference)->toStartWith('pyt_')
        ->and($result->amountMinor)->toBe(5000)
        ->and($result->currency)->toBe('GMD');
})->with([
    'pending' => ['pending', PayoutStatus::Pending],
    'completed' => ['completed', PayoutStatus::Succeeded],
    'failed' => ['failed', PayoutStatus::Failed],
    'cancelled' => ['cancelled', PayoutStatus::Cancelled],
]);

it('throws GmbPayException with a clear message when metadata[network] is missing — no HTTP call', function () {
    Http::fake();

    expect(fn () => GmbPay::driver('modempay')->payout(modempayPayoutRequest(metadata: [])))
        ->toThrow(GmbPayException::class, 'metadata["network"]');

    Http::assertNothingSent();
});

it('surfaces a Modempay 4xx as a GmbPayException', function () {
    Http::fake(['*' => Http::response(['status' => false, 'message' => 'Insufficient balance'], 422)]);

    expect(fn () => GmbPay::driver('modempay')->payout(modempayPayoutRequest()))
        ->toThrow(GmbPayException::class, 'Insufficient balance');
});

it('returns the demo-mode stub and makes no HTTP call when demo_mode is on', function () {
    config(['gmb-pay.demo_mode' => true]);
    Http::fake();

    $result = GmbPay::driver('modempay')->payout(modempayPayoutRequest());

    expect($result->status)->toBe(PayoutStatus::Succeeded)
        ->and($result->reference)->toStartWith('demo_payout_');

    Http::assertNothingSent();
});
