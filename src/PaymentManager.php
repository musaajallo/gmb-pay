<?php

declare(strict_types=1);

namespace Africs\GmbPay;

use Africs\GmbPay\Contracts\PaymentDriver;
use Africs\GmbPay\DataObjects\ChargeRequest;
use Africs\GmbPay\DataObjects\ChargeResult;
use Africs\GmbPay\Drivers\Modempay\ModempayDriver;
use Africs\GmbPay\Drivers\Wave\WaveDriver;
use Africs\GmbPay\Drivers\Waychit\WaychitDriver;
use Africs\GmbPay\Exceptions\UnknownDriverException;
use Africs\GmbPay\Idempotency\IdempotencyStore;
use Africs\GmbPay\Models\Charge;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Manager;

/**
 * @method \Africs\GmbPay\DataObjects\ChargeResult verify(string $reference)
 * @method \Africs\GmbPay\DataObjects\RefundResult refund(\Africs\GmbPay\DataObjects\RefundRequest $request)
 * @method \Africs\GmbPay\DataObjects\PayoutResult payout(\Africs\GmbPay\DataObjects\PayoutRequest $request)
 */
class PaymentManager extends Manager
{
    public function __construct(
        Container $container,
        private readonly IdempotencyStore $idempotency,
    ) {
        parent::__construct($container);
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('gmb-pay.default', 'modempay');
    }

    public function driver($driver = null): PaymentDriver
    {
        /** @var PaymentDriver $resolved */
        $resolved = parent::driver($driver);

        return $resolved;
    }

    public function charge(ChargeRequest $request, ?string $driverName = null): ChargeResult
    {
        $driver = $this->driver($driverName);

        if ($request->idempotencyKey === null) {
            return $driver->charge($request);
        }

        /** @var Charge $charge */
        $charge = $this->idempotency->remember(
            $driver->name(),
            $request->idempotencyKey,
            fn (): Charge => $this->persistChargeFromResult($driver->name(), $request, $driver->charge($request)),
        );

        return $this->resultFromCharge($charge);
    }

    protected function createModempayDriver(): PaymentDriver
    {
        return new ModempayDriver(
            config: (array) $this->config->get('gmb-pay.drivers.modempay', []),
        );
    }

    protected function createWaveDriver(): PaymentDriver
    {
        return new WaveDriver(
            config: (array) $this->config->get('gmb-pay.drivers.wave', []),
        );
    }

    protected function createWaychitDriver(): PaymentDriver
    {
        return new WaychitDriver(
            config: (array) $this->config->get('gmb-pay.drivers.waychit', []),
        );
    }

    protected function createDriver($driver)
    {
        try {
            return parent::createDriver($driver);
        } catch (\InvalidArgumentException $e) {
            throw new UnknownDriverException(
                "Unknown gmb-pay driver [{$driver}]. Available: modempay, wave, waychit.",
                previous: $e,
            );
        }
    }

    private function persistChargeFromResult(string $driver, ChargeRequest $request, ChargeResult $result): Charge
    {
        return Charge::create([
            'reference' => $result->reference,
            'driver' => $driver,
            'provider_reference' => $result->providerReference,
            'amount_minor' => $result->amountMinor,
            'currency' => $result->currency,
            'status' => $result->status,
            'metadata' => array_merge($request->metadata, [
                '_gmbpay_checkout_url' => $result->checkoutUrl,
                '_gmbpay_failure_reason' => $result->failureReason,
                '_gmbpay_raw' => $result->raw,
            ]),
        ]);
    }

    private function resultFromCharge(Charge $charge): ChargeResult
    {
        $metadata = (array) ($charge->metadata ?? []);

        return new ChargeResult(
            reference: (string) $charge->reference,
            status: $charge->status,
            amountMinor: (int) $charge->amount_minor,
            currency: (string) $charge->currency,
            checkoutUrl: $metadata['_gmbpay_checkout_url'] ?? null,
            providerReference: $charge->provider_reference,
            failureReason: $metadata['_gmbpay_failure_reason'] ?? null,
            raw: (array) ($metadata['_gmbpay_raw'] ?? []),
        );
    }
}
