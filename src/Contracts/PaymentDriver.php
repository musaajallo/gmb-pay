<?php

declare(strict_types=1);

namespace Africs\GmbPay\Contracts;

use Africs\GmbPay\DataObjects\ChargeRequest;
use Africs\GmbPay\DataObjects\ChargeResult;
use Africs\GmbPay\DataObjects\PayoutRequest;
use Africs\GmbPay\DataObjects\PayoutResult;
use Africs\GmbPay\DataObjects\RefundRequest;
use Africs\GmbPay\DataObjects\RefundResult;
use Africs\GmbPay\DataObjects\WebhookEvent;
use Illuminate\Http\Request;

interface PaymentDriver
{
    public function name(): string;

    public function charge(ChargeRequest $request): ChargeResult;

    public function verify(string $reference): ChargeResult;

    public function refund(RefundRequest $request): RefundResult;

    public function payout(PayoutRequest $request): PayoutResult;

    public function webhookSignatureValid(Request $request): bool;

    public function parseWebhook(Request $request): WebhookEvent;
}
