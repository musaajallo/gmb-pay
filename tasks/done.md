# Done

_Completed features logged here with metadata. Append one block per feature when you tick it in `all-features.md`._

## F13 — ModempayClient HTTP wrapper ✓
- **Tests:** 6/6 passing (full suite 67/67) — `vendor/bin/pest`
- **Files changed:** 2 (2 new)
  - `src/Drivers/Modempay/ModempayClient.php` (new — `request(method, path, body): Response`)
  - `tests/Drivers/Modempay/ModempayClientTest.php` (new)
- **Lines:** +120 / -0
- **Complexity:** Low — thin wrapper around `Illuminate\Support\Facades\Http`
- **Notes:**
  - Returns the raw `Illuminate\Http\Client\Response`. Driver methods (F14+) inspect `->status()` / `->json()` themselves rather than the client doing error mapping — that way each endpoint can decide whether a given 4xx is a `GmbPayException` or a domain-specific state
  - `app()->isLocal()` gates request/response `Log::debug` lines. `Log::spy()` + `app()->detectEnvironment(fn () => 'local')` in tests proves both branches; in default `testing` env nothing is logged (which is what we want for noisy CI)
  - `Http::withToken($key)` produces `Authorization: Bearer $key` natively — no manual header construction
  - Container binding deferred to F14. Whether `ModempayClient` ends up as a singleton or built inside `ModempayDriver::__construct` from the driver config is F14's call; F13 just delivers the class

## F12 — Wire idempotency into PaymentManager::charge() ✓
- **Tests:** 3/3 passing (full suite 61/61) — `vendor/bin/pest`
- **Files changed:** 3 (1 new, 2 modified)
  - `src/PaymentManager.php` (modified — constructor takes `IdempotencyStore`; explicit `charge(ChargeRequest, ?string): ChargeResult` method; private `persistChargeFromResult()` + `resultFromCharge()` helpers)
  - `src/GmbPayServiceProvider.php` (modified — pass `IdempotencyStore` into the `PaymentManager` singleton closure)
  - `tests/PaymentManager/ChargeIdempotencyTest.php` (new)
- **Lines:** +120 / -10
- **Complexity:** Medium — first explicit override of a `Manager` magic-proxied method; bridges DTO ↔ Eloquent
- **Notes:**
  - `PaymentManager::charge()` now exists as a real method, so the facade call `GmbPay::charge($request)` no longer falls through `Manager::__call` to the default driver. `verify`/`refund`/`payout` remain magic-proxied (the `@method` docblock lines were left in place; only the `charge` line was removed)
  - Driver-direct calls (`GmbPay::driver('modempay')->charge($req)` — what SmokeTest exercises) are unchanged: they still hit `AbstractDriver::charge()` and return a pure DTO with no persistence. F12 only activates when callers route through `PaymentManager::charge()`
  - Persistence is **gated on `idempotencyKey`**: null key → pure passthrough (no `Charge` row, no `IdempotencyKey` row), preserving today's behavior; non-null key → exactly one `Charge` row and one `IdempotencyKey` row per `(driver, key)`, even across many repeat calls. F22 (`Billable::charge`) will add Charge persistence on the no-key path later
  - ChargeResult round-trip through `gmb_pay_charges.metadata` uses `_gmbpay_checkout_url`, `_gmbpay_failure_reason`, `_gmbpay_raw` keys. The `_gmbpay_` prefix isolates internal stashing from caller-supplied metadata. `Charge::$casts['metadata'] = 'array'` and `$casts['status'] = ChargeStatus::class` handle the encode/decode automatically
  - Test environment quirk: with `Tests\PaymentManager` as a new directory, Pest's `__DIR__` sweep in `tests/Pest.php` picks it up automatically — no Pest.php edit needed
  - Concurrency hardening (DB transaction + `lockForUpdate` in `IdempotencyStore`) is still deferred. The `(driver, key)` unique constraint on `gmb_pay_idempotency_keys` is the long-term backstop; a parallel-retry test would force the harder version of `remember()` — leave for a later pass once we have a real driver

