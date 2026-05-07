<?php

declare(strict_types=1);

namespace Africs\GmbPay\Http\Controllers;

use Africs\GmbPay\Exceptions\InvalidWebhookSignatureException;
use Africs\GmbPay\Exceptions\UnknownDriverException;
use Africs\GmbPay\PaymentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WebhookController extends Controller
{
    public function __construct(
        protected PaymentManager $payments,
    ) {}

    public function handle(Request $request, string $driver): JsonResponse
    {
        try {
            $resolved = $this->payments->driver($driver);
        } catch (UnknownDriverException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        if (! $resolved->webhookSignatureValid($request)) {
            throw new InvalidWebhookSignatureException("Invalid webhook signature for driver [{$driver}].");
        }

        $event = $resolved->parseWebhook($request);

        event(new \Africs\GmbPay\Events\WebhookReceived($event));

        return response()->json(['received' => true]);
    }
}
