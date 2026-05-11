# africs/gmb-pay — All Features

This file is the master plan. A fresh Claude session (or human) should be able to read this and resume implementation without re-asking decisions.

## Context

### Test runner
- **Run all tests:** `vendor/bin/pest`
- **Run one file:** `vendor/bin/pest tests/Path/To/Test.php`
- **Pest config:** `phpunit.xml` (sets `APP_ENV=testing` and `GMB_PAY_DEMO=true` by default)
- **Base test case:** `Africs\GmbPay\Tests\TestCase` — registers the service provider and the `GmbPay` facade alias via Orchestra Testbench

### Patterns (already in the scaffold — extend, don't re-invent)
- **Namespace:** `Africs\GmbPay\` (PSR-4 under `src/`); tests under `Africs\GmbPay\Tests\`
- **Strict types:** every PHP file starts with `declare(strict_types=1);`
- **DTOs:** `final readonly class` in `src/DataObjects/` (constructor promotion only, no setters)
- **Enums:** backed string enums in `src/Enums/` (PascalCase cases, kebab-case values when public-facing)
- **Drivers:** extend `Africs\GmbPay\Drivers\AbstractDriver`. Override only what's implemented; everything else falls back to demo-mode stubs in `AbstractDriver` or throws `BadMethodCallException` via `notImplemented()`
- **Manager:** add a new gateway by writing `protected function create<Name>Driver(): PaymentDriver` on `PaymentManager` and reading config from `gmb-pay.drivers.<name>`
- **Exceptions:** all custom exceptions inherit `Africs\GmbPay\Exceptions\GmbPayException`
- **Webhook routing:** `routes/webhooks.php` exposes `POST {prefix}/{driver}` → `WebhookController::handle`. Signature must validate before persistence
- **Events:** dispatch `Africs\GmbPay\Events\WebhookReceived` with a typed `WebhookEvent` payload
- **Currency:** GMD by default. Always use **minor units** (`amountMinor`) in code and DB; never floats
- **Demo mode:** `config('gmb-pay.demo_mode')` (env `GMB_PAY_DEMO=true`) makes every driver return stubbed success — used for local dev before merchant onboarding and as default in tests

### External docs to consult during implementation (use context7 when resumed)
- Laravel 13 — service provider, manager pattern, queues, scheduler, events/listeners, Eloquent, migrations, validation, blade
- Modempay — `https://docs.modempay.com` (only Phase-1 gateway with public docs)
- Wave (Senegal API as the closest reference) — `https://docs.wave.com` (Gambia API gated; will need merchant onboarding before F44)
- Waychit — no public docs, marketing only at `https://waychit.com/developers` (blocks F46)

### Reference features (read these before adding new ones)
- `src/PaymentManager.php` — how drivers are registered
- `src/Drivers/AbstractDriver.php` — fallback stubs, `notImplemented()` helper
- `src/Drivers/Modempay/ModempayDriver.php` — empty driver, the canonical extension point
- `src/Http/Controllers/WebhookController.php` — current shape of webhook handling
- `tests/SmokeTest.php` — current Pest test conventions

### Conventions for this plan
- **Feature size:** every feature is implementable in a single focused session. If a feature in this list grows past one file's worth of work, split it before starting
- **TDD:** every feature lands a Pest test first (RED), then implementation (GREEN), then re-runs the suite. See `superpowers:test-driven-development` if invoked
- **Commits:** one commit per completed feature, `F<NN>: <short description>` (no Co-Authored-By, no Claude footer — see `~/.claude/CLAUDE.md`)
- **Move features through:** unchecked here → active in `todo.md` → checked here + appended to `done.md`
- **Blocked features:** stay unchecked here; their detail block lives under `todo.md` `## Blocked`. The list below marks `(BLOCKED)` for visibility

---

## Features

### Phase A — Persistence layer

