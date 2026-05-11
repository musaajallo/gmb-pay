# TODO

## Active: F19 — ModempayDriver::parseWebhook for wrapped payloads

**Goal:** Override `AbstractDriver::parseWebhook()` on `ModempayDriver` so the wrapped `{"event": "<type>", "payload": {...}}` shape Modempay actually sends gets decoded correctly — populating `WebhookEvent::$type` from the event string, `providerReference` from the inner `payment_intent_id` (matches what F14 stores in `Charge::$provider_reference` so F08/F09 listeners reconcile), and `providerEventId` as a composite `"<event>:<payload.id>"` so two distinct lifecycle events for the same resource (e.g. `charge.created` then `charge.succeeded`) don't collide on F07's `(driver, provider_event_id)` unique index.

**Sample wire payload (literally from `https://docs.modempay.com/documentation/core/webhooks`):**

```json
{
  "event": "charge.succeeded",
  "payload": {
    "id": "23419194-7324-4c2b-a74b-d8fba736e692",
    "payment_intent_id": "...",
    "amount": 5000,
    "currency": "GMD",
    "status": "successful",
    "...": "..."
  }
}
```

There is no separate top-level webhook event id (e.g. no `evt_...` like Stripe). Modempay's payload.id is the resource id, not a webhook event id — hence the composite dedup key.

### Modempay → WebhookEventType map

| Modempay event | Our enum |
|---|---|
| `charge.succeeded` | `ChargeSucceeded` |
| `charge.cancelled` | `ChargeCancelled` |
| `charge.expired` | `ChargeFailed` |
| `payment_intent.cancelled` | `ChargeCancelled` |
| `payment_intent.expired` | `ChargeFailed` |
| `transfer.succeeded` | `PayoutSucceeded` |
| `transfer.failed` | `PayoutFailed` |
| `transfer.cancelled` | `PayoutFailed` |
| `transfer.reversed` | `PayoutFailed` |
| everything else (`customer.*`, `charge.created`, `charge.updated`, `transfer.flagged`, etc.) | `Unknown` |

### Steps

