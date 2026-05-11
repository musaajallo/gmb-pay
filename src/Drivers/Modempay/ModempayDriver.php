<?php

declare(strict_types=1);

namespace Africs\GmbPay\Drivers\Modempay;

use Africs\GmbPay\DataObjects\ChargeRequest;
use Africs\GmbPay\DataObjects\ChargeResult;
use Africs\GmbPay\Drivers\AbstractDriver;
use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Exceptions\GmbPayException;
use Illuminate\Support\Str;

class ModempayDriver extends AbstractDriver
{
    public function __construct(
        array $config = [],
        private readonly ?ModempayClient $client = null,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'modempay';
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        if ($this->isDemo()) {
            return parent::charge($request);
        }

        $payload = array_filter([
            'amount' => $request->amountMinor,
            'currency' => $request->currency,
            'description' => $request->description,
            'return_url' => $request->returnUrl,
            'cancel_url' => $request->callbackUrl,
            'metadata' => $request->metadata !== [] ? $request->metadata : null,
            'from_sdk' => false,
        ], static fn ($value) => $value !== null);

        $response = $this->client()->request('POST', '/v1/payments', ['data' => $payload]);

        if (! $response->successful()) {
            $body = $response->json();
            $message = is_array($body) && isset($body['message']) && is_string($body['message'])
                ? $body['message']
                : $response->body();

            throw new GmbPayException(sprintf(
                'Modempay charge failed (HTTP %d): %s',
                $response->status(),
                $message,
            ));
        }

        $data = (array) ($response->json('data') ?? []);
        $paymentLink = (string) ($data['payment_link'] ?? '');

        return new ChargeResult(
            reference: 'chg_' . Str::random(20),
            status: $this->statusFromModempay((string) ($data['status'] ?? '')),
            amountMinor: (int) ($data['amount'] ?? $request->amountMinor),
            currency: (string) ($data['currency'] ?? $request->currency),
            checkoutUrl: $paymentLink !== '' ? $paymentLink : null,
            providerReference: $paymentLink !== '' ? Str::afterLast($paymentLink, '/') : null,
            raw: $data,
        );
    }

    private function client(): ModempayClient
    {
        return $this->client ?? new ModempayClient(
            baseUrl: (string) ($this->config['base_url'] ?? 'https://api.modempay.com'),
            secretKey: (string) ($this->config['secret_key'] ?? ''),
            timeoutSeconds: (int) ($this->config['timeout_seconds'] ?? 30),
        );
    }

    private function statusFromModempay(string $status): ChargeStatus
    {
        return match ($status) {
            'successful' => ChargeStatus::Succeeded,
            'failed' => ChargeStatus::Failed,
            'cancelled' => ChargeStatus::Cancelled,
            default => ChargeStatus::Pending,
        };
    }
}