- [x] **F01** — Migration + Eloquent model for `gmb_pay_customers` (provider-agnostic; one row per Billable per driver, holds `provider_customer_id`)
- [x] **F02** — Migration + Eloquent model for `gmb_pay_charges` (`reference`, `provider_reference`, `driver`, `customer_id`, `amount_minor`, `currency`, `status`, `metadata`, timestamps; status enum cast)
- [x] **F03** — Migration + Eloquent model for `gmb_pay_refunds` (`charge_id`, `reference`, `provider_reference`, `amount_minor`, `status`, timestamps)
- [x] **F04** — Migration + Eloquent model for `gmb_pay_payouts` (`reference`, `provider_reference`, `recipient_phone`, `amount_minor`, `currency`, `status`, timestamps)
- [x] **F05** — Migration + Eloquent model for `gmb_pay_webhook_events` (`driver`, `provider_event_id`, `type`, `payload` JSON, `received_at`; **unique index on (driver, provider_event_id)** for dedup)
- [x] **F06** — Migration + Eloquent model for `gmb_pay_idempotency_keys` (`driver`, `key`, `target_type`, `target_id`, `created_at`; unique on (driver, key))

### Phase B — Webhook persistence + dispatch

- [x] **F07** — `WebhookController::handle()`: persist a `WebhookEvent` row before dispatching the event; ignore (200) duplicates by `(driver, provider_event_id)`
- [x] **F08** — `UpdateChargeFromWebhook` listener: on `WebhookReceived` with `charge.*` types, update the matching `gmb_pay_charges` row by `provider_reference`
- [x] **F09** — `UpdateRefundFromWebhook` listener: same pattern for `refund.*` types
- [x] **F10** — Auto-register listeners in the service provider (`Event::listen` in `boot()`); add config flag `gmb-pay.events.auto_register` defaulting to `true`

### Phase C — Idempotency

- [x] **F11** — `IdempotencyStore` service: `remember(driver, key, callable): result` — returns prior charge if (driver, key) seen, else runs the callable and records the result
- [x] **F12** — Wire idempotency into `PaymentManager::charge()` so `ChargeRequest::$idempotencyKey` short-circuits duplicate calls

### Phase D — Modempay real driver (only Phase-1 gateway with public docs)

> Verify all paths and signature scheme against `https://docs.modempay.com` when resuming. The headers below are the *expected* shape based on the public quickstart; treat the doc as authoritative.