1. **RED — write the test first** at `tests/Drivers/Modempay/ModempayWebhookParseTest.php`:
   - Test (a): new-shape `charge.succeeded` payload → `WebhookEvent` with `type === ChargeSucceeded`, `providerReference === payload.payment_intent_id`, `providerEventId === "charge.succeeded:<payload.id>"`, `payload === $entireBody`
   - Test (b): event-mapping data set covering at least `charge.expired`, `payment_intent.expired`, `transfer.succeeded`, `transfer.failed`, `customer.created` (→ Unknown)
   - Test (c): end-to-end — POST a new-shape `charge.succeeded` body to `/gmb-pay/webhook/modempay` (in demo mode so signature passes); assert a `WebhookEvent` row with the composite `provider_event_id` is persisted and a pre-existing pending Charge (matched by `payment_intent_id`) advances to `Succeeded` via the F10 auto-registered listener
   - Test (d): **flat shape backward-compat** — POSTing the old `{"id":"evt_x","type":"charge.succeeded"}` shape still parses via `AbstractDriver::parseWebhook()` (the F07/F08/F09/F10 existing tests rely on this — don't break them). Assert the resulting `WebhookEvent` row has `provider_event_id === "evt_x"`
2. **Implement** `ModempayDriver::parseWebhook(Request $request): WebhookEvent`:
   - If `$body['event']` is a non-empty string AND `$body['payload']` is an array → wrapped branch:
     - `$event = $body['event']; $inner = $body['payload'];`
     - `$type = $this->webhookEventTypeFromModempay($event);` (private match-table helper, returns `WebhookEventType::Unknown` for anything not listed)
     - `$resourceId = is_string($inner['id'] ?? null) ? $inner['id'] : null;`
     - `$providerEventId = $resourceId !== null ? "{$event}:{$resourceId}" : null;`
     - `$providerReference = is_string($inner['payment_intent_id'] ?? null) ? $inner['payment_intent_id'] : null;`
     - Return `new WebhookEvent(type, driver: $this->name(), providerReference, payload: $body, providerEventId)`
   - Else fall through to `parent::parseWebhook($request)` for backward compat (flat shape used by existing tests + generic providers)
3. Run `vendor/bin/pest`. Tick F19, append done.md entry, commit `F19: ModempayDriver::parseWebhook for wrapped payloads`. With F19 done, Phase D closes (except the blocked F16).

### Files this feature will touch

- `src/Drivers/Modempay/ModempayDriver.php` (modified — adds `parseWebhook()` override + private `webhookEventTypeFromModempay()` helper)
- `tests/Drivers/Modempay/ModempayWebhookParseTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the new cases above)
- Existing `tests/Webhook/WebhookPersistenceTest.php` and `tests/Webhook/AutoRegisterListenersTest.php` (which still POST the flat shape) keep passing untouched
- End-to-end test (c) proves a real Modempay-shape webhook drives the F08 listener correctly via `payment_intent_id` reconciliation

### Notes for the implementer

- The composite `provider_event_id = "<event>:<payload.id>"` is necessary because Modempay's payload.id is the resource id, not a webhook event id — two distinct lifecycle events for the same charge would otherwise collide on F07's unique index and silently swallow the second event
- Wrapping detection is the *type* of `payload`, not just its presence — Modempay's `payload` is always an object; if it's anything else (string, missing, scalar), treat the body as flat shape and fall through
- The "Unknown" cases (`charge.created`, `charge.updated`, `customer.*`, `transfer.flagged`) are intentionally noisy in the persisted row but produce no listener side-effects (F08's listener `match`es only the four meaningful charge events; everything else is a no-op `default => null`)

**Goal:** Override `AbstractDriver::webhookSignatureValid()` on `ModempayDriver` so the existing `WebhookController` path only accepts requests carrying a valid HMAC-SHA512 of the raw body under header `x-modem-signature`. Demo mode keeps returning `true` (parity with AbstractDriver) so tests and local dev don't need real signatures.

**Spec correction:** The original F18 line says "HMAC-SHA256". Per `https://docs.modempay.com/documentation/core/webhooks`, Modempay actually uses **HMAC-SHA512** with header `x-modem-signature`. F18 implements the SHA512 variant.

### Steps

1. **RED — write the test first** at `tests/Drivers/Modempay/ModempayWebhookSignatureTest.php`:
   - Helper: `hash_hmac('sha512', $body, $secret)` returns the expected signature
   - Test (a): a `POST` request carrying the correct `x-modem-signature` for its raw body → `webhookSignatureValid()` returns `true`
   - Test (b): wrong signature → `false`
   - Test (c): missing `x-modem-signature` header → `false`
   - Test (d): empty `webhook_secret` config → `false` (don't accidentally accept random requests in a misconfigured install)
   - Test (e): demo mode (`gmb-pay.demo_mode = true`) → `true` regardless of header
2. **Implement** `ModempayDriver::webhookSignatureValid(Request $request): bool`:
   - Demo branch first
   - `$secret = $this->config['webhook_secret'] ?? ''` — return `false` if empty
   - `$provided = $request->header('x-modem-signature')` — return `false` if null
   - `$computed = hash_hmac('sha512', $request->getContent(), $secret)` — over the **raw** body, not parsed
   - `return hash_equals($computed, (string) $provided)` — constant-time comparison
3. Run `vendor/bin/pest`. Tick F18, append done.md entry, commit `F18: Modempay webhook signature verification (HMAC-SHA512)`

### Files this feature will touch

- `src/Drivers/Modempay/ModempayDriver.php` (modified — adds `webhookSignatureValid()` override)
- `tests/Drivers/Modempay/ModempayWebhookSignatureTest.php` (new)
- `tasks/all-features.md` (check the box — and update the F18 line text to read HMAC-SHA512)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the new cases above)
- The driver reads the **raw** body via `$request->getContent()`, never `$request->all()` (parsed JSON canonicalization differs and would break the hash)
- Constant-time comparison via `hash_equals` is used; no `==` or `===` on the signature strings

### Notes for the implementer

- Build the test request via `Illuminate\Http\Request::create('/gmb-pay/webhook/modempay', 'POST', [], [], [], [], $rawBody)` so `getContent()` returns exactly the bytes you signed. `headers->set('x-modem-signature', $sig)` sets the header
- F19 (parseWebhook) is next — Modempay's payload is `{"event": "...", "payload": {...}}`, not the flat shape `AbstractDriver::parseWebhook()` assumes. F19 will override

**Goal:** Send mobile-money payouts through Modempay's `POST /v1/transfers` endpoint. Demo mode keeps the AbstractDriver stub; non-demo mode posts a flat (not `data`-wrapped) body and maps the response to a `PayoutResult`. F16 is blocked on Modempay's missing public refund endpoint — F17 keeps moving Phase D forward in the meantime.

**Modempay endpoint (per `https://docs.modempay.com/documentation/payouts/mobile-money`):**

- `POST https://api.modempay.com/v1/transfers`
- **Body is flat** (unlike `/v1/payments` which is wrapped in `"data"`)
- Required: `amount`, `currency`, `network`, `account_number`, `beneficiary_name`
- Optional: `narration`, `metadata`, `callback_url`
- Response fields include `id`, `status`, `transfer_reference`, `amount`, `currency`, `fee`, `account_number`, `network`, `account_name`, `events`, etc.
- Status vocabulary: `pending`, `completed`, `failed`, `cancelled`

### Steps

1. **PayoutStatus enum** (`src/Enums/PayoutStatus.php`):
   - Add `case Cancelled = 'cancelled';` — Modempay can return it. Pre-1.0, no migration concerns
2. **RED — write the test first** at `tests/Drivers/Modempay/ModempayDriverPayoutTest.php`:
   - `beforeEach`: turn off demo mode, set `secret_key`/`base_url` config
   - Test (a): POST to `https://api.modempay.com/v1/transfers` with a **flat** body containing `amount`, `currency`, `network` (pulled from `metadata['network']`), `account_number === recipientPhone`, `beneficiary_name === recipientName`, `narration === description`, `metadata` (with `network` key still present). Bearer auth
   - Test (b): status mapping data set — `pending → Pending`, `completed → Succeeded`, `failed → Failed`, `cancelled → Cancelled`. `PayoutResult.providerReference` comes from response `id`; `reference` is locally generated (`'pyt_' . Str::random(20)`)
   - Test (c): omitting `metadata['network']` raises a `GmbPayException` with a message naming the missing field (does not call out to Modempay)
   - Test (d): 4xx surfaces as `GmbPayException` via the shared `throwIfNotSuccessful()` helper
   - Test (e): demo mode falls through to `AbstractDriver::payout()` (PayoutResult with `Succeeded` + `'demo_payout_...'` reference) and makes zero HTTP calls
3. **Implement** `ModempayDriver::payout(PayoutRequest $request): PayoutResult`:
   - Demo branch first
   - Pull `$network = $request->metadata['network'] ?? null`. If null/empty, `throw new GmbPayException('Modempay payout requires metadata["network"] (mobile-money provider code).');`
   - Build flat body — note this is **not** wrapped in `data`
   - POST via the client, route 4xx through `throwIfNotSuccessful($response, 'payout')`
   - Parse response: `$data` may be wrapped in `data` or flat; tolerate both like F15
   - Map status via private `statusFromModempayPayout(string): PayoutStatus`
   - Return `PayoutResult` with `reference: 'pyt_' . Str::random(20)`, `providerReference: (string) ($data['id'] ?? '')`, `amountMinor`, `currency`, `raw: $data`
4. Run `vendor/bin/pest`. Tick F17, append done.md entry, commit `F17: ModempayDriver::payout() via /v1/transfers`

### Files this feature will touch

- `src/Enums/PayoutStatus.php` (modified — add `Cancelled`)
- `src/Drivers/Modempay/ModempayDriver.php` (modified — adds `payout()` override + private `statusFromModempayPayout()`)
- `tests/Drivers/Modempay/ModempayDriverPayoutTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the new cases above)
- The body sent on the wire is **flat** (not `data`-wrapped)
- A `PayoutRequest` without `metadata['network']` raises `GmbPayException` *before* any HTTP call (assert `Http::assertNothingSent()`)
- Demo-mode path is unchanged

### Notes for the implementer

- **The `network` field is Modempay-specific.** Our `PayoutRequest` DTO doesn't have it as a top-level property (other drivers may not need it). Funnel it through `metadata['network']` for now and document the requirement in the exception message. If Wave/Waychit also turn out to need a similar field, F46+ can decide whether to promote it to the DTO
- Response wrapping: the docs don't show a literal example for `/v1/transfers`. Tolerate both shapes like F15 does (`$response->json('data') ?? $response->json() ?? []`)
- The PaymentDriver capability-split clause in F17's original spec (`SupportsPayouts` interface) is **not exercised here** — Modempay supports payouts, so we just implement it. The split becomes worth doing when a non-payout driver lands (Waychit?) — defer to that feature
- F18 (webhook signature) is next: remember to use HMAC-**SHA512** with header `x-modem-signature` per `https://docs.modempay.com/documentation/core/webhooks`, not SHA256 from the original plan

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

### F16 — ModempayDriver::refund()

**Blocked by:** Modempay's public docs (`https://docs.modempay.com`) document a `refunded` transaction status but **no API endpoint to create a refund**. `/documentation/core/transactions` only exposes `GET /v1/transactions/{id}` and `GET /v1/transactions`. The payment-intents management page only documents `PATCH /v1/payments/<id>` to **cancel** an intent (pre-capture), not to refund a completed one.

**Unblock conditions:**
- Modempay publishes a refund endpoint, OR
- Merchant onboarding surfaces an undocumented endpoint we can verify against, OR
- We accept refunds-via-dashboard-only and ship `ModempayDriver::refund()` as a `BadMethodCallException` override with a Modempay-specific message (degraded scope; would still leave the F16 box unchecked since the feature spec was a real API call)

**When unblocked:** drop in here, write a `tests/Drivers/Modempay/ModempayDriverRefundTest.php` mirroring the F14/F15 shape, override `ModempayDriver::refund()`, tick F16, commit `F16: ModempayDriver::refund() real implementation`.