## F11 — IdempotencyStore service ✓
- **Tests:** 4/4 passing (full suite 58/58) — `vendor/bin/pest`
- **Files changed:** 2 (2 new)
  - `src/Idempotency/IdempotencyStore.php` (new — `Africs\GmbPay\Idempotency\IdempotencyStore::remember(driver, key, callback): Model`)
  - `tests/Idempotency/IdempotencyStoreTest.php` (new)
- **Lines:** +130 / -0
- **Complexity:** Low — single read, single conditional `updateOrCreate`, returns the morphTo target
- **Notes:**
  - First non-Model, non-Driver class in the package; gave it its own `Africs\GmbPay\Idempotency` namespace so F12 (and future siblings like a key-hasher / context object) have a stable home
  - `remember()` returns `Illuminate\Database\Eloquent\Model` — the callback's job is to produce one. F12 will be the place where `ChargeResult` → `Charge` model is bridged before this is called
  - Existing-row check requires **both** `target_type` and `target_id` to be non-null. That lets a future "two-phase" flow (insert key first → run callback → back-fill target) recover cleanly: a half-written row is treated as "not yet executed" and the callback re-runs. F11 itself doesn't write half-rows but the read path is already shaped for it
  - No transaction or `lockForUpdate` yet — concurrency hardening is deferred to F12 where it matters (PaymentManager::charge). The `(driver, key)` unique index in `gmb_pay_idempotency_keys` is still the long-term backstop
  - Container binding deferred too: F11 instantiates fresh in tests; F12 can decide whether to bind as a singleton when it wires it into PaymentManager
  - Test helper rename: `tests/Persistence/RefundTest.php` already defines a global Pest helper called `makeCharge`. Pest's `__DIR__` sweep loads both files into the same process so a duplicate top-level function fatally errors. Renamed mine to `makeIdempotencyTestCharge`

## F10 — Auto-register webhook listeners ✓
- **Tests:** 2/2 passing (full suite 54/54) — `vendor/bin/pest`
- **Files changed:** 5 (1 new, 4 modified)
  - `tests/Webhook/AutoRegisterListenersTest.php` (new)
  - `config/gmb-pay.php` (modified — added `events.auto_register` default `true`, also reads `GMB_PAY_EVENTS_AUTO_REGISTER`)
  - `src/GmbPayServiceProvider.php` (modified — gated `Event::listen` for both listeners in `boot()`)
  - `src/Drivers/AbstractDriver.php` (modified — `parseWebhook()` now extracts `provider_reference`, falling back to `reference`)
  - `tasks/all-features.md` (ticked F10)
- **Lines:** +63 / -3 (approx)
- **Complexity:** Low — three small wiring edits plus the gate test
- **Notes:**
  - Provider boot uses `config->get('gmb-pay.events.auto_register', true)` so installations that ran F00–F09 without republishing config still get auto-registration on upgrade
  - F08/F09 listener tests still call `Event::listen` explicitly in their `beforeEach`. With auto-register on by default the listener now registers twice and fires twice per event; the listener body (`update(['status' => $status])`) is idempotent so this is harmless. Left as-is per the F10 done criteria
  - The OFF test in the same file uses `Event::forget(WebhookReceived::class)` rather than a per-test config flip because Orchestra Testbench bootstraps the app inside `setUp` before any test-body code runs, so a runtime `config(...)` call lands after the provider's `boot()` has already decided whether to register. The observable outcome (webhook row persisted, charge stays Pending) matches the spec; a comment in the test explains the limitation
  - Tried a two-file approach with a per-file `defineEnvironment` subclass first — Pest's `uses(TestCase::class)->in(__DIR__)` in `tests/Pest.php` claimed the directory and rejected a per-file `uses()` for the OFF test (`"The folder ... already uses the test case ..."`). Single-file `Event::forget` was the smaller change

