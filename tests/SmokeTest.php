<?php

declare(strict_types=1);

use Africs\GmbPay\DataObjects\ChargeRequest;
use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Exceptions\UnknownDriverException;
use Africs\GmbPay\Facades\GmbPay;
use Africs\GmbPay\PaymentManager;

it('boots the service provider and binds the manager', function () {
    expect(app(PaymentManager::class))->toBeInstanceOf(PaymentManager::class);
});

it('resolves each driver by name', function (string $driver) {
    expect(GmbPay::driver($driver)->name())->toBe($driver);
})->with(['modempay', 'wave', 'waychit']);

it('returns a pending demo charge when demo_mode is on', function () {
    $result = GmbPay::driver('modempay')->charge(new ChargeRequest(
        amountMinor: 5000,
        currency: 'GMD',
        customerPhone: '+2203000000',
    ));

    expect($result->status)->toBe(ChargeStatus::Pending)
        ->and($result->checkoutUrl)->toStartWith('https://demo.local/checkout/');
});

it('throws on unknown drivers', function () {
    GmbPay::driver('definitely-not-a-driver');
})->throws(UnknownDriverException::class);
