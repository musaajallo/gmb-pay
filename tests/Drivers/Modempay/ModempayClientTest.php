<?php

declare(strict_types=1);

use Africs\GmbPay\Drivers\Modempay\ModempayClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);
});

function makeModempayTestClient(?string $secret = 'sk_test_abc123', ?string $baseUrl = 'https://api.modempay.test'): ModempayClient
{
    return new ModempayClient(
        baseUrl: $baseUrl,
        secretKey: $secret,
    );
}

it('sends Bearer auth and JSON content-type/accept headers', function () {
    $client = makeModempayTestClient();

    $client->request('POST', '/payment-intents', ['amount' => 5000]);

    Http::assertSent(function (Request $req) {
        return $req->hasHeader('Authorization', 'Bearer sk_test_abc123')
            && $req->hasHeader('Accept', 'application/json')
            && $req->hasHeader('Content-Type', 'application/json');
    });
});

it('composes the base URL with the request path', function () {
    $client = makeModempayTestClient();

    $client->request('POST', '/payment-intents', []);

    Http::assertSent(fn (Request $req) => $req->url() === 'https://api.modempay.test/payment-intents');
});

it('sends the body as JSON for POST', function () {
    $client = makeModempayTestClient();

    $client->request('POST', '/payment-intents', ['amount' => 5000, 'currency' => 'GMD']);

    Http::assertSent(fn (Request $req) => $req['amount'] === 5000 && $req['currency'] === 'GMD');
});

it('returns the Illuminate HTTP Response so callers can inspect status and body', function () {
    $client = makeModempayTestClient();

    $response = $client->request('POST', '/payment-intents', []);

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->status())->toBe(200)
        ->and($response->json())->toBe(['ok' => true]);
});

it('logs request and response at debug level when the app is local', function () {
    Log::spy();
    $this->app->detectEnvironment(fn () => 'local');

    $client = makeModempayTestClient();
    $client->request('POST', '/payment-intents', ['amount' => 5000]);

    Log::shouldHaveReceived('debug')->twice();
});

it('does not log requests when the app is in the testing env', function () {
    Log::spy();

    $client = makeModempayTestClient();
    $client->request('POST', '/payment-intents', ['amount' => 5000]);

    Log::shouldNotHaveReceived('debug');
});