## F09 — UpdateRefundFromWebhook listener ✓
- **Tests:** 5/5 passing (full suite 52/52) — `vendor/bin/pest`
- **Files changed:** 2 (2 new)
  - `src/Listeners/UpdateRefundFromWebhook.php` (new)
  - `tests/Webhook/UpdateRefundFromWebhookTest.php` (new)
- **Lines:** +120 / -0
- **Complexity:** Low — same shape as F08 with a `whereHas('charge')` instead of a direct driver scope
- **Notes:**
  - Refunds have no `driver` column — driver scoping piggybacks on the parent charge via `whereHas`. This avoids denormalizing driver onto the refund row, which would need a backfill if the charge ever moved drivers (rare but legal during merchant migration)
  - Cross-driver test uses two charges + two refunds with the same `provider_reference` to prove a modempay webhook does not bleed into a wave refund
  - Same `?->update(...)` no-op pattern as F08; the refund table is small enough that an extra missed lookup is cheaper than tracking unknown refund references in another table

## F08 — UpdateChargeFromWebhook listener ✓
- **Tests:** 6/6 passing (full suite 47/47) — `vendor/bin/pest`
- **Files changed:** 2 (2 new)
  - `src/Listeners/UpdateChargeFromWebhook.php` (new)
  - `tests/Webhook/UpdateChargeFromWebhookTest.php` (new)
- **Lines:** +130 / -0
- **Complexity:** Low — single listener, single match expression, scoped lookup
- **Notes:**
  - Listener is registered manually via `Event::listen` in the test's `beforeEach`. F10 will move that into the service provider behind a config flag, so this test stays useful as a unit-level guard even after auto-registration
  - Lookup is scoped on **both** `driver` and `provider_reference` — proven by the cross-driver test. Without the driver scope, a webhook from one provider could mutate a same-named charge from another
  - Refund and Unknown event types are explicit `default => null` no-ops — F09 picks up refunds in its own listener so this one stays single-purpose
  - Used `?->update(...)` so a missing local row is silent (provider may emit events for charges initiated outside this app); the test "no matching local charge" codifies that as a non-throw

## F07 — Webhook persistence + dedup ✓
- **Tests:** 3/3 passing (full suite 41/41) — `vendor/bin/pest`
- **Files changed:** 4 (1 new, 3 modified)
  - `tests/Webhook/WebhookPersistenceTest.php` (new)
  - `src/DataObjects/WebhookEvent.php` (modified — added `providerEventId` property)
  - `src/Drivers/AbstractDriver.php` (modified — `parseWebhook` now extracts `id`+`type` from payload)
  - `src/Http/Controllers/WebhookController.php` (modified — dedup lookup + row insert before dispatch)
- **Lines:** +73 / -8
- **Complexity:** Medium — touches DTO, abstract driver, controller, and introduces the controller's first DB write
- **Notes:**
  - `parseWebhook` now best-effort extracts `id` (provider event id) and maps `type` via `WebhookEventType::tryFrom()`. Drivers can still override entirely; AbstractDriver's behavior is just the safe default
  - Dedup short-circuit returns `{"received": true, "duplicate": true}` so providers see a 200 and stop retrying. Rows are never inserted twice for the same `(driver, provider_event_id)`
  - Persisting **before** dispatch is intentional: queued listener failures still leave the raw event on disk for replay (F08+ will use this)
  - `Event::fake([WebhookReceived::class])` in the test isolates dispatches without breaking other listeners — important once F10 auto-registers `UpdateChargeFromWebhook`/`UpdateRefundFromWebhook`
  - Null `provider_event_id` payloads always insert a fresh row (SQLite NULLs are distinct in unique indexes); test 3 codifies this

## F06 — Idempotency keys migration + IdempotencyKey model ✓
- **Tests:** 5/5 passing (full suite 38/38) — `vendor/bin/pest`
- **Files changed:** 3 (3 new)
  - `database/migrations/2026_01_01_000006_create_gmb_pay_idempotency_keys_table.php` (new)
  - `src/Models/IdempotencyKey.php` (new)
  - `tests/Persistence/IdempotencyKeyTest.php` (new)
