# Done

_Completed features logged here with metadata. Append one block per feature when you tick it in `all-features.md`._

## F34 — gmb-pay:cycle Artisan command ✓
- **Tests:** 4/4 passing (full suite 176/176) — `vendor/bin/pest`
- **Files changed:** 3 (2 new, 1 modified)
  - `src/Console/CycleCommand.php` (new — `gmb-pay:cycle`)
  - `src/GmbPayServiceProvider.php` (modified — register `CycleCommand` alongside `InstallCommand`)
  - `tests/Console/CycleCommandTest.php` (new — `Bus::fake()`-backed)
- **Lines:** +130 / -1
- **Complexity:** Trivial — single query + `each()` dispatch
- **Notes:**
  - **Active-only scope** — non-Active statuses (Incomplete/Canceled/PastDue/Paused) skip the command silently. F35 will extend the same `handle()` to walk `PastDue` for grace expiration; the structure stays the same
  - **F26's composite `(status, current_period_end)` index backs this query** — the prophylactic-index pattern is paying off already
  - **Idempotency at the dispatch layer**: if the command runs twice in a window before `current_period_end` is rolled, the same sub gets two jobs. The inner `InitiateRecurringChargeJob` will run twice and create duplicate Charge rows. F32 already accepts that — webhook reconciliation (via the `(driver, provider_reference)` unique index) is the eventual dedup. F34's job is to fire; idempotency is a downstream concern
  - **Schedule documentation** is F36 — telling consumers to register `$schedule->command('gmb-pay:cycle')->everyFiveMinutes()`

## F33 — RetryFailedChargeJob with backoff schedule ✓
- **Tests:** 3/3 passing (full suite 172/172) — `vendor/bin/pest`
- **Files changed:** 2 (2 new)
  - `src/Jobs/RetryFailedChargeJob.php` (new)
  - `tests/Jobs/RetryFailedChargeJobTest.php` (new — including a throwing-driver shim that swaps the `PaymentManager` binding)
- **Lines:** +210 / -0
- **Complexity:** Medium — coordinates F32 reuse + container-binding swap in the test
- **Notes:**
  - **Wraps `InitiateRecurringChargeJob` rather than duplicating logic** — `try { (new InitiateRecurring…)->handle(); } catch (...)`. F32's cycle work stays the single source of truth; F33 only owns retry decisioning
  - **Exhaustion check is `attempt > count(backoffs)`** — `attempt=1..count` are valid invocations; `attempt=count+1` is the "give up" sentinel. Test (a) uses `attempt=4` against a 3-element backoff to lock that
  - **Throwing-driver shim in the test** binds an anonymous PaymentManager (with a `driver()` method) to the container. The anonymous payment driver throws on `charge()`, no-ops the rest. This is the right shape for any future "what if the provider is down" test
  - **Delay assertion is loose** (`$job->delay !== null`) — Laravel's queued-job delay isn't always a Carbon at Bus::fake() capture time, sometimes it's already serialized to seconds. The looser check still proves a delay was attached without coupling the test to internal representation
  - F34 (`gmb-pay:cycle` artisan command) is next; F35 (grace enforcer) and F37/F38 (webhook listener extensions) complete Phase G

## F32 — InitiateRecurringChargeJob real handle() ✓
- **Tests:** 3/3 passing (full suite 169/169) — `vendor/bin/pest`
- **Files changed:** 2 (1 new, 1 modified)
  - `src/Jobs/InitiateRecurringChargeJob.php` (modified — full `handle()` + private `addInterval()`)
  - `tests/Jobs/InitiateRecurringChargeJobTest.php` (new)
