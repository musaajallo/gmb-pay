# TODO

## Active: F13 — ModempayClient HTTP wrapper

**Goal:** A pre-configured HTTP client for Modempay so F14–F19 driver methods can call `$client->request('POST', '/path', $body)` without re-wiring auth, timeouts, or logging. No Modempay endpoint paths are baked in at this layer — those land with each feature (F14 charge, F15 verify, F16 refund, etc).

### Steps

1. **RED — write the test first** at `tests/Drivers/Modempay/ModempayClientTest.php`:
   - With `Http::fake()`, send a `POST /payment-intents` with body `['amount' => 5000]`. Assert (a) `Authorization: Bearer sk_test_abc123` header is set; (b) `Accept: application/json`; (c) `Content-Type: application/json`; (d) URL resolves to `https://api.modempay.test/payment-intents`; (e) body is sent as JSON (`$req['amount'] === 5000`)
   - Returns the `Illuminate\Http\Client\Response` so callers can inspect status / body
   - When `app()->detectEnvironment(fn () => 'local')` is in effect, `Log::spy()` records a `debug` entry for both the request and the response. When the env is `testing` (default), `Log::spy()` records nothing
   - Confirm fails first (no class yet)
2. **Implement** `src/Drivers/Modempay/ModempayClient.php`:
   - Namespace `Africs\GmbPay\Drivers\Modempay`
   - Constructor: `__construct(private readonly string $baseUrl, private readonly string $secretKey, private readonly int $timeoutSeconds = 30)`
   - `public function request(string $method, string $path, array $body = []): Response`
     - Build via `Http::baseUrl($baseUrl)->withToken($secretKey)->acceptJson()->asJson()->timeout($timeoutSeconds)`
     - Pre-call: if `app()->isLocal()`, `Log::debug('[modempay] request', ['method' => $method, 'path' => $path, 'body' => $body])`
     - Send via `->send($method, $path, ['json' => $body])`
     - Post-call: if `app()->isLocal()`, `Log::debug('[modempay] response', ['status' => $response->status(), 'body' => $response->json() ?? $response->body()])`
     - Return `$response`
3. Run `vendor/bin/pest`. Tick F13, append done.md entry, commit `F13: ModempayClient HTTP wrapper`

### Files this feature will touch

- `src/Drivers/Modempay/ModempayClient.php` (new)
- `tests/Drivers/Modempay/ModempayClientTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the new cases above)
- `ModempayClient` is constructible in isolation — F14 can `new ModempayClient(...)` or resolve via container without any prior service-provider edits in this feature
- No Modempay endpoint paths exist in the class body; those land with F14+

### Notes for the implementer

- Use the `Illuminate\Support\Facades\Http` facade so `Http::fake()` works in tests
- `Http::withToken($key)` produces `Authorization: Bearer $key`. Don't roll the header by hand
- `Log::spy()` returns a spy that records every call to `Log::debug`/`info`/etc. Assert with `Log::shouldHaveReceived('debug')->twice()` (request + response) for the local-env case; `Log::shouldNotHaveReceived('debug')` for the testing-env case
- Container binding is **deferred to F14**. F13 just delivers the class; F14 will decide whether to bind it as a singleton or build it inside `ModempayDriver::__construct` from the driver config array

---

## Blocked

_(features paused mid-implementation — none yet)_
