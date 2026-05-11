<?php

declare(strict_types=1);

namespace Africs\GmbPay\Drivers\Modempay;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ModempayClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secretKey,
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function request(string $method, string $path, array $body = []): Response
    {
        $debug = (bool) app()->isLocal();

        if ($debug) {
            Log::debug('[modempay] request', [
                'method' => $method,
                'path' => $path,
                'body' => $body,
            ]);
        }

        $response = Http::baseUrl($this->baseUrl)
            ->withToken($this->secretKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds)
            ->send($method, $path, ['json' => $body]);

        if ($debug) {
            Log::debug('[modempay] response', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);
        }

        return $response;
    }
}