- [x] **F13** — `ModempayClient` (Guzzle via `Illuminate\Http\Client`): base URL from config, `Authorization: Bearer {secret_key}` header, JSON content type, configurable timeout, request/response logging when `app()->isLocal()`
- [x] **F14** — `ModempayDriver::charge()` — POST to the payment-intents endpoint, return `ChargeResult` with `checkoutUrl` populated. Handle 4xx → `GmbPayException`
- [x] **F15** — `ModempayDriver::verify()` — GET payment-intent by reference, map provider status to `ChargeStatus`
- [ ] **F16** — *(BLOCKED — no public refund API)* `ModempayDriver::refund()` — POST refund, map to `RefundResult`
- [x] **F17** — `ModempayDriver::payout()` — only if Modempay supports payouts; otherwise throw `BadMethodCallException` with a clear message and remove from contract via capability split (decide at implementation time)
- [x] **F18** — `ModempayDriver::webhookSignatureValid()` — HMAC-**SHA512** of raw request body using `webhook_secret`, compared in constant time (header `x-modem-signature` — Modempay's published algorithm is SHA512, not SHA256 as this line originally said)
- [x] **F19** — `ModempayDriver::parseWebhook()` — map provider event types (`charge.succeeded`, `charge.cancelled`, etc.) to `WebhookEventType`, extract `provider_event_id`, `provider_reference`

### Phase E — Billable trait (Cashier-style)

- [x] **F20** — `Africs\GmbPay\Concerns\Billable` trait: `gmbPayCustomers()` morphMany relationship, `gmbPayCharges()` hasManyThrough Customer (schema has no direct polymorphic billable on charges; see done entry)
- [x] **F21** — `Billable::createGmbPayCustomer(?string $driver = null, array $opts = [])` — creates local customer row (provider customer creation deferred until a driver actually needs it)
- [x] **F22** — `Billable::charge(int $amountMinor, string $currency, array $opts)` — wraps `GmbPay::driver(...)->charge(...)`, persists `Charge` row, returns `ChargeResult`
- [x] **F23** — `Billable::findChargeByReference(string $reference): ?Charge`
- [x] **F24** — `Billable::refund(string $reference, ?int $amountMinor = null)` — fetches charge, calls driver, persists `Refund` row (Billable surface ships now; the Modempay driver-side refund call is still blocked on F16's missing public refund endpoint)

### Phase F — Plans & subscriptions

- [x] **F25** — Migration + model `gmb_pay_plans` (`slug`, `name`, `amount_minor`, `currency`, `interval` enum {day, week, month, year}, `interval_count`, `trial_days`, `active`)
- [x] **F26** — Migration + model `gmb_pay_subscriptions` (`billable_type`, `billable_id`, `plan_id`, `driver`, `status` enum {incomplete, active, past_due, canceled, paused}, `current_period_start`, `current_period_end`, `cancel_at_period_end`, `canceled_at`, `trial_ends_at`)
- [x] **F27** — Migration + model `gmb_pay_subscription_items` (`subscription_id`, `quantity`, `unit_amount_minor`)
- [x] **F28** — Migration + model `gmb_pay_invoices` (`subscription_id`, `charge_id` nullable, `amount_minor`, `currency`, `status` enum {open, paid, uncollectible, void}, `period_start`, `period_end`)
- [x] **F29** — `Billable::subscribeToPlan(Plan|string $plan, array $opts = [])` — creates Subscription in `incomplete`, dispatches `InitiateRecurringChargeJob` for the first cycle (job currently a stub; F32 fills its `handle()`)
- [x] **F30** — `Billable::subscriptions()` and `Billable::subscribed(?string $planSlug = null)`
- [x] **F31** — `Subscription` helpers: `cancel()`, `cancelAtPeriodEnd()`, `resume()`, `onTrial()`, `pastDue()`, `active()`, `markPastDue()`, `markCanceled()`

### Phase G — Subscription engine

- [x] **F32** — `InitiateRecurringChargeJob` (queueable): builds `ChargeRequest` from Subscription + Plan, calls `driver->charge()`, creates `Charge` + `Invoice` rows linking the cycle
- [x] **F33** — `RetryFailedChargeJob` with backoff schedule from `gmb-pay.subscriptions.retry_backoff_minutes` (defaults `[60, 360, 1440]`); after final retry, marks subscription `past_due`
- [x] **F34** — `gmb-pay:cycle` Artisan command: selects subs where `status=active` and `current_period_end <= now()`, dispatches `InitiateRecurringChargeJob` per sub
- [x] **F35** — Grace-period enforcer (inside the same `cycle` command run): subs `past_due` longer than `gmb-pay.subscriptions.grace_days` → `markCanceled()`
- [x] **F36** — Schedule documentation: install command output tells user to add `$schedule->command('gmb-pay:cycle')->everyFiveMinutes()` to their `routes/console.php`
- [x] **F37** — Webhook listener extension: on `charge.succeeded` linked to an invoice → mark invoice `paid` + advance `current_period_*` + clear `past_due` if set
- [x] **F38** — Webhook listener extension: on `charge.failed` linked to an invoice → set sub `past_due` + dispatch `RetryFailedChargeJob`

### Phase H — Views + customer portal (publishable)

- [ ] **F39** — Blade layout `resources/views/layouts/app.blade.php` (no Tailwind hard-dep — ship semantic HTML; consumers can theme)
- [ ] **F40** — `resources/views/checkout.blade.php` — minimal "redirecting to provider" page used as fallback when `ChargeResult::$checkoutUrl` is set
- [ ] **F41** — `resources/views/status.blade.php` — success / failure / pending render based on charge state
- [ ] **F42** — `resources/views/portal/index.blade.php` — landing for the customer portal
- [ ] **F43** — `resources/views/portal/subscriptions.blade.php` — list, cancel, resume
- [ ] **F44** — `resources/views/portal/invoices.blade.php` — list, view receipt
- [ ] **F45** — `PortalController` + opt-in `routes/portal.php` (loaded only when `gmb-pay.portal.enabled=true`); auth via app's default guard

### Phase I — Wave Gambia & Waychit (BLOCKED on merchant access)

- [ ] **F46** — *(BLOCKED)* Wave Gambia: pull API docs from merchant onboarding; document differences vs Wave Senegal in `docs/wave-gambia.md`
- [ ] **F47** — *(BLOCKED)* `WaveDriver::charge() / verify() / refund() / parseWebhook()` real implementation
- [ ] **F48** — *(BLOCKED)* Waychit: pull API docs from merchant onboarding; document in `docs/waychit.md`
- [ ] **F49** — *(BLOCKED)* `WaychitDriver::charge() / verify() / refund() / parseWebhook()` real implementation

### Phase J — Phase 2 gateways

- [ ] **F50** — `GamswitchDriver` stub + `createGamswitchDriver` on `PaymentManager` + config block
- [ ] **F51** — `QMoneyDriver` stub + manager registration + config block
- [ ] **F52** — `AfrimoneyDriver` stub (Africell Money) + manager registration + config block
- [ ] **F53–F55** — *(BLOCKED)* Real implementations of Gamswitch / QMoney / Africell Money once merchant access is granted

### Phase K — Tooling, docs, release

- [x] **F56** — `.github/workflows/tests.yml` — matrix PHP {8.3, 8.4} × Laravel {11, 12, 13}, runs Pint, PHPStan, Pest
- [x] **F57** — `pint.json` (Laravel Pint config — preset `laravel`, custom rules can come later)
- [x] **F58** — `phpstan.neon` (Larastan, level 6 to start, can ratchet up)
- [x] **F59** — `README.md` expansion: full usage examples for charges, subscriptions, custom driver registration, webhook signing in tests
- [ ] **F60** — `CONTRIBUTING.md`: TDD expectation, commit message format, how to add a new driver
- [x] **F61** — `CHANGELOG.md` (Keep a Changelog format) + first `0.1.0-alpha` tag (shipped without Phase H views — those deferred indefinitely)

---

## Dependency notes

- **F07 needs F05.** Don't start webhook persistence until the table exists.
- **F08–F10 need F02 and F03** (charges + refunds tables).
- **F12 needs F11.**
- **F14–F19 should land before any subscription work** so the engine has a working real driver to charge against. Until then, subscription tests run with the demo driver.
- **F22 and F24 need F02 and F03.**
- **F29 needs F25–F28 and F32.**
- **F37 and F38 need F08, F28, F32, F33.**
- **F45 needs F39 + F42–F44.**
- **F47 is blocked on F46. F49 is blocked on F48. F53–F55 are blocked on the corresponding gateway access.**
- **F16 is blocked on Modempay** exposing a public refund endpoint. `https://docs.modempay.com/documentation/core/transactions` documents a `refunded` status but no API operation to create one; revisit when merchant onboarding clarifies whether refunds happen via the dashboard or via an undocumented endpoint.

## When the user resumes

A fresh session should:
1. `git pull` to get any plan updates
2. Read this file's **Context** section (do not re-detect the test runner or re-derive patterns)
3. Read `tasks/done.md` to see what's been built and the metadata for sizing
4. Read `tasks/todo.md` for the active feature and any **Blocked** entries
5. Use **context7** to fetch Laravel 13 docs on demand (service provider, queues, scheduler, eloquent, blade) and the Modempay docs for Phase D
6. Pick the next unchecked feature respecting dependency notes above
7. Follow the TDD loop: failing test → minimal implementation → suite green → check the box → append to `done.md` → commit
