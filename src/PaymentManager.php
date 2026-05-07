<?php

declare(strict_types=1);

namespace Africs\GmbPay;

use Africs\GmbPay\Contracts\PaymentDriver;
use Africs\GmbPay\Drivers\Modempay\ModempayDriver;
use Africs\GmbPay\Drivers\Wave\WaveDriver;
use Africs\GmbPay\Drivers\Waychit\WaychitDriver;
use Africs\GmbPay\Exceptions\UnknownDriverException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Manager;

/**
 * @method \Africs\GmbPay\DataObjects\ChargeResult charge(\Africs\GmbPay\DataObjects\ChargeRequest $request)
 * @method \Africs\GmbPay\DataObjects\ChargeResult verify(string $reference)
 * @method \Africs\GmbPay\DataObjects\RefundResult refund(\Africs\GmbPay\DataObjects\RefundRequest $request)
 * @method \Africs\GmbPay\DataObjects\PayoutResult payout(\Africs\GmbPay\DataObjects\PayoutRequest $request)
 */
class PaymentManager extends Manager
{
    public function __construct(Container $container)
    {
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
}