- **Lines:** +138 / -0
- **Complexity:** Low — single table, single model, polymorphic target
- **Notes:**
  - `key` is 191 chars to keep the composite `(driver, key)` index under MySQL's old utf8mb4 767-byte limit without `innodb_large_prefix` config — same default Laravel uses
  - `target_type` and `target_id` are both nullable so F11 can write the row before the target exists, then back-fill on success. Tests use a non-null Charge target so the morphTo path is exercised
  - Added `(target_type, target_id)` index for reverse lookups ("which idempotency key created this Charge?") — cheap to add now, painful to add later when the table has volume
  - This closes Phase A. Persistence layer is complete; F07 is the first feature that *uses* these tables together

## F05 — Webhook events migration + WebhookEvent model ✓
- **Tests:** 6/6 passing (full suite 33/33) — `vendor/bin/pest`
- **Files changed:** 3 (3 new)
  - `database/migrations/2026_01_01_000005_create_gmb_pay_webhook_events_table.php` (new)
  - `src/Models/WebhookEvent.php` (new)
  - `tests/Persistence/WebhookEventTest.php` (new)
- **Lines:** +148 / -0
- **Complexity:** Low — single table, single model. The only subtlety is the namespace clash with the DTO
- **Notes:**
  - **Namespace clash (intentional):** `Africs\GmbPay\Models\WebhookEvent` is the DB row, `Africs\GmbPay\DataObjects\WebhookEvent` is the DTO sent to listeners. F07/F08/F09 will need to import both with aliases — keep them separate; the DTO never holds an `id`, the model never holds raw provider headers
  - SQLite (test driver) treats multiple NULL `provider_event_id` values as distinct in the unique index — test 6 codifies this so the controller can safely insert rows for providers that don't send an event id without colliding
  - `received_at` is a separate column from `created_at` because retries / backfills may set received_at to the original delivery time; tests assert Carbon round-trip on the literal value, not the column default
  - Index on `type` is cheap to add now and unblocks future "list all `charge.failed` in the last 24h" queries without a backfill migration

## F04 — Payouts migration + Payout model ✓
- **Tests:** 5/5 passing (full suite 27/27) — `vendor/bin/pest`
- **Files changed:** 3 (3 new)
  - `database/migrations/2026_01_01_000004_create_gmb_pay_payouts_table.php` (new)
  - `src/Models/Payout.php` (new)
  - `tests/Persistence/PayoutTest.php` (new)
- **Lines:** +137 / -0
- **Complexity:** Low — single table, single model, no relations, mirror of charges' unique-index strategy
- **Notes:**
  - `recipient_phone` stored as plain string (not nullable) — driver code normalizes to E.164 on the way in. Phase-1 gateways all require a phone, so non-null is correct now; non-phone rails (bank account, card) would need a column rename later
  - Composite unique `(driver, provider_reference)` matches the charges table — keeps dedup logic identical for whichever subsystem (or future cycle command) needs it
  - No `customer_id` and no `charge_id` — payouts are merchant-initiated money-out, decoupled from inbound charge state. F37/F38 (subscription webhooks) will not touch this table

## F03 — Refunds migration + Refund model ✓
- **Tests:** 6/6 passing (full suite 22/22) — `vendor/bin/pest`
- **Files changed:** 4 (3 new, 1 modified)
  - `database/migrations/2026_01_01_000003_create_gmb_pay_refunds_table.php` (new)
  - `src/Models/Refund.php` (new)
  - `src/Models/Charge.php` (modified — added `refunds(): HasMany`)
  - `tests/Persistence/RefundTest.php` (new)
- **Lines:** +75 / -1
- **Complexity:** Low — single table, single model, one belongsTo + inverse hasMany + enum cast
- **Notes:**
  - `charge_id` uses `constrained()->cascadeOnDelete()` — deleting a charge wipes its refund rows. Acceptable because production refund history will live in the provider too; revisit with soft deletes when audit needs surface
  - No `(driver, provider_reference)` unique on refunds (unlike charges) because provider_reference alone is not globally unique across drivers, but refunds are always reached via `charge_id` so dedup is the listener's job in F09 anyway
  - `unique(reference)` is the local invariant tested explicitly so duplicate-create surfaces a `QueryException` rather than silently inserting

