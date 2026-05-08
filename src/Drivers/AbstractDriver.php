<?php

declare(strict_types=1);

namespace Africs\GmbPay\Drivers;

use Africs\GmbPay\Contracts\PaymentDriver;
use Africs\GmbPay\DataObjects\ChargeRequest;
use Africs\GmbPay\DataObjects\ChargeResult;
use Africs\GmbPay\DataObjects\PayoutRequest;
use Africs\GmbPay\DataObjects\PayoutResult;
use Africs\GmbPay\DataObjects\RefundRequest;
use Africs\GmbPay\DataObjects\RefundResult;
use Africs\GmbPay\DataObjects\WebhookEvent;
use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Enums\PayoutStatus;
use Africs\GmbPay\Enums\RefundStatus;
use Africs\GmbPay\Enums\WebhookEventType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class AbstractDriver implements PaymentDriver
{
    public function __construct(
        protected array $config = [],
    ) {}

    public function isDemo(): bool
    {
        return (bool) (config('gmb-pay.demo_mode') ?? false);
    }

    abstract public function name(): string;

    public function charge(ChargeRequest $request): ChargeResult
    {
        if ($this->isDemo()) {
            return new ChargeResult(
                reference: 'demo_' . Str::random(16),
                status: ChargeStatus::Pending,
                amountMinor: $request->amountMinor,
                currency: $request->currency,
                checkoutUrl: 'https://demo.local/checkout/' . Str::random(8),
            );
        }

        throw $this->notImplemented(__FUNCTION__);
    }

    public function verify(string $reference): ChargeResult
    {
        if ($this->isDemo()) {
            return new ChargeResult(
                reference: $reference,
                status: ChargeStatus::Succeeded,
                amountMinor: 0,
                currency: (string) config('gmb-pay.currency', 'GMD'),
            );
        }

        throw $this->notImplemented(__FUNCTION__);
    }

    public function refund(RefundRequest $request): RefundResult
    {
        if ($this->isDemo()) {
            return new RefundResult(
                reference: 'demo_refund_' . Str::random(12),
                status: RefundStatus::Succeeded,
                amountMinor: $request->amountMinor ?? 0,
                currency: (string) config('gmb-pay.currency', 'GMD'),
            );
        }

        throw $this->notImplemented(__FUNCTION__);
    }

    public function payout(PayoutRequest $request): PayoutResult
    {
        if ($this->isDemo()) {
            return new PayoutResult(
                reference: 'demo_payout_' . Str::random(12),
                status: PayoutStatus::Succeeded,
                amountMinor: $request->amountMinor,
                currency: $request->currency,
            );
        }

        throw $this->notImplemented(__FUNCTION__);
    }

    public function webhookSignatureValid(Request $request): bool
    {
        if ($this->isDemo()) {
            return true;
        }

        return false;
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $payload = (array) $request->all();
        $rawType = is_string($payload['type'] ?? null) ? $payload['type'] : null;
        $type = $rawType !== null
            ? (WebhookEventType::tryFrom($rawType) ?? WebhookEventType::Unknown)
            : WebhookEventType::Unknown;

        $providerEventId = is_string($payload['id'] ?? null) ? $payload['id'] : null;

        return new WebhookEvent(
            type: $type,
            driver: $this->name(),
            payload: $payload,
            providerEventId: $providerEventId,
        );
    }

    protected function notImplemented(string $method): \BadMethodCallException
    {
        return new \BadMethodCallException(
            sprintf(
                "[%s] %s() is not implemented yet. Set GMB_PAY_DEMO=true for stubbed responses, or wait for merchant onboarding.",
                static::class,
                $method,
            ),
        );
    }
}