- **Lines:** +200 / -3
- **Complexity:** Medium — the first feature that wires Subscription → driver → Charge → Invoice end-to-end
- **Notes:**
  - **Trial vs Live paths** branch on `onTrial()`. Trial: no driver call, advance to Active with period spanning to `trial_ends_at`. Live: real charge + Invoice + advance period by `plan.interval * plan.interval_count`
  - **Total amount = `sum(quantity * unit_amount_minor)`** across all SubscriptionItems. Test (c) locks the quantity multiplication (was the most likely source of off-by-N bugs)
  - **`addInterval()` helper** uses Carbon's `addDays/addWeeks/addMonths/addYears` per PlanInterval enum — single source of truth for the period-end calculation; F37 (webhook listener that rolls the period forward on `charge.succeeded`) will reuse this when it lands
  - **Invoice persisted as `Open`** here; F37's webhook listener will flip it to `Paid` when the corresponding `charge.succeeded` arrives. Today demo mode returns a `Pending` charge so the invoice stays Open across the test — that's the right shape for a real provider where confirmation arrives asynchronously
  - **Customer is reused via `createGmbPayCustomer()`** (F21's idempotent helper), so first-cycle and subsequent-cycle Charges all link to the same Customer row per billable+driver
  - **Driver exceptions bubble up** — F33's RetryFailedChargeJob will be the rescue layer. F32 doesn't catch so the queue can mark the job failed and trigger the retry chain

## F31 — Subscription lifecycle helpers — closes Phase F ✓
- **Tests:** 7/7 passing (full suite 166/166) — `vendor/bin/pest`
- **Files changed:** 2 (1 new, 1 modified)
  - `src/Models/Subscription.php` (modified — eight lifecycle methods)
  - `tests/Persistence/SubscriptionLifecycleTest.php` (new)
- **Lines:** +180 / -0
- **Complexity:** Low — eight small methods, no external dependencies
- **Notes:**
  - **`markCanceled()` aliases `cancel()`** — same DB effect. The naming difference signals intent (user-initiated vs system-initiated, e.g. F35's grace enforcer) to readers
  - **`resume()` handles two paths in one call**: from `Canceled` it flips to `Active` and nulls `canceled_at`; from any other status it just clears `cancel_at_period_end`. Callers don't need to branch
  - **`onTrial()` uses Carbon's `isFuture()`** rather than a manual `>` comparison — handles same-second equality edge case correctly
  - **`forceFill` over `update`** so the methods work even if Subscription ever picks up `$fillable`. Mass-assignment guards aren't relevant here — these are explicit, audited mutations
  - **Phase F closes** with F31. 7 features: F25 (Plan), F26 (Subscription), F27 (SubscriptionItem), F28 (Invoice), F29 (subscribeToPlan + job stub), F30 (subscriptions/subscribed), F31 (lifecycle helpers). All seven shipped this session. Phase G opens next: F32 fills in `InitiateRecurringChargeJob::handle()`

## F30 — Billable::subscriptions() + subscribed() ✓
- **Tests:** 4/4 passing (full suite 159/159) — `vendor/bin/pest`
- **Files changed:** 2 (1 new, 1 modified)
  - `src/Concerns/Billable.php` (modified — adds `subscriptions(): MorphMany` and `subscribed(?string $planSlug = null): bool`)
  - `tests/Billable/SubscribedTest.php` (new)
- **Lines:** +90 / -0
- **Complexity:** Trivial — one morph relation + one EXISTS query
- **Notes:**
  - **"Subscribed" means `Active` only** for v1. `PastDue`, `Paused`, `Incomplete`, `Canceled` all return `false`. Test (c) iterates every non-Active status to lock the contract. F35's grace enforcer may relax this later (e.g., "subscribed within 3-day grace after past_due"), but adding nuance is easier than removing it
  - **`subscribed($slug)` uses `whereHas`** rather than joining, keeping it a single EXISTS subquery. No N+1, no full collection hydration

## F29 — Billable::subscribeToPlan() + InitiateRecurringChargeJob stub ✓
- **Tests:** 6/6 passing (full suite 155/155) — `vendor/bin/pest`
- **Files changed:** 3 (2 new, 1 modified)
  - `src/Jobs/InitiateRecurringChargeJob.php` (new — Queueable stub; `handle()` is intentionally empty pending F32)
  - `src/Concerns/Billable.php` (modified — adds `subscribeToPlan()`)
  - `tests/Billable/SubscribeToPlanTest.php` (new — `Bus::fake()`-backed)
- **Lines:** +170 / -2
- **Complexity:** Medium — first feature in Phase F that wires persistence, queueing, and trial logic together
- **Notes:**
  - **Job stub now, real handler later** — `InitiateRecurringChargeJob` exists with an empty `handle()` so F29 can `::dispatch($subscription)` without runtime errors. F32 will implement the body (build a `ChargeRequest` from the Subscription + Plan, call `$driver->charge()`, create Charge + Invoice rows). The class shape (Queueable, `__construct(public Subscription $subscription)`) is the public contract — don't change it in F32
  - **Trial computation is `now() + plan->trial_days`** with a 5-second tolerance check in the test to absorb cross-second flakiness. `trial_days = 0` → `null` (no trial), per test (c)
  - **`Subscription::create()` + `->items()->create()`** in two writes (no transaction). If the item insert fails the orphan Subscription stays. Acceptable for v1 — Subscription validation happens at the migration level (plan_id FK exists, billable polymorphic columns are non-null); a failed item insert would itself be a DB schema bug worth surfacing
  - **`$subscription->fresh(['items'])`** is returned so the caller can immediately read items without a second query. Costs one extra SELECT but matches the test-friendly shape
  - **Multiple subs to the same plan are allowed** — Stripe-like. Callers wanting one-sub-per-billable-per-plan should check first
  - F30 (`subscriptions()` + `subscribed()` helpers) is next — small read-side helpers. F31 (lifecycle helpers) wraps Phase F's business logic

## F28 — gmb_pay_invoices migration + Invoice model ✓
- **Tests:** 4/4 passing (full suite 149/149) — `vendor/bin/pest`
- **Files changed:** 5 (4 new, 1 modified)
  - `src/Enums/InvoiceStatus.php` (new — `open | paid | uncollectible | void`)
  - `database/migrations/2026_01_01_000010_create_gmb_pay_invoices_table.php` (new)
  - `src/Models/Invoice.php` (new — `subscription()`, `charge()` belongsTo + datetime casts)
  - `src/Models/Subscription.php` (modified — `invoices()` HasMany)
  - `tests/Persistence/InvoiceTest.php` (new)
- **Lines:** +175 / -0
- **Complexity:** Low — single table, two FKs with different on-delete behaviours
- **Notes:**
  - **Two different on-delete behaviours**: `subscription_id → cascadeOnDelete()` (kill the sub, kill its invoices), `charge_id → nullOnDelete()` (kill a charge but keep the invoice history with a null charge link). Test (d) locks both branches by deleting in two stages
  - **Currency is frozen on the invoice**, not pulled from the parent Plan at read time. If a Plan's currency ever changes, historical invoices keep what they billed in. (Plans shouldn't change currency in practice, but the schema doesn't have to enable the drift)
  - **Index on `(status, period_end)`** for the F34 `gmb-pay:cycle` open-invoice sweep, same prophylactic-index pattern F02/F26 used
  - F29 (`Billable::subscribeToPlan`) is next — first business-logic feature in Phase F. F25–F28 gave us all the persistence we need to back it

## F27 — gmb_pay_subscription_items migration + SubscriptionItem model ✓
- **Tests:** 4/4 passing (full suite 145/145) — `vendor/bin/pest`
- **Files changed:** 4 (3 new, 1 modified, +1 fixture tweak)
  - `database/migrations/2026_01_01_000009_create_gmb_pay_subscription_items_table.php` (new)
  - `src/Models/SubscriptionItem.php` (new — `subscription()` belongsTo, int casts)
  - `src/Models/Subscription.php` (modified — adds `items(): HasMany`)
  - `tests/Persistence/SubscriptionItemTest.php` (new)
  - `tests/TestCase.php` (modified — enables `foreign_key_constraints` on the testing SQLite connection)
- **Lines:** +145 / -1
- **Complexity:** Low — single child table, two relations, one config flip in tests
- **Notes:**
  - **Enabling `foreign_key_constraints` on the SQLite test connection** was necessary to make the cascade-delete test actually fire. SQLite ships with FKs **off** by default; Orchestra Testbench's in-memory test DB inherits that. Without the pragma, the F03/F27 `cascadeOnDelete()` declarations are decorative only at test time. Real MySQL/Postgres enforce FKs natively. Setting `database.connections.testing.foreign_key_constraints = true` in `TestCase::defineEnvironment` is the single source of truth — every persistence test now exercises real cascade behaviour
  - **`quantity` default 1** lets F29 do `subscription->items()->create(['unit_amount_minor' => $plan->amount_minor])` for the single-plan case and skip the quantity field
  - **No `currency` on items** — they inherit it from `Subscription → Plan`. Single source of truth avoids drift if a plan's currency ever changes (it shouldn't, but the schema doesn't have to enable the drift)

## F26 — gmb_pay_subscriptions migration + Subscription model ✓
- **Tests:** 3/3 passing (full suite 141/141) — `vendor/bin/pest`
- **Files changed:** 4 (4 new)
  - `src/Enums/SubscriptionStatus.php` (new — `incomplete | active | past_due | canceled | paused`)
  - `database/migrations/2026_01_01_000008_create_gmb_pay_subscriptions_table.php` (new)
  - `src/Models/Subscription.php` (new — `billable()` morphTo + `plan()` belongsTo)
  - `tests/Persistence/SubscriptionTest.php` (new)
- **Lines:** +160 / -0
- **Complexity:** Low — single table, two relations, five casts
- **Notes:**
  - **Composite index on `(status, current_period_end)`** is the prophylactic index for F34's `gmb-pay:cycle` command (`where('status', Active)->where('current_period_end', '<=', now())`). MySQL/Postgres both pick it for the compound predicate
  - **Period timestamps cast to `datetime`** (Carbon) so F32+ can do `current_period_end->copy()->addMonth()` arithmetic without re-parsing
  - **`startOfSecond()` in the test** matters because SQLite/MySQL only persist seconds (no sub-second). Without it, `equalTo()` would fail on a roundtrip mismatch of microseconds
  - **No DB default for `status`** because F29 (`subscribeToPlan`) sets it explicitly to `Incomplete` on create — leaving the column nullable would muddle the contract. Migration declares it non-null
  - F27 (subscription items) and F28 (invoices) next, both pure persistence. F29 is the first business-logic feature in Phase F

## F25 — gmb_pay_plans migration + Plan model ✓
- **Tests:** 4/4 passing (full suite 138/138) — `vendor/bin/pest`
- **Files changed:** 4 (4 new)
  - `src/Enums/PlanInterval.php` (new — `day | week | month | year` backed string enum)
  - `database/migrations/2026_01_01_000007_create_gmb_pay_plans_table.php` (new)
  - `src/Models/Plan.php` (new)
  - `tests/Persistence/PlanTest.php` (new)
- **Lines:** +130 / -0
- **Complexity:** Low — single table, single model, four casts; no relationships
- **Notes:**
  - **Defaults baked in at the DB layer** — `interval_count = 1`, `trial_days = 0`, `active = true`. Test (d) locks all three. Lets a minimal `Plan::create(['slug' => 'x', 'name' => 'X', 'amount_minor' => 1000, 'currency' => 'GMD', 'interval' => Month])` succeed
  - **`interval` cast to `PlanInterval`** so reads come back typed. The DB column stores the backed string `'month'` etc.; the enum cast round-trips it
  - **Index on `active`** added now to keep the cycle command's `where active = true` lookups cheap when subscriptions land (mirrors the same prophylactic index F02 added to `gmb_pay_charges.status`)
  - **`trial_days` lives on the Plan**, not the Subscription — a change to the plan affects future subs; existing subs already froze their `trial_ends_at` at subscribe-time (F26 wires that)
  - Opens **Phase F**. F26 (Subscriptions schema), F27 (SubscriptionItems), F28 (Invoices) are next — three more pure-persistence features before F29's first business-logic helper

## F24 — Billable::refund — drive + persist a Refund row ✓
- **Tests:** 4/4 passing (full suite 134/134) — `vendor/bin/pest`
- **Files changed:** 2 (1 new, 1 modified)
  - `src/Concerns/Billable.php` (modified — adds `refund()`)
  - `tests/Billable/BillableRefundTest.php` (new)
- **Lines:** +105 / -0
- **Complexity:** Low — `findChargeByReference()` + a single driver call + a `Refund::create`
- **Notes:**
  - **Billable surface ships now even though F16 is blocked.** Demo-mode refunds work end-to-end against `AbstractDriver::refund()`'s stub. Non-demo against Modempay surfaces `BadMethodCallException` from `AbstractDriver::notImplemented()` — test (d) locks that, so when F16 unblocks the Modempay-side refund endpoint, the F24 test simply flips from "throws BadMethodCallException" to "returns Succeeded RefundResult" without touching the Billable
  - **Missing/cross-billable reference fails fast** with `GmbPayException` *before* hitting the driver — `findChargeByReference()` is the gate. Test (c) explicitly asserts no `Refund` row is inserted on that path
  - **Refund schema has no `currency`** (the parent Charge owns currency); the DTO mirrors this. Don't accidentally try to pass currency to the driver — it'll fail at the `RefundRequest` constructor
  - **Idempotency is not wired here.** `RefundRequest::$idempotencyKey` exists in the DTO but there's no `PaymentManager::refund()` analog of F12. When Modempay's refund endpoint surfaces (post-F16), a follow-up will add the equivalent — for now this Billable helper just drives once and persists once
  - **Phase E closes** with F20–F24 all shipped. F25 opens Phase F (Plans). Subscription work runs entirely on the demo driver — no more provider docs needed until Wave/Waychit onboarding for F46+

## F23 — Billable::findChargeByReference ✓
- **Tests:** 4/4 passing (full suite 130/130) — `vendor/bin/pest`
- **Files changed:** 2 (1 new, 1 modified)
  - `src/Concerns/Billable.php` (modified — single-method addition)
  - `tests/Billable/FindChargeByReferenceTest.php` (new)
- **Lines:** +75 / -0
- **Complexity:** Trivial — one query on the existing `gmbPayCharges()` relation, qualified by `gmb_pay_charges.reference`
- **Notes:**
  - Relies entirely on `gmbPayCharges()`'s built-in `billable_type` scoping (per F20). No extra `where` for billable id/type needed
  - Returns `null` not just for missing refs but for **other-billable** and **orphan** Charges — tests (c) and (d) lock both cases so a future refactor can't accidentally widen the access window

## F22 — Billable::charge — drive + persist + link ✓
- **Tests:** 5/5 passing (full suite 126/126) — `vendor/bin/pest`
- **Files changed:** 2 (1 new, 1 modified)
  - `src/Concerns/Billable.php` (modified — adds `charge()` method)
  - `tests/Billable/BillableChargeTest.php` (new)
- **Lines:** +120 / -0
- **Complexity:** Medium — bridges Billable → PaymentManager → Charge persistence, two paths unified via `firstOrNew`
- **Notes:**
  - **Goes through `PaymentManager::charge()` (F12)**, not the driver directly, so the idempotency dedup that F12 wired up still applies via `$opts['idempotencyKey']`. Test (c) proves a repeat with the same key returns the same `reference` and Charge::count stays at 1
  - **Unified persistence via `firstOrNew`**: F12 only persists when `idempotencyKey` is set; F22 needs a Charge regardless. After F12 returns the `ChargeResult`, F22 does `Charge::firstOrNew(['reference' => $result->reference])` and fills the link fields. For the idempotency-keyed path, the row already exists (created by F12) and we back-fill `customer_id`; for the no-key path, the row is fresh and we set everything including the `_gmbpay_*` metadata stash. This resolves the asymmetry called out in F20's done entry
  - **Metadata is only written on create** (`if (! $charge->exists)`), preventing F22 from accidentally clobbering F12's `_gmbpay_*` stash on replay. The metadata's user-facing keys (e.g. `order_id`) come from `$request->metadata`; the `_gmbpay_*` internal keys come from `ChargeResult`. Test (e) checks the user-facing keys round-trip
  - **`$opts['customerPhone']` defaults to `''`** because `ChargeRequest::$customerPhone` is non-nullable. Modempay's `/v1/payments` doesn't accept phone at the intent level anyway (see F14 done notes), so empty is harmless. Drivers that DO need a phone (Wave/Waychit when those land) should validate at the driver layer
  - **Duplicates F12's metadata-blob construction**. Acceptable for two callers (F12 + F22). If a third caller surfaces (e.g. F32 `InitiateRecurringChargeJob`), extract `persistChargeFromResult()` to `src/Internal/ChargeBridge.php` as a shared static helper
  - F23 (next) is the tiny `findChargeByReference()` lookup. F24 ships as a Billable-side wrapper around `$driver->refund()` — Modempay's path bubbles up the `BadMethodCallException` until F16's blocker resolves; demo mode still works

## F21 — Billable::createGmbPayCustomer() ✓
- **Tests:** 5/5 passing (full suite 121/121) — `vendor/bin/pest`
- **Files changed:** 2 (1 new, 1 modified)
  - `src/Concerns/Billable.php` (modified — adds `createGmbPayCustomer()` method)
  - `tests/Billable/CreateGmbPayCustomerTest.php` (new)
- **Lines:** +90 / -0
- **Complexity:** Low — single `firstOrCreate` call
- **Notes:**
  - **`firstOrCreate` semantics** make the call safe to invoke repeatedly. Calling on every login or every checkout returns the existing row instead of throwing on the `(billable_type, billable_id, driver)` unique constraint. Test (c) and (d) lock that the second call returns the same row AND doesn't overwrite the original `metadata`
  - **`metadata` is `firstOrCreate`'s "create-only" payload** — passed as the second argument, it's only persisted when the row is created. Subsequent calls with different metadata silently keep the original. If a caller wants to update metadata they should mutate the model directly (`$customer->update(['metadata' => $new])`) — explicit beats firstOrCreate-as-update
  - **`provider_customer_id` stays null** — F21 is local-only per the original spec. F22 (or a follow-up) is where a real driver call to `/v1/customers` (Modempay) lands and back-fills this column on first charge
  - **Default driver from config** is read via `config('gmb-pay.default', 'modempay')` so apps that flip their gmb-pay default at runtime get the right driver here too. The `(string)` cast guards against config returning `null`

## F20 — Billable trait — gmbPayCustomers + gmbPayCharges ✓
- **Tests:** 4/4 passing (full suite 116/116) — `vendor/bin/pest`
- **Files changed:** 3 (1 new, 2 modified)
  - `src/Concerns/Billable.php` (new — two relations: `gmbPayCustomers(): MorphMany` and `gmbPayCharges(): HasManyThrough`)
  - `tests/Fixtures/Models/FakeBillable.php` (modified — `use Billable;`)
  - `tests/Billable/BillableTraitTest.php` (new)
- **Lines:** +110 / -2
- **Complexity:** Low — two relation methods, one fixture line
- **Notes:**
  - **Spec phrasing was loose** — `all-features.md` said `gmbPayCharges()` is a "morphMany", but the schema only puts a polymorphic `billable_*` pair on the `gmb_pay_customers` table, not on `gmb_pay_charges`. Charges link to a Billable transitively through `customer_id`, so the correct relation is `HasManyThrough(Charge, Customer)`. Updated the `all-features.md` line text to reflect this. Adding polymorphic columns to `gmb_pay_charges` would let us flatten the relation but the migration cost isn't worth it pre-1.0
  - **`->where('gmb_pay_customers.billable_type', $this->getMorphClass())`** prevents two different Billable classes (e.g. `User` and `Organization`) with overlapping numeric primary keys from cross-contaminating each other's charges. The morph-class filter is applied at the relation level, so eager-loaded collections also stay scoped
  - **Orphan charges** (`customer_id = null`) are *intentionally* invisible to `gmbPayCharges()` — those represent one-shot phone payments where the merchant didn't create a Customer record. They're still in `gmb_pay_charges` and reachable by direct query; just not from a Billable. Test (c) locks this
  - **Trait is side-effect-free** — no boot hooks, no observer registration, no DB writes. `use Billable` just attaches the two relation methods. Safe to add to existing `User` models without migrations or behavior change
  - F21 (next) will add `createGmbPayCustomer()` to this trait. F22 will wrap `GmbPay::charge()` and persist Charge rows linked to the Billable's Customer — at that point the asymmetry F12 left (no Charge persistence on the no-idempotency-key path) gets resolved through this Billable layer

## F19 — ModempayDriver::parseWebhook for wrapped payloads ✓
- **Tests:** 13/13 passing (full suite 112/112) — `vendor/bin/pest`
- **Files changed:** 2 (1 new, 1 modified)
  - `src/Drivers/Modempay/ModempayDriver.php` (modified — adds `parseWebhook()` override + private `webhookEventTypeFromModempay()` match table)
  - `tests/Drivers/Modempay/ModempayWebhookParseTest.php` (new — 13 cases including an event-mapping data set and an end-to-end POST that drives the F08 listener)
- **Lines:** +130 / -0
- **Complexity:** Low — single override + a 10-line `match` table
- **Notes:**
  - **Composite `provider_event_id`**: Modempay's webhook payload has no separate webhook event id (only the resource id, `payload.id`, which is the same across the resource's lifecycle). To avoid `(driver, provider_event_id)` collisions when the same resource emits `charge.created` then `charge.succeeded`, we synthesize `provider_event_id = "{event}:{payload.id}"`. The F07 dedup test still proves null `provider_event_id`s are treated as distinct, so legacy webhook bodies (which don't go through this branch) are unaffected
  - **`provider_reference = payload.payment_intent_id`** — this matches what F14 stores in `Charge::$provider_reference` after creating an intent, so the F08 listener's `where('provider_reference', $dto->providerReference)` lookup reconciles correctly. The end-to-end test (c) proves this loop by POSTing a wrapped `charge.succeeded`, asserting the matching Pending Charge becomes Succeeded
  - **Backward-compat fall-through**: when the body doesn't have a string `event` *and* an array `payload`, the override calls `parent::parseWebhook($request)` — preserving the F07/F08/F09/F10 controller tests that still POST flat `{"id","type"}` shapes. Test (d) locks this path
  - **Event mapping aliases**: `payment_intent.cancelled` and `payment_intent.expired` map to the same `WebhookEventType` cases as their `charge.*` siblings (`ChargeCancelled` / `ChargeFailed`) because the two event streams describe the same lifecycle from the merchant's perspective. Same for `transfer.reversed` → `PayoutFailed` (a reversal IS a payment failure after the fact, even if the provider considers it a separate concept)
  - **Phase D now closed** for everything not blocked. F16 (refund) remains in `tasks/todo.md ## Blocked`; revisit when Modempay exposes a refund endpoint or merchant onboarding clarifies the path

## F18 — Modempay webhook signature verification (HMAC-SHA512) ✓
- **Tests:** 5/5 passing (full suite 99/99) — `vendor/bin/pest`
- **Files changed:** 3 (1 new, 1 modified, 1 plan correction)
  - `src/Drivers/Modempay/ModempayDriver.php` (modified — adds `webhookSignatureValid()` override)
  - `tests/Drivers/Modempay/ModempayWebhookSignatureTest.php` (new)
  - `tasks/all-features.md` (corrected F18 line text: HMAC-SHA512, header `x-modem-signature`)
- **Lines:** +90 / -1
- **Complexity:** Low — five-line method, straight `hash_hmac` + `hash_equals`
- **Notes:**
  - **Spec correction** caught during F15 doc-reading: Modempay uses HMAC-**SHA512** (not SHA256 as the original F18 line claimed) over the raw JSON body, header `x-modem-signature` (no `X-` prefix in the docs, but Laravel header lookups are case-insensitive so either case works). Updated the F18 line in `all-features.md` so a fresh reader sees the right algorithm
  - **`$request->getContent()` is the raw body** — must be used, not `$request->all()` / `$request->json()->all()`. Laravel's JSON canonicalization strips whitespace and re-encodes, which would change the bytes the hash is computed over and break verification. Test (a) signs and verifies the exact same `$body` string to lock this
  - **Empty `webhook_secret` returns false** rather than `hash_equals('','x') === false` accidentally accepting empty input. Test (d) protects against a misconfigured install silently accepting any request
  - **Demo mode parity:** the override still returns `true` in demo to match `AbstractDriver::webhookSignatureValid()` — local dev and tests can replay webhooks without computing signatures
  - F19 is next: Modempay payload shape is `{"event": "<type>", "payload": {...}}`, not the flat `{"id", "type", ...}` `AbstractDriver::parseWebhook()` currently assumes. The F19 override will re-key onto `payload.id` / event-string / `payload.payment_intent_id` so the F08/F09 listeners reconcile correctly

## F17 — ModempayDriver::payout() via /v1/transfers ✓
- **Tests:** 8/8 passing (full suite 94/94) — `vendor/bin/pest`
- **Files changed:** 3 (1 new, 2 modified)
  - `src/Enums/PayoutStatus.php` (modified — added `Cancelled = 'cancelled'`)
  - `src/Drivers/Modempay/ModempayDriver.php` (modified — `payout()` override + private `statusFromModempayPayout()`)
  - `tests/Drivers/Modempay/ModempayDriverPayoutTest.php` (new)
- **Lines:** +150 / -1
- **Complexity:** Low — same shape as F14, except the body is flat (not wrapped in `data`)
- **Notes:**
  - **F16 was marked BLOCKED first** (separate `docs:` commit `f5e1fbe`) — Modempay's public docs document a `refunded` transaction status but no endpoint to create one. F17 went next in the Phase D order so Phase D keeps progressing
  - **`network` lives in `PayoutRequest::$metadata['network']`**, not as a top-level DTO property. Modempay requires it (mobile-money provider code like `africell`, `qcell`, `gamcel`) but other drivers won't, so promoting it to the DTO would be invasive. Driver throws a `GmbPayException` *before* any HTTP call when it's missing — test (c) locks the no-network-no-request invariant
  - **Body is flat, not `data`-wrapped** — different from `/v1/payments`. Easy to miss; the test explicitly asserts `($req['data'] ?? null) === null` to guard against accidentally copying F14's shape
  - **Payout status mapping**: `completed → Succeeded`, `failed → Failed`, `cancelled → Cancelled`, everything else → `Pending`. The `Cancelled` case wasn't in `PayoutStatus` before; added it as part of this feature
  - **Response wrapping tolerance** (same pattern as F15): tries `$response->json('data')` first, falls back to flat. Modempay's `/v1/transfers` example didn't show a literal response wrapper either way
  - **No capability split** (`SupportsPayouts` interface) introduced here. Modempay supports payouts, so we just implement; the split becomes worthwhile when a non-payout driver lands (Waychit may not). Defer to that feature

## F15 — ModempayDriver::verify() real implementation ✓
- **Tests:** 10/10 passing (full suite 86/86) — `vendor/bin/pest`
- **Files changed:** 2 (1 new, 1 modified)
  - `src/Drivers/Modempay/ModempayDriver.php` (modified — adds `verify()` override + extracts a shared `throwIfNotSuccessful()` helper that both `charge()` and `verify()` now use)
  - `tests/Drivers/Modempay/ModempayDriverVerifyTest.php` (new — six `it()` blocks including a `with([...])` data set across all five known Modempay statuses)
- **Lines:** +145 / -8
- **Complexity:** Low — same shape as F14, just GET with a query param
- **Notes:**
  - **`$reference` is `intent_secret`, not the payment-intent UUID.** Discovered while reading `/documentation/payment-intents/management`: the verify endpoint is `GET /v1/payments/verify?intent_secret=<...>`, keyed by Modempay's short-lived verifier token (returned in F14's `ChargeResult.raw.intent_secret`). The long-lived UUID id (what F14 stores in `provider_reference`) is what cancel/refund/webhook flows use. The intent_secret/UUID split is a Modempay-specific quirk worth surfacing in the driver docblock when F19 lands and we know the full picture
  - **Response wrapping is tolerated either way.** The Modempay docs for verify only describe the response in prose ("returns Payment Intent object including status, amount, currency, description, link, customer") with no literal JSON example. The driver tries `$response->json('data')` first and falls back to `$response->json()` if that's null — a flat shape and a `data`-wrapped shape both work. Test (d) locks the flat-shape parser
  - **Status map is shared with F14** via the existing private `statusFromModempay()` helper. F14's `default => Pending` already catches the verify-specific status `initialized` correctly (the test asserts this), so no map change was needed
  - **Error mapping was extracted** out of `charge()` into a private `throwIfNotSuccessful(Response, string $operation): void`. Both `charge()` and `verify()` now call it. The error message is templated as `"Modempay <operation> failed (HTTP <status>): <message>"` so callers can disambiguate
  - **`providerReference` is set to `null` from verify.** The verify response doesn't expose the PI UUID id (just `link`, which contains the UUID but parsing it out here would create two sources of truth). Callers who need the UUID should fetch the original `ChargeResult` from F14 or use the webhook reconciliation path
  - **Carry-overs for later Phase-D features:** while planning F15 the webhook docs revealed two things that affect F18/F19 — (1) Modempay webhook payloads are wrapped as `{"event": "<type>", "payload": {...}}`, not the flat shape `AbstractDriver::parseWebhook()` currently assumes (F07 default); (2) Modempay's webhook signature is **HMAC-SHA512** over the raw body, header `x-modem-signature` — F18's plan currently says SHA256, update that before implementing

## F14 — ModempayDriver::charge() real implementation ✓
- **Tests:** 9/9 passing (full suite 76/76) — `vendor/bin/pest`
- **Files changed:** 3 (1 new, 2 modified)
  - `src/Drivers/Modempay/ModempayDriver.php` (modified — adds `ModempayClient` constructor dep + `charge()` override + private `client()` lazy factory + private `statusFromModempay()`)
  - `src/PaymentManager.php` (modified — `createModempayDriver()` builds a `ModempayClient` from the driver config block)
  - `tests/Drivers/Modempay/ModempayDriverChargeTest.php` (new — five `it()` blocks, one of which is a `with([...])` data set covering all five Modempay status strings)
- **Lines:** +160 / -3
- **Complexity:** Medium — first feature against a real provider API; required reading Modempay's `/documentation/payment-intents/create` + `/overview` + `/authentication` pages to lock the request/response shape and status vocabulary
- **Notes:**
  - **Request body is wrapped in `"data"`** at this endpoint (Modempay's house style — not Stripe-flat). The client sends `{"data": {amount, currency, description, return_url, cancel_url, metadata, from_sdk: false}}`. Easy to forget when copying from a flat-body integration
  - **`customer_phone/email/name` from `ChargeRequest` are intentionally not forwarded.** Modempay's `/v1/payments` doesn't accept those at the intent level — they belong on a Customer resource. The hosted checkout (`payment_link`) collects what it needs; F21 will add an explicit `/v1/customers` pre-create flow so callers can associate a Modempay Customer UUID
  - **Provider reference extraction is heuristic.** The 2xx response shape we have shows `intent_secret` + `payment_link` but no top-level `id`. We extract the trailing UUID from `payment_link` via `Str::afterLast($paymentLink, '/')` and store it as `providerReference` so webhooks (F19) can reconcile. Switch to a real `id` field if Modempay surfaces one
  - **Status map** is inline in the driver: `successful` → Succeeded, `failed` → Failed, `cancelled` → Cancelled, everything else (`requires_payment_method`, `processing`, unknown) → Pending. F15 (`verify`) will reuse this; if duplication surfaces, extract to `ModempayStatusMap`
  - **Demo mode is preserved**: the override checks `$this->isDemo()` and falls through to `parent::charge($request)` (the AbstractDriver stub). SmokeTest still gets a `https://demo.local/checkout/...` URL and `Http::assertNothingSent()` passes in the demo test case
  - **4xx error mapping** reads `response.message` from the JSON body when present, otherwise falls back to the raw body. Message is prefixed with `"Modempay charge failed (HTTP {status}): "` so callers can grep
  - **`ModempayClient` is constructor-injected** but nullable, with a private `client()` lazy factory as a fallback (uses `config['base_url']` + `config['secret_key']` + `config['timeout_seconds']`). Lets the driver be hand-instantiated in tests without `PaymentManager`. The PaymentManager path always passes a real client through
  - **`array_filter` on the payload** drops null fields (no `description` when caller didn't supply one, etc), which keeps the wire format clean. Metadata is dropped when it's an empty array — Modempay's example showed metadata as an object, so we don't send `{}`

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