## F02 — Charges migration + Charge model ✓
- **Tests:** 6/6 passing (full suite 16/16) — `vendor/bin/pest`
- **Files changed:** 3 (3 new)
  - `database/migrations/2026_01_01_000002_create_gmb_pay_charges_table.php` (new)
  - `src/Models/Charge.php` (new)
  - `tests/Persistence/ChargeTest.php` (new)
- **Lines:** +156 / -0
- **Complexity:** Low — single table, single model, one belongsTo + enum cast
- **Notes:**
  - `status` cast to `Africs\GmbPay\Enums\ChargeStatus`; backed-string values (`pending`, `succeeded`, `failed`, `cancelled`, `refunded`) round-trip through SQLite cleanly
  - Two unique indexes: `reference` (local id, always set) and `(driver, provider_reference)` (provider id, nullable). SQLite treats multiple NULLs as distinct so dedup works as soon as the provider responds
  - `customer_id` is `nullable + nullOnDelete` because one-shot phone payments don't need a stored customer (see F22)
  - Index on `status` added now to keep the cycle command's `where status = 'active'` lookups cheap when subscriptions land (F34)

## F01 — Customers migration + Customer model ✓
- **Tests:** 4/4 passing (full suite 10/10) — `vendor/bin/pest`
- **Files changed:** 6 (5 new, 1 modified)
  - `database/migrations/2026_01_01_000001_create_gmb_pay_customers_table.php` (new)
  - `src/Models/Customer.php` (new)
  - `tests/Persistence/CustomerTest.php` (new)
  - `tests/Fixtures/Models/FakeBillable.php` (new)
  - `tests/Fixtures/migrations/0000_00_00_000000_create_fake_billables_table.php` (new)
  - `tests/TestCase.php` (added `defineDatabaseMigrations()`)
- **Lines:** +132 / -0
- **Complexity:** Low — single table, single model, polymorphic morphTo
- **Notes:**
  - `defineDatabaseMigrations()` in `TestCase` loads both fixture and package migrations; this is reused by every persistence test going forward
  - Fixture `FakeBillable` lives under `tests/Fixtures/Models/` and a tiny migration creates `fake_billables` — kept generic so it can serve any morph-owner test (charges, refunds, subscriptions)
  - Unique index `(billable_type, billable_id, driver)` is named explicitly (`gmb_pay_customers_billable_driver_unique`) because Laravel's auto-generated name exceeds the 64-char limit on some MySQL configs
  - `metadata` JSON cast to array; column is nullable so rows can be created without provider customer creation (deferred until a driver actually needs it — see F21)

## F00 — Initial scaffold ✓
- **Tests:** 6/6 passing (`vendor/bin/pest`)
- **Files:** 36 new (composer.json, service provider, manager, facade, contract + capability interfaces, 8 DTOs, 4 enums, 3 exceptions, abstract driver + 3 concrete stub drivers, install command, webhook routing + controller, event, phpunit.xml, Pest bootstrap, smoke test, README, LICENSE, .gitignore)
- **Lines:** +900 / -0 (approx)
- **Complexity:** Medium — package scaffolding requires several coordinated pieces (manager + facade + provider + contract + DTOs) before any test can boot
- **Notes:**
  - Demo mode (`GMB_PAY_DEMO=true`) returns stubbed success across all drivers — used as test default and as the local-dev fallback before merchant onboarding
  - Service provider auto-publishes config (`gmb-pay-config`), migrations (`gmb-pay-migrations`), and views (`gmb-pay-views`) tags
  - `routes/webhooks.php` is auto-loaded; webhook URL pattern: `{prefix}/{driver}` where prefix defaults to `gmb-pay/webhook`
  - Composer install resolved to Laravel 13.8.0 + Pest 4.7.0 + Orchestra Testbench 11.1.0 on PHP 8.4.20
