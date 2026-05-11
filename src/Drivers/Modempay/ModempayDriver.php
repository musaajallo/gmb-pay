<?php

declare(strict_types=1);

namespace Africs\GmbPay\Drivers\Modempay;

use Africs\GmbPay\DataObjects\ChargeRequest;
use Africs\GmbPay\DataObjects\ChargeResult;
use Africs\GmbPay\DataObjects\PayoutRequest;
use Africs\GmbPay\DataObjects\PayoutResult;
use Africs\GmbPay\Drivers\AbstractDriver;
use Africs\GmbPay\Enums\ChargeStatus;
use Africs\GmbPay\Enums\PayoutStatus;
use Africs\GmbPay\Exceptions\GmbPayException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
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

        $this->throwIfNotSuccessful($response, 'charge');

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

    public function verify(string $reference): ChargeResult
    {
        if ($this->isDemo()) {
            return parent::verify($reference);
        }

        $response = $this->client()->request('GET', '/v1/payments/verify?intent_secret=' . urlencode($reference));

        $this->throwIfNotSuccessful($response, 'verify');

        $data = $response->json('data');
        if (! is_array($data)) {
            $data = (array) ($response->json() ?? []);
        }

        $link = is_string($data['link'] ?? null) ? $data['link'] : null;

        return new ChargeResult(
            reference: $reference,
            status: $this->statusFromModempay((string) ($data['status'] ?? '')),
            amountMinor: (int) ($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? (string) config('gmb-pay.currency', 'GMD')),
            checkoutUrl: $link,
            providerReference: null,
            raw: $data,
        );
    }

    public function payout(PayoutRequest $request): PayoutResult
    {
        if ($this->isDemo()) {
            return parent::payout($request);
        }

        $network = $request->metadata['network'] ?? null;
        if (! is_string($network) || $network === '') {
            throw new GmbPayException('Modempay payout requires metadata["network"] (mobile-money provider code).');
        }

        $body = array_filter([
            'amount' => $request->amountMinor,
            'currency' => $request->currency,
            'network' => $network,
            'account_number' => $request->recipientPhone,
            'beneficiary_name' => $request->recipientName,
            'narration' => $request->description,
            'metadata' => $request->metadata,
        ], static fn ($value) => $value !== null);

        $response = $this->client()->request('POST', '/v1/transfers', $body);

        $this->throwIfNotSuccessful($response, 'payout');

        $data = $response->json('data');
        if (! is_array($data)) {
            $data = (array) ($response->json() ?? []);
        }

        return new PayoutResult(
            reference: 'pyt_' . Str::random(20),
            status: $this->statusFromModempayPayout((string) ($data['status'] ?? '')),
            amountMinor: (int) ($data['amount'] ?? $request->amountMinor),
            currency: (string) ($data['currency'] ?? $request->currency),
            providerReference: isset($data['id']) && is_string($data['id']) ? $data['id'] : null,
            raw: $data,
        );
    }

    public function webhookSignatureValid(Request $request): bool
    {
        if ($this->isDemo()) {
            return true;
        }

        $secret = (string) ($this->config['webhook_secret'] ?? '');
        if ($secret === '') {
            return false;
        }

        $provided = $request->header('x-modem-signature');
        if (! is_string($provided) || $provided === '') {
            return false;
        }

        $computed = hash_hmac('sha512', $request->getContent(), $secret);

        return hash_equals($computed, $provided);
    }

    private function statusFromModempayPayout(string $status): PayoutStatus
    {
        return match ($status) {
            'completed' => PayoutStatus::Succeeded,
            'failed' => PayoutStatus::Failed,
            'cancelled' => PayoutStatus::Cancelled,
            default => PayoutStatus::Pending,
        };
    }

    private function throwIfNotSuccessful(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        $body = $response->json();
        $message = is_array($body) && isset($body['message']) && is_string($body['message'])
            ? $body['message']
            : $response->body();

        throw new GmbPayException(sprintf(
            'Modempay %s failed (HTTP %d): %s',
            $operation,
            $response->status(),
            $message,
        ));
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
