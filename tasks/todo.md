# TODO

## Active: F15 — ModempayDriver::verify() real implementation

**Goal:** Server-side verification of a Modempay payment intent after the customer returns from the hosted checkout. `verify($reference)` takes the **`intent_secret`** (Modempay's verifier token, returned in `ChargeResult.raw.intent_secret` when F14 created the intent) and returns a `ChargeResult` with the live provider status. Demo mode keeps using the AbstractDriver stub. 4xx → `GmbPayException`.

**Modempay endpoint (per `https://docs.modempay.com/documentation/payment-intents/management` — the docs only describe the response in prose, no literal JSON example; treat the response decoder as best-effort and tolerate both `data`-wrapped and flat shapes):**

- `GET /v1/payments/verify?intent_secret=<intent_secret>`
- Bearer auth (same as F14)
- 2xx response includes: `status`, `amount`, `currency`, `description`, `link`, `customer`. Wrapping in `"data"` is not confirmed for this endpoint — `create` uses `data` and `list` uses `data` + `meta`, so we assume `data` and fall back to top-level
- Status vocabulary (verify) per docs prose: `initialized`, `processing`, `successful` — plus the create endpoint's `requires_payment_method`, `failed`, `cancelled` are reachable in this state machine. Map any non-terminal value to `Pending`

### Steps

1. **RED — write the test first** at `tests/Drivers/Modempay/ModempayDriverVerifyTest.php`:
   - `beforeEach`: turn off demo mode, set `secret_key`/`base_url` config the same way the F14 test does
   - Test (a): driver sends `GET https://api.modempay.com/v1/payments/verify?intent_secret=pi_secret_abc123` with Bearer auth
   - Test (b): on a `data`-wrapped 2xx with `status: "successful", amount: 5000, currency: "GMD"`, `verify()` returns a `ChargeResult` with `status === Succeeded`, `amountMinor === 5000`, `currency === "GMD"`, `reference === "pi_secret_abc123"` (echo)
   - Test (c): status mapping for `initialized`, `processing`, `successful`, `failed`, `cancelled` (a `with([...])` data set — reuse the F14 mapping but add `initialized → Pending`)
   - Test (d): if the response is **flat** (no `data` wrapper), the driver still parses it correctly (forward-compat hedge)
   - Test (e): 4xx response throws `GmbPayException` with the message
   - Test (f): demo mode skips HTTP (`Http::assertNothingSent()`)
2. **Implement** `ModempayDriver::verify(string $reference): ChargeResult`:
   - Demo branch first: `if ($this->isDemo()) return parent::verify($reference);`
   - Else: `$response = $this->client()->request('GET', '/v1/payments/verify?intent_secret=' . urlencode($reference));`
   - On non-2xx, throw `GmbPayException` (same shape as F14's error mapping)
   - Decode: `$data = $response->json('data') ?? $response->json() ?? [];` — tolerate both shapes
   - Build `ChargeResult` with `reference: $reference` (echo), `status: $this->statusFromModempay(...)`, `amountMinor`, `currency`, `checkoutUrl: $data['link'] ?? null`, `providerReference: null` (verify doesn't expose the UUID), `raw: $data`
3. **No status-map changes needed** — F14's `statusFromModempay()` already falls through to `Pending` for any unknown string, which covers `initialized`
4. Run `vendor/bin/pest`. Tick F15, append done.md entry, commit `F15: ModempayDriver::verify() real implementation`

### Files this feature will touch

- `src/Drivers/Modempay/ModempayDriver.php` (modified — adds `verify()` override)
- `tests/Drivers/Modempay/ModempayDriverVerifyTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the new cases above)
- `verify($intent_secret)` in non-demo mode performs exactly one GET with the secret as a query param; demo mode performs zero HTTP calls and returns the AbstractDriver stub
- The driver tolerates both `data`-wrapped and flat response bodies — no test regressions if Modempay's verify endpoint turns out to be flat

### Notes for the implementer

- The argument name is `$reference` in the contract for consistency with the other drivers, but for Modempay it's specifically the `intent_secret` value
- The PI's UUID id (what F14 stores as `provider_reference`) is **not** what verify takes — that's a Modempay quirk: their `intent_secret` is a short-lived verifier, the UUID id is the long-lived resource id. F16 (refund/cancel) will go back to using the UUID against `PATCH /v1/payments/<payment_intent_id>`
- `urlencode($reference)` because intent_secret values can include URL-unsafe characters
- Discovery from this round that still needs follow-up later in Phase D / E:
  - Modempay webhook payloads are wrapped as `{"event": "<type>", "payload": {...}}`, not the flat `{"id", "type", ...}` shape F07's `AbstractDriver::parseWebhook()` assumes. F19 (`ModempayDriver::parseWebhook`) will override the abstract method
  - Modempay's signature is **HMAC-SHA512** (header `x-modem-signature`), not SHA256 as the original F18 line suggested. Update the F18 plan before implementing

**Goal:** Override `AbstractDriver::charge()` on `ModempayDriver` so a non-demo call POSTs `/v1/payments` against the live Modempay API, returning a `ChargeResult` with `checkoutUrl` populated. Demo mode (`GMB_PAY_DEMO=true`) keeps using the stubbed AbstractDriver path. 4xx responses raise `GmbPayException`.

**Modempay request/response shape (per `https://docs.modempay.com/documentation/payment-intents/create` — re-verify if it drifts):**

- POST `/v1/payments` against base URL `https://api.modempay.com`
- Body is wrapped: `{"data": {"amount": <minor>, "currency": "GMD", "description": ..., "return_url": ..., "cancel_url": ..., "metadata": ..., "from_sdk": false}}`. No `customer_phone/email/name` fields at this endpoint — those are Customer-resource concerns (F21)
- 2xx response is also wrapped: `{"data": {"intent_secret": "...", "payment_link": "https://test.checkout.modempay.com/<uuid>", "amount": ..., "currency": "GMD", "expires_at": "...", "status": "requires_payment_method"}}`
- Status vocabulary: `requires_payment_method`, `processing`, `successful`, `failed`, `cancelled`

### Steps

1. **RED — write the test first** at `tests/Drivers/Modempay/ModempayDriverChargeTest.php`:
   - `beforeEach`: turn off demo mode (`config(['gmb-pay.demo_mode' => false])`), `Http::fake()` returning the Modempay 2xx shape above
   - Test (a): driver sends a POST to `https://api.modempay.com/v1/payments` with body wrapped in `"data"`, including `amount`, `currency`, `description`, `return_url`, `metadata`, `from_sdk: false`. Bearer auth header from `secret_key` config
   - Test (b): driver returns a `ChargeResult` with `checkoutUrl === response.data.payment_link`, `providerReference` set to the last URL segment of `payment_link`, `amountMinor` and `currency` echoed from the request, `status` mapped from `requires_payment_method` → `ChargeStatus::Pending`
   - Test (c): status mapping — `successful` → Succeeded, `processing` → Pending, `failed` → Failed, `cancelled` → Cancelled. Use a data-provider style or four small `it()` blocks
   - Test (d): 4xx response throws `GmbPayException` with a message containing the response body
   - Test (e): demo-mode path is unchanged — `GMB_PAY_DEMO=true` returns the stubbed `https://demo.local/checkout/...` URL without making any HTTP call (assert `Http::assertNothingSent()`)
2. **Implement** `ModempayDriver::charge(ChargeRequest $request): ChargeResult`:
   - If `$this->isDemo()`: `return parent::charge($request);` (keeps the AbstractDriver demo path)
   - Else: build the `data` payload, POST via `$this->client->request('POST', '/v1/payments', ['data' => $payload])`, parse the response, return a `ChargeResult` mapped through a private `statusFromModempay(string): ChargeStatus` helper
   - Throw `Africs\GmbPay\Exceptions\GmbPayException` if `! $response->successful()` — include `$response->status()` and `$response->body()` in the message
   - Generate a local reference (`'chg_' . Str::random(20)`)
3. **Wire** `ModempayClient` into `ModempayDriver`:
   - Add `private readonly ModempayClient $client` constructor dep
   - Update `PaymentManager::createModempayDriver()` to build a `ModempayClient` from the modempay config block (`base_url`, `secret_key`, `timeout_seconds`)
   - Empty `secret_key` is fine for demo-mode tests since the driver never reaches the network there
4. Run `vendor/bin/pest`. Tick F14, append done.md entry, commit `F14: ModempayDriver::charge() real implementation`

### Files this feature will touch

- `src/Drivers/Modempay/ModempayDriver.php` (modified — adds `charge()` override + constructor)
- `src/PaymentManager.php` (modified — `createModempayDriver` builds a `ModempayClient`)
- `tests/Drivers/Modempay/ModempayDriverChargeTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the new cases above)
- `GMB_PAY_DEMO=true` path still returns `https://demo.local/checkout/...` and makes zero HTTP calls
- 4xx responses surface as `GmbPayException`, not silent failures

### Notes for the implementer

- The Modempay request body is **wrapped in `data`** — easy to miss when copying from a Stripe-style integration where the body is flat
- `customer_phone/email/name` from `ChargeRequest` are intentionally NOT forwarded to Modempay at this endpoint. Modempay's hosted checkout (`payment_link`) collects them itself; F21 will add a `/v1/customers` pre-create flow when a caller wants to associate a Modempay Customer UUID
- Provider reference extraction: `Str::afterLast($paymentLink, '/')` returns the UUID. If Modempay later exposes a top-level `id` field in this response, switch to that and migrate the existing rows in a separate task
- `from_sdk: false` is in the example payload — keep it explicit so the docs example matches what we send
- Status map for this feature lives inline in the driver. F15 (`verify`) will reuse it; if duplication appears we can extract to `ModempayStatusMap` then

---

## Blocked

_(features paused mid-implementation — none yet)_
