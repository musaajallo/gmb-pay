# TODO

## Active: F56 — GitHub Actions CI workflow

Matrix PHP {8.3, 8.4} × Laravel {11, 12, 13}. Steps: composer install, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `vendor/bin/pest`. Commit `F56: GitHub Actions CI workflow`.

Comprehensive rewrite covering install, Billable trait API, one-shot charges, subscriptions, webhooks (auto-registered listeners table), refunds (with Modempay-block note), demo mode, Modempay-specific quirks, troubleshooting. Replaces the scaffold-era README. No tests — this is docs.

Commit `F59: README expansion`.

**Goal:** Mirror of F37 for the failure path. When `charge.failed` arrives for a Charge tied to a subscription's invoice, mark the subscription `PastDue` and dispatch `RetryFailedChargeJob`. F33's retry chain handles the backoff and eventual cancellation. Closes Phase G.

### Steps

1. **RED — write the test first** at `tests/Webhook/RetryChargeFromWebhookTest.php`:
   - `Bus::fake()` in `beforeEach`
   - Test (a): Active sub with Open invoice + Charge. POST `charge.failed` webhook → sub flips to `PastDue`; one `RetryFailedChargeJob` dispatched for that sub
   - Test (b): orphan Charge (no Invoice) → no sub mutation, no retry dispatched
   - Test (c): Charge linked to invoice but no Subscription (defensive) → no exception, no dispatch
2. **Implement** `src/Listeners/RetryChargeFromWebhook.php`:
   - Skip when not `ChargeFailed`
   - Look up Charge, Invoice, Subscription chain
   - `$sub->markPastDue()`; `RetryFailedChargeJob::dispatch($sub)`
3. **Register** in `GmbPayServiceProvider::boot()` — fourth listener in the auto_register block
4. Run pest. Tick F38. Done entry. Commit `F38: RetryChargeFromWebhook listener — closes Phase G`

### Files this feature will touch

- `src/Listeners/RetryChargeFromWebhook.php` (new)
- `src/GmbPayServiceProvider.php` (modified — register listener)
- `tests/Webhook/RetryChargeFromWebhookTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry — Phase G summary)

### Done criteria

- All Pest tests pass (full suite green)
- Subscription is marked PastDue exactly once per failure; RetryFailedChargeJob is dispatched once
- Orphan charges (no linked invoice or subscription) silently no-op

### Notes for the implementer

- `RetryFailedChargeJob` accepts the subscription + an `attempt` parameter (defaults to 1). F38 dispatches with the default — F33's job manages the attempt counter from there
- Phase G closes with F38. Phases F (subs schema) + G (engine) are both done. Webhooks reconcile both success and failure paths end-to-end

**Goal:** When a `charge.succeeded` webhook arrives for a Charge that's linked to an Invoice, flip the Invoice to `Paid`. If the parent Subscription was `PastDue`, recover it to `Active` and re-advance `current_period_*` (a successful retry restarts the cycle clock). Normal-flow subs (already Active) get the Invoice paid but no period change — F32 already advanced the period optimistically at cycle dispatch time.

### Steps

1. **Plan helper** — add `Plan::nextPeriodEnd(Carbon $start): Carbon` static-ish instance method that returns `$start + interval * interval_count`. Refactor `InitiateRecurringChargeJob::addInterval()` to call it; remove the private helper. F32 + F37 both go through one source of truth
2. **RED — write the test first** at `tests/Webhook/MarkInvoicePaidFromWebhookTest.php`:
   - Auto-register is on by default (F10) so the listener fires automatically when `WebhookReceived` dispatches
   - Test (a, normal flow): Active subscription, Open invoice linked to a Charge. POST a wrapped Modempay `charge.succeeded` webhook (using F19's shape) referencing `payment_intent_id` matching the Charge's `provider_reference`. Assert Invoice flips to `Paid`. Subscription stays `Active`, `current_period_end` unchanged
   - Test (b, past_due recovery): PastDue subscription with an Open invoice. After the webhook: Invoice `Paid`, Subscription `Active`, `current_period_start ≈ now`, `current_period_end ≈ now + plan.interval`
   - Test (c, orphan): charge.succeeded for a Charge with NO linked Invoice → invoice count stays 0; no exception; F08's listener still updates the Charge to Succeeded (already covered by F08 tests, just don't regress)
3. **Implement** `src/Listeners/MarkInvoicePaidFromWebhook.php` mirroring F08's shape:
   - Skip when not `WebhookEventType::ChargeSucceeded`
   - Look up Charge by `(driver, provider_reference)`
   - Find Invoice by `charge_id`; if none, return
   - `$invoice->update(['status' => InvoiceStatus::Paid])`
   - Load `$invoice->subscription`. If `PastDue`: flip to Active + `current_period_start = now`, `current_period_end = $sub->plan->nextPeriodEnd(now())`
4. **Register** in `GmbPayServiceProvider::boot()` — add `Event::listen(WebhookReceived::class, MarkInvoicePaidFromWebhook::class)` inside the existing `auto_register` block
5. Run pest. Tick F37. Done entry. Commit `F37: MarkInvoicePaidFromWebhook listener — invoice paid + past_due recovery`

### Files this feature will touch

- `src/Models/Plan.php` (modified — `nextPeriodEnd()` helper)
- `src/Jobs/InitiateRecurringChargeJob.php` (modified — use the new helper, drop private `addInterval`)
- `src/Listeners/MarkInvoicePaidFromWebhook.php` (new)
- `src/GmbPayServiceProvider.php` (modified — register listener)
- `tests/Webhook/MarkInvoicePaidFromWebhookTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green)
- Active-flow webhooks mark the Invoice Paid without re-advancing the period (F32 already did)
- PastDue-recovery webhooks flip the sub to Active AND advance the period
- Orphan charges (no Invoice) don't trip the listener — `where('charge_id')->first()` returns null and we return silently

### Notes for the implementer

- F08's `UpdateChargeFromWebhook` listener still runs alongside this one (both registered). The order doesn't matter — F08 just updates Charge.status; F37 reads the Charge's id (which it already had) and works on the Invoice/Subscription side
- The PastDue recovery path is the canonical "their retry succeeded" flow — pairs with F33's RetryFailedChargeJob and F38's webhook → retry trigger

**Goal:** When `gmb-pay:install` finishes, the "Next steps" output points the user at scheduling the cycle command in their `routes/console.php`. Docs-only feature.

### Steps

1. **RED — write the test first** at `tests/Console/InstallCommandTest.php`:
   - Run `Artisan::call('gmb-pay:install', ['--no-migrate' => true])`; capture `Artisan::output()`; assert it contains the literal string `gmb-pay:cycle` and `everyFiveMinutes` and `routes/console.php`
2. **Implement** — extend InstallCommand's "Next steps" section with one more line:
   ```php
   $this->line('  5. Schedule the cycle command in routes/console.php:');
   $this->line("       Schedule::command('gmb-pay:cycle')->everyFiveMinutes();");
   ```
3. Run pest. Tick F36. Done entry. Commit `F36: install-command schedule hint`

### Files this feature will touch

- `src/Console/InstallCommand.php` (modified — extend Next steps output)
- `tests/Console/InstallCommandTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- Pest output capture matches the literal scheduling hint
- The install command still exits successfully end-to-end (`--no-migrate` to avoid running real migrations during the test)

**Goal:** Same `gmb-pay:cycle` command, second loop: `PastDue` subscriptions whose `updated_at` is older than `gmb-pay.subscriptions.grace_days` (default 3) get `markCanceled()`. Uses `updated_at` as a proxy for "PastDue since" — see notes for the tradeoff.

### Steps

1. **RED — write the test first** at `tests/Console/CycleGraceTest.php`:
   - `Bus::fake()`
   - Test (a): a `PastDue` sub whose `updated_at` is 5 days ago + `grace_days = 3` → after `gmb-pay:cycle`, status flips to `Canceled` and `canceled_at` is set
   - Test (b): a `PastDue` sub whose `updated_at` is 1 day ago + `grace_days = 3` → after the command, still `PastDue` (within grace)
   - Test (c): `Active` subs are not affected by the grace loop — combined test with one Active+due (gets a dispatch from F34) and one PastDue+stale (gets canceled from F35); both happen in one command run
2. **Implement** in `src/Console/CycleCommand::handle()`:
   - After the existing Active loop, run:
     ```php
     $graceDays = (int) config('gmb-pay.subscriptions.grace_days', 3);
     Subscription::query()
         ->where('status', SubscriptionStatus::PastDue)
         ->where('updated_at', '<=', now()->subDays($graceDays))
         ->each(fn (Subscription $sub) => $sub->markCanceled());
     ```
3. Run pest. Tick F35. Done entry. Commit `F35: gmb-pay:cycle grace-period enforcer`

### Files this feature will touch

- `src/Console/CycleCommand.php` (modified — adds second loop)
- `tests/Console/CycleGraceTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green)
- A PastDue sub stale by > grace_days is canceled; one within grace stays PastDue
- Active subs are untouched by the grace loop (test c)

### Notes for the implementer

- **`updated_at` as a "PastDue since" proxy** is the v1 cut. It works as long as nothing else touches the sub after `markPastDue()` — which is true in our current flows (F33's retry exhaustion calls `markPastDue` and is the only thing that should write to it during the past_due window). If a future code path (e.g. F37 webhook listener) ever updates a past_due sub before grace passes, we'd need a dedicated `past_due_since` column. Note in done.md as a known limitation
- Setting Subscription's `updated_at` directly in tests requires `$sub->forceFill(['updated_at' => now()->subDays(5)])->save()` after creation — Eloquent overwrites it on save unless you force-set

**Goal:** A scheduled command that walks every `Active` Subscription whose `current_period_end <= now()` and dispatches `InitiateRecurringChargeJob` for each. The actual scheduling (e.g. `everyFiveMinutes`) lands in the app's `routes/console.php` per F36's docs; F34 just delivers the command.

### Steps

1. **RED — write the test first** at `tests/Console/CycleCommandTest.php`:
   - `Bus::fake()` in `beforeEach`
   - Test (a): one Active subscription with `current_period_end` in the past → exactly one `InitiateRecurringChargeJob` is dispatched, for that subscription
   - Test (b): one Active subscription with `current_period_end` in the future → no dispatch
   - Test (c): subscriptions in non-Active statuses (Canceled, Incomplete, PastDue, Paused) with due periods are skipped
   - Test (d): command exits with status `0` (success) regardless of how many subs were processed
2. **Implement** `src/Console/CycleCommand.php`:
   - `protected $signature = 'gmb-pay:cycle';`
   - `handle(): int` — query Active subscriptions due now, `each()` dispatches `InitiateRecurringChargeJob`. Return `self::SUCCESS`
3. **Register** in `src/GmbPayServiceProvider::boot()` — add `CycleCommand::class` to the `$this->commands([...])` list alongside `InstallCommand`
4. Run pest. Tick F34. Done entry. Commit `F34: gmb-pay:cycle Artisan command`

### Files this feature will touch

- `src/Console/CycleCommand.php` (new)
- `src/GmbPayServiceProvider.php` (modified — register `CycleCommand`)
- `tests/Console/CycleCommandTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the four new cases)
- Only `Active` subs with `current_period_end <= now()` get a dispatch — every other status or future-due sub is silently skipped
- The composite index `(status, current_period_end)` from F26 backs the query

### Notes for the implementer

- F35 (grace-period enforcer) extends THIS command — when it lands, the same `handle()` will also walk `PastDue` subs and `markCanceled()` if their `updated_at` is older than `grace_days`. Keep `handle()`'s shape so F35 can add a second loop without restructuring
- `each()` (vs `get()->each()`) chunks lazily — fine for v1 where subs counts are small; if it ever scales, switch to `chunkById()`

**Goal:** A queueable retry wrapper around `InitiateRecurringChargeJob`. On each invocation: try the charge; if it throws, dispatch self again with the next delay from `gmb-pay.subscriptions.retry_backoff_minutes` (defaults `[60, 360, 1440]` minutes — 1h, 6h, 24h); after the schedule is exhausted, mark the Subscription `PastDue` (F35 will move that to `Canceled` if grace days pass).

### Steps

1. **RED — write the test first** at `tests/Jobs/RetryFailedChargeJobTest.php`:
   - `beforeEach`: `Bus::fake()` to capture re-dispatches
   - Test (a, exhausted): `attempt = count(backoffs) + 1`. Run job. Subscription is marked `PastDue`; no self-redispatch
   - Test (b, charge throws): bind a `PaymentManager` stub whose `driver()` returns a driver that throws `RuntimeException` on `charge()`. Run job at `attempt = 1`. Self is re-dispatched with `attempt = 2` and delay of 60 minutes (first backoff); subscription stays `Incomplete` (not yet PastDue)
   - Test (c, charge succeeds): in demo mode the inner charge succeeds. Run job at `attempt = 1`. No re-dispatch, no PastDue. (Charge + Invoice get persisted by the inner `InitiateRecurringChargeJob` — we don't assert that here since F32 already covered it; just assert there's no retry pending)
2. **Implement** `src/Jobs/RetryFailedChargeJob.php`:
   - Queueable, `__construct(public Subscription $subscription, public int $attempt = 1)`
   - `handle()`:
     - `$backoffs = (array) config('gmb-pay.subscriptions.retry_backoff_minutes', [60, 360, 1440]);`
     - If `$this->attempt > count($backoffs)`: `$this->subscription->fresh()?->markPastDue(); return;`
     - `try { (new InitiateRecurringChargeJob($this->subscription))->handle(); }`
     - `catch (\Throwable $e) { $delay = $backoffs[$this->attempt - 1] ?? end($backoffs); self::dispatch($this->subscription, $this->attempt + 1)->delay(now()->addMinutes($delay)); }`
3. Run pest. Tick F33. Done entry. Commit `F33: RetryFailedChargeJob with backoff schedule`

### Files this feature will touch

- `src/Jobs/RetryFailedChargeJob.php` (new)
- `tests/Jobs/RetryFailedChargeJobTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green)
- Exhausted-attempt path marks Subscription `PastDue` without re-dispatching
- Failure path re-dispatches with the right delay; success path does neither

### Notes for the implementer

- F38 (webhook listener) is the eventual caller — it dispatches `RetryFailedChargeJob` when a `charge.failed` webhook arrives. F33 just defines the retry shape
- The test's failing-driver stub: `$this->app->instance(PaymentManager::class, $mockManager)` then have `$mockManager->driver(...)` return a driver whose `charge()` throws. Anonymous classes implementing `PaymentDriver` work — only `charge()` needs to throw, the other methods can `notImplemented()` since they won't be called

**Goal:** Fill in `InitiateRecurringChargeJob::handle()` with the first-cycle work. Two paths:
1. **Trial path** — when `subscription->onTrial()` is true, no driver call. Set `status=Active`, `current_period_start = now`, `current_period_end = trial_ends_at`. No Charge, no Invoice.
2. **Live path** — compute total from items (`sum(quantity * unit_amount_minor)`), build a `ChargeRequest`, call `$driver->charge()`, persist a `Charge` linked to the billable's Customer for that driver, persist an `Invoice` (status `Open`, linked to the subscription + charge), advance subscription to `Active` with `current_period_start = now`, `current_period_end = now + plan.interval * plan.interval_count`.

### Steps

1. **RED — write the test first** at `tests/Jobs/InitiateRecurringChargeJobTest.php`:
   - `beforeEach`: demo mode (default)
   - Test (a, trial path): create a Subscription with `trial_ends_at = now + 14d` (and `Incomplete` status). Dispatch the job synchronously (`$job->handle()` or `dispatch_sync`). Assert: status flips to `Active`, `current_period_start ≈ now`, `current_period_end ≈ trial_ends_at`. `Charge::count() === 0`, `Invoice::count() === 0`
   - Test (b, live path): create a Subscription with no trial, one item at 5000, Plan interval=Month. Run job. Assert: status `Active`, `current_period_start ≈ now`, `current_period_end ≈ now + 1mo`. Exactly one `Charge` row with `amount_minor = 5000`, linked to a Customer for this billable+driver. Exactly one `Invoice` (status `Open`, linked to that Charge, period dates matching the subscription)
   - Test (c, multi-item total): subscription with two items (one at 3000, one at 2000 with quantity 2 → 7000 total). Live path. Charge amount = 3000 + 2*2000 = 7000
2. **Implement** `src/Jobs/InitiateRecurringChargeJob::handle()`:
   - `$sub = $this->subscription->fresh(['plan', 'items', 'billable']);` — reload with relations
   - If `$sub->onTrial()`:
     - Update `current_period_start = now`, `current_period_end = trial_ends_at`, `status = Active`. Return
   - Live path:
     - Compute `$amount = $sub->items->sum(fn ($i) => $i->quantity * $i->unit_amount_minor)`
     - Compute period end via a private helper `addInterval(Carbon $start, PlanInterval $interval, int $count): Carbon` (uses `addDays`/`addWeeks`/`addMonths`/`addYears`)
     - Resolve the driver, build `ChargeRequest(amountMinor: $amount, currency: $sub->plan->currency, customerPhone: '')`
     - `$result = $driver->charge($request);`
     - Persist a `Charge` row linked to `Customer` via `$sub->billable->createGmbPayCustomer($sub->driver)` (existing F21 helper); reuse the `_gmbpay_*` metadata stash from F22
     - Persist an `Invoice` row: `subscription_id`, `charge_id`, `amount_minor`, `currency`, `status = Open`, `period_start`, `period_end`
     - Update Subscription: `status = Active`, period dates set
3. Run pest. Tick F32. Done entry. Commit `F32: InitiateRecurringChargeJob real handle()`

### Files this feature will touch

- `src/Jobs/InitiateRecurringChargeJob.php` (modified — fill `handle()`, add private interval helper)
- `tests/Jobs/InitiateRecurringChargeJobTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the three new cases)
- Trial-path subscriptions don't produce a Charge or Invoice on the first cycle
- Live-path subscriptions produce exactly one Charge + one Invoice; the Invoice references both the subscription and the charge

### Notes for the implementer

- Trial-path advances `status` to `Active` so F30's `subscribed()` returns true for trialing users. `onTrial()` still reports the trial state via `trial_ends_at`. Two orthogonal flags
- The driver call can fail (in non-demo mode) — let the exception bubble. F33's `RetryFailedChargeJob` will rescue. F32 doesn't catch
- Reusing `createGmbPayCustomer()` on the billable is the right idempotent attach pattern

**Goal:** Add eight lifecycle methods to the `Subscription` model so callers don't have to know the underlying status enum. Closes Phase F.

### Methods

| Method | Behavior |
|---|---|
| `cancel(): self` | `status = Canceled`, `canceled_at = now()`, `cancel_at_period_end = false`. Save. Return `$this` |
| `cancelAtPeriodEnd(): self` | `cancel_at_period_end = true`. Status unchanged. Save. Return `$this` |
| `resume(): self` | If `status === Canceled`, flip back to `Active` and null `canceled_at`. Always clear `cancel_at_period_end`. Save. Return `$this` |
| `onTrial(): bool` | `trial_ends_at !== null && trial_ends_at > now()` |
| `pastDue(): bool` | `status === PastDue` |
| `active(): bool` | `status === Active` |
| `markPastDue(): self` | `status = PastDue`. Save. Return `$this` |
| `markCanceled(): self` | Alias for `cancel()` — semantically "system-initiated" vs user-initiated cancel. Same DB effect |

### Steps

1. **RED — write the test first** at `tests/Persistence/SubscriptionLifecycleTest.php`:
   - Test (a): `cancel()` sets status + canceled_at + clears cancel_at_period_end
   - Test (b): `cancelAtPeriodEnd()` flips the flag without touching status/canceled_at
   - Test (c): `resume()` from Canceled → Active + nulls canceled_at; from cancel_at_period_end=true → flag cleared, status unchanged (Active)
   - Test (d): `onTrial()` true when `trial_ends_at` is in the future; false when in past or null
   - Test (e): `active()`/`pastDue()` reflect the status enum
   - Test (f): `markPastDue()` and `markCanceled()` change status as documented
2. **Implement** all eight methods on `src/Models/Subscription.php`
3. Run pest. Tick F31. Done entry calling out that Phase F closes here. Commit `F31: Subscription lifecycle helpers — closes Phase F`

### Files this feature will touch

- `src/Models/Subscription.php` (modified)
- `tests/Persistence/SubscriptionLifecycleTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry — Phase F summary)

### Done criteria

- All Pest tests pass (full suite green)
- Every helper persists its mutations (no in-memory-only changes)
- `markCanceled()` and `cancel()` produce identical DB state

### Notes for the implementer

- `markCanceled()` aliasing `cancel()` is fine — both produce a "Canceled" subscription. The naming difference signals intent (user-initiated vs system-initiated) to readers; behaviour is identical
- `resume()` deliberately handles both reactivate-from-Canceled and undo-cancel-at-period-end in one call. Callers don't need to know which state they were in
- These are model methods (`$sub->cancel()`), not static query helpers. Tests load a Subscription instance, call the method, then `->fresh()` to verify persistence

**Goal:** Two read-side helpers on the `Billable` trait. `subscriptions(): MorphMany` exposes all subscriptions for this billable (matches the existing `gmbPayCustomers`/`gmbPayCharges` style). `subscribed(?string $planSlug = null): bool` returns true when the billable holds at least one `Active` Subscription — optionally scoped to a specific plan slug.

### Steps

1. **RED — write the test first** at `tests/Billable/SubscribedTest.php`:
   - Test (a): `$billable->subscriptions` lists every Subscription tied to this billable (regardless of status). Two billables don't cross-contaminate
   - Test (b): `subscribed()` returns `true` when at least one Subscription with `status === Active` exists
   - Test (c): `subscribed()` returns `false` when the billable's only subscription is `Incomplete`, `Canceled`, `PastDue`, or `Paused` (not `Active`)
   - Test (d): `subscribed('pro-monthly')` returns `true` only when the active subscription is for plan `pro-monthly` specifically — an active sub for a different plan returns `false` for this scoped check
2. **Implement** in `src/Concerns/Billable.php`:
   - `subscriptions(): MorphMany` → `$this->morphMany(Subscription::class, 'billable')`
   - `subscribed(?string $planSlug = null): bool` — build a query: filter `status === SubscriptionStatus::Active`. When `$planSlug !== null`, join (or `whereHas`) on Plan to require the slug. Use `exists()` to short-circuit
3. Run pest, tick, done, commit `F30: Billable::subscriptions() + subscribed()`

### Files this feature will touch

- `src/Concerns/Billable.php` (modified — adds two read helpers)
- `tests/Billable/SubscribedTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the four new cases)
- `subscribed()` is single-query (uses `exists()`, no N+1 hydration)
- Scoping by plan slug works for the common "is user on Pro plan?" question

### Notes for the implementer

- For v1, "subscribed" means **`Active` only**. `PastDue` (within grace) doesn't count yet; that nuance can land with F35's grace enforcer if needed
- Use `whereHas('plan', fn ($q) => $q->where('slug', $planSlug))` for the plan-scoped variant — keeps the query a single-statement EXISTS on the SQL side

**Goal:** First business-logic feature in Phase F. `$billable->subscribeToPlan($planOrSlug, $opts)` creates a Subscription in `Incomplete`, attaches one default SubscriptionItem, optionally sets `trial_ends_at`, and dispatches `InitiateRecurringChargeJob` to kick off the first cycle. The job itself is a Queueable stub — F32 will fill in its handle() body.

### Steps

1. **InitiateRecurringChargeJob stub** at `src/Jobs/InitiateRecurringChargeJob.php`:
   - Standard Laravel Queueable shape: `use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;`
   - Constructor `__construct(public Subscription $subscription)`
   - `public function handle(): void {}` — empty for now. F32 fleshes it out
2. **RED — write the test first** at `tests/Billable/SubscribeToPlanTest.php`:
   - `Bus::fake()` in `beforeEach` so dispatches are captured
   - Test (a): pass a `Plan` model → creates a Subscription (`status === Incomplete`, `driver === modempay` from config default, `plan_id` matches) and one `SubscriptionItem` (`unit_amount_minor === $plan->amount_minor`, `quantity === 1`)
   - Test (b): pass a slug string → resolves Plan from DB
   - Test (c): plan with `trial_days > 0` → `trial_ends_at` is approximately `now() + trial_days` (within a 5-sec tolerance); plan with `trial_days = 0` → `trial_ends_at` is null
   - Test (d): an unknown plan slug raises `ModelNotFoundException`
   - Test (e): `InitiateRecurringChargeJob` is dispatched once with the created Subscription
   - Test (f): `$opts['driver']` overrides the config default
3. **Implement** `Billable::subscribeToPlan(Plan|string $plan, array $opts = []): Subscription`:
   - Resolve Plan: if string, `Plan::where('slug', $plan)->firstOrFail()`
   - `$driverName = $opts['driver'] ?? config('gmb-pay.default', 'modempay')`
   - `$trialEndsAt = $plan->trial_days > 0 ? now()->addDays($plan->trial_days) : null`
   - Create Subscription with `status: SubscriptionStatus::Incomplete`, `driver: $driverName`, `plan_id: $plan->id`, `trial_ends_at: $trialEndsAt`, billable polymorphic columns
   - Create one SubscriptionItem with `unit_amount_minor: $plan->amount_minor` (quantity defaults to 1)
   - `InitiateRecurringChargeJob::dispatch($subscription)`
   - Return the Subscription
4. Run pest. Tick F29. Done entry. Commit `F29: Billable::subscribeToPlan() + InitiateRecurringChargeJob stub`

### Files this feature will touch

- `src/Jobs/InitiateRecurringChargeJob.php` (new — Queueable stub)
- `src/Concerns/Billable.php` (modified — adds `subscribeToPlan()`)
- `tests/Billable/SubscribeToPlanTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green)
- A subscription with no trial has `trial_ends_at = null`; one with `trial_days = 14` has `trial_ends_at ≈ now() + 14d`
- The job is dispatched once per subscribe call; not creating it would cause F32 to lose its scheduled work

### Notes for the implementer

- Multiple subscriptions to the same plan are allowed (Stripe-like) — F29 doesn't dedup. Callers who want one-sub-per-billable-per-plan should check via `$billable->gmbPayCustomers`/manual query before calling
- Subscription starts `Incomplete`; F32 advances to `Active` after the first cycle's Charge succeeds (via the F37 webhook listener extension)
- Don't set `current_period_start`/`current_period_end` here — F32 sets them when the first charge fires (with the actual provider-confirmed timestamps in mind)

**Goal:** Each subscription cycle produces an Invoice. The Invoice carries the period dates and snapshots the amount due for that cycle. When the cycle's Charge succeeds, the Invoice flips to `paid` and links to the `charge_id`. F32 (`InitiateRecurringChargeJob`) creates Invoices; F37 (webhook listener extension) marks them `paid`.

### Steps

1. **InvoiceStatus enum** at `src/Enums/InvoiceStatus.php`:
   - `Open='open'`, `Paid='paid'`, `Uncollectible='uncollectible'`, `Void='void'`
2. **RED — write the test first** at `tests/Persistence/InvoiceTest.php`:
   - Test (a): table + columns (`subscription_id`, `charge_id` nullable, `amount_minor`, `currency`, `status`, `period_start`, `period_end`, timestamps)
   - Test (b): persists with casts — `status` to enum, `amount_minor` to int, `period_start`/`period_end` to datetimes; `charge_id` accepts null
   - Test (c): `subscription()` belongsTo + `charge()` belongsTo + `Subscription::invoices()` hasMany resolve
   - Test (d): deleting parent Subscription cascades to invoices; deleting linked Charge **nulls** `charge_id` (`nullOnDelete`) rather than cascading
3. **Migration** `database/migrations/2026_01_01_000010_create_gmb_pay_invoices_table.php`:
   - `id`, `foreignId('subscription_id')->constrained('gmb_pay_subscriptions')->cascadeOnDelete()`, `foreignId('charge_id')->nullable()->constrained('gmb_pay_charges')->nullOnDelete()`, `unsignedBigInteger('amount_minor')`, `string('currency', 3)`, `string('status', 32)`, `timestamp('period_start')`, `timestamp('period_end')`, `timestamps()`
   - Index on `(status, period_end)` for the cycle command's open-invoice sweep
4. **Invoice model** + add `invoices(): HasMany` to `Subscription`
5. Run pest, tick, done, commit `F28: gmb_pay_invoices migration + Invoice model`

### Files this feature will touch

- `src/Enums/InvoiceStatus.php` (new)
- `database/migrations/2026_01_01_000010_create_gmb_pay_invoices_table.php` (new)
- `src/Models/Invoice.php` (new)
- `src/Models/Subscription.php` (modified — `invoices()` hasMany)
- `tests/Persistence/InvoiceTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the four new cases above)
- Cascading delete from Subscription → Invoices is enforced (FK test exercises real cascade thanks to F27's `foreign_key_constraints` test config)
- Deleting a Charge that was linked to an Invoice nulls `invoice.charge_id` rather than dropping the invoice — paid history survives a Charge purge

### Notes for the implementer

- `currency` is duplicated on Invoice (already on the parent Plan) to freeze it at invoice-time. If a Plan's currency ever changes, historical invoices keep their original
- F32 will create invoices in `Open`; F37 flips to `Paid` on `charge.succeeded` webhook

**Goal:** Many-to-one child of Subscription that records `quantity` and `unit_amount_minor`. The subscription's total per cycle is `sum(items.quantity * items.unit_amount_minor)`. For the common single-plan case there will be exactly one item per subscription.

### Steps

1. **RED — write the test first** at `tests/Persistence/SubscriptionItemTest.php`:
   - Test (a): migrations create `gmb_pay_subscription_items` with columns `id, subscription_id, quantity, unit_amount_minor, created_at, updated_at`
   - Test (b): persists an item linked to a Subscription; `quantity` and `unit_amount_minor` cast to `int`
   - Test (c): `subscription()` belongsTo + `Subscription::items()` hasMany resolve correctly (need to add `items()` to the Subscription model)
   - Test (d): deleting the parent Subscription cascades — child items go away (`cascadeOnDelete()` on the FK)
2. **Migration** `database/migrations/2026_01_01_000009_create_gmb_pay_subscription_items_table.php`:
   - `id`, `foreignId('subscription_id')->constrained('gmb_pay_subscriptions')->cascadeOnDelete()`, `unsignedInteger('quantity')->default(1)`, `unsignedBigInteger('unit_amount_minor')`, `timestamps()`
3. **SubscriptionItem model** `src/Models/SubscriptionItem.php`:
   - `$casts = ['quantity' => 'int', 'unit_amount_minor' => 'int']`
   - `subscription(): BelongsTo`
4. **Subscription model** (`src/Models/Subscription.php`) — add `items(): HasMany` relation pointing to `SubscriptionItem::class`
5. Run `vendor/bin/pest`. Tick F27, append done.md entry, commit `F27: gmb_pay_subscription_items migration + SubscriptionItem model`

### Files this feature will touch

- `database/migrations/2026_01_01_000009_create_gmb_pay_subscription_items_table.php` (new)
- `src/Models/SubscriptionItem.php` (new)
- `src/Models/Subscription.php` (modified — add `items()` HasMany)
- `tests/Persistence/SubscriptionItemTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the four new cases above)
- Cascade delete from Subscription → items is observable (test d)

### Notes for the implementer

- `quantity` default 1 covers the dominant single-plan case so F29 can just `subscription->items()->create(['unit_amount_minor' => $plan->amount_minor])` and let quantity ride on its default
- No `currency` on items — items inherit it from `Subscription → Plan`. Keeping currency on a single source of truth avoids divergence

**Goal:** A subscription ties a Billable to a Plan via a chosen `driver`, tracks its lifecycle in `status` and its current period in `current_period_*`, and supports trials + scheduled cancellation. F31 layers in helper methods (`cancel()`, `resume()`, `markPastDue()`, etc.) — F26 is pure persistence.

### Steps

1. **SubscriptionStatus enum** at `src/Enums/SubscriptionStatus.php`:
   - Backed string enum: `Incomplete='incomplete'`, `Active='active'`, `PastDue='past_due'`, `Canceled='canceled'`, `Paused='paused'`
2. **RED — write the test first** at `tests/Persistence/SubscriptionTest.php`:
   - Test (a): migrations create `gmb_pay_subscriptions` with the columns from the spec
   - Test (b): persists a Subscription with billable polymorphic link, plan_id, driver, status, period dates, casts on read (`status` to enum, `cancel_at_period_end` to bool, `current_period_start/end` and `canceled_at` and `trial_ends_at` to datetimes)
   - Test (c): `billable()` morphTo and `plan()` belongsTo resolve correctly
   - Test (d): index on `(status, current_period_end)` exists (cheap to verify via `Schema::hasIndex` if available, or just `Schema::hasColumn` confirmation; the F25 prophylactic-index pattern carries over)
3. **Migration** `database/migrations/2026_01_01_000008_create_gmb_pay_subscriptions_table.php`:
   - `id`, `morphs('billable')`, `foreignId('plan_id')->constrained('gmb_pay_plans')->cascadeOnDelete()`, `string('driver', 32)`, `string('status', 32)`, `timestamp('current_period_start')->nullable()`, `timestamp('current_period_end')->nullable()`, `boolean('cancel_at_period_end')->default(false)`, `timestamp('canceled_at')->nullable()`, `timestamp('trial_ends_at')->nullable()`, `timestamps()`
   - Composite index on `(status, current_period_end)` to keep the F34 cycle command's "active and due" lookup cheap
4. **Subscription model** `src/Models/Subscription.php`:
   - Casts: `status` → `SubscriptionStatus::class`, `cancel_at_period_end` → `bool`, `current_period_start`/`current_period_end`/`canceled_at`/`trial_ends_at` → `datetime`
   - Relations: `billable(): MorphTo` and `plan(): BelongsTo`
5. Run `vendor/bin/pest`. Tick F26, append done.md entry, commit `F26: gmb_pay_subscriptions migration + Subscription model`

### Files this feature will touch

- `src/Enums/SubscriptionStatus.php` (new)
- `database/migrations/2026_01_01_000008_create_gmb_pay_subscriptions_table.php` (new)
- `src/Models/Subscription.php` (new)
- `tests/Persistence/SubscriptionTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the four new cases above)
- Subscription persists and loads with proper casts; polymorphic billable + belongsTo plan both round-trip
- The `(status, current_period_end)` composite index is present (will be exercised by F34's `where('status', Active)->where('current_period_end', '<=', now())`)

### Notes for the implementer

- No `quantity` or `unit_amount_minor` here — those live on `SubscriptionItem` rows (F27). A subscription describes the relationship; items describe what's being charged
- Status defaults to `incomplete` when first created (F29 creates the row before kicking off the first cycle); no DB default needed because F29 will set it explicitly
- Tests can lean on `FakeBillable` again as the polymorphic owner — same fixture used by F01's customer test

**Goal:** Open Phase F by laying down the `gmb_pay_plans` table and `Plan` Eloquent model. A Plan defines a recurring billable: `slug` (unique stable id), `name` (human-readable), `amount_minor`, `currency`, `interval` (`day|week|month|year`), `interval_count`, `trial_days`, `active`. No relationships at this layer — F26 wires Subscriptions to Plan via FK; F29 (`Billable::subscribeToPlan`) is where Plans get used.

### Steps

1. **PlanInterval enum** at `src/Enums/PlanInterval.php`:
   - `case Day = 'day'; case Week = 'week'; case Month = 'month'; case Year = 'year';`
2. **RED — write the test first** at `tests/Persistence/PlanTest.php` (mirrors F02 ChargeTest's shape):
   - Test (a): migrations create `gmb_pay_plans` with columns `id, slug, name, amount_minor, currency, interval, interval_count, trial_days, active, created_at, updated_at`
   - Test (b): can persist a Plan with all required fields and read them back; `amount_minor` casts to `int`, `interval` casts to `PlanInterval`, `active` casts to `bool`, `trial_days` casts to `int`
   - Test (c): `unique(slug)` is enforced — duplicate slug raises `QueryException`
   - Test (d): default `interval_count = 1` and `trial_days = 0` and `active = true` when not provided (set in migration `->default()`)
3. **Migration** `database/migrations/2026_01_01_000007_create_gmb_pay_plans_table.php`:
   - `id`, `string('slug')->unique()`, `string('name')`, `unsignedBigInteger('amount_minor')`, `string('currency', 3)`, `string('interval', 16)`, `unsignedInteger('interval_count')->default(1)`, `unsignedInteger('trial_days')->default(0)`, `boolean('active')->default(true)`, `timestamps()`
4. **Plan model** `src/Models/Plan.php`:
   - `protected $table = 'gmb_pay_plans';`, `protected $guarded = [];`
   - `$casts = ['amount_minor' => 'int', 'interval' => PlanInterval::class, 'interval_count' => 'int', 'trial_days' => 'int', 'active' => 'bool']`
   - No relationships yet (F26 adds the `subscriptions(): HasMany` if needed)
5. Run `vendor/bin/pest`. Tick F25, append done.md entry, commit `F25: gmb_pay_plans migration + Plan model`

### Files this feature will touch

- `src/Enums/PlanInterval.php` (new)
- `database/migrations/2026_01_01_000007_create_gmb_pay_plans_table.php` (new)
- `src/Models/Plan.php` (new)
- `tests/Persistence/PlanTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the four new cases above)
- Plan can be created via `Plan::create(['slug' => 'pro-monthly', 'name' => 'Pro', 'amount_minor' => 50000, 'currency' => 'GMD', 'interval' => 'month'])` — defaults fill in the rest
- Unique constraint on slug fires on duplicate

### Notes for the implementer

- The interval value goes on the DB as the backed string (`'day' / 'week' / 'month' / 'year'`). Enum cast handles the round-trip
- `trial_days` is on the Plan rather than the Subscription so a single change to the plan affects all future subs; existing subs already have `trial_ends_at` (F26) frozen at the time of subscribe
- F25 is pure persistence — no behavior, no relations, no business logic. F26–F31 build on top

**Goal:** `$billable->refund($reference, ?$amountMinor)` finds the local `Charge`, calls `$driver->refund()`, persists a linked `Refund` row, returns the `RefundResult`. F16's Modempay-side block means real (non-demo) Modempay refunds still bubble up `BadMethodCallException` from `AbstractDriver::notImplemented()` — F24 ships the Billable surface that becomes useful the moment a driver-side refund API exists.

### Steps

1. **RED — write the test first** at `tests/Billable/BillableRefundTest.php`:
   - Test (a): demo mode, `$billable->refund('chg_x')` returns a `RefundResult` with `Succeeded` status; persists exactly one `Refund` row with `charge_id` pointing at the matched local Charge
   - Test (b): partial refund — passing `$amountMinor = 1500` lands as `Refund::amount_minor = 1500`, while the `RefundRequest` carries the same value to the driver. Demo mode echoes whatever the request supplied
   - Test (c): an unknown or cross-billable reference raises a `GmbPayException` *before* any driver call — uses `findChargeByReference()` as the gate
   - Test (d): with demo mode **off** and the Modempay driver in play, the call surfaces the underlying `BadMethodCallException` from `AbstractDriver::notImplemented()` (F16 is BLOCKED — when it ships, change this test to assert success). Use `expect(...)->toThrow(BadMethodCallException::class)`
2. **Implement** `refund(string $reference, ?int $amountMinor = null): RefundResult` in `src/Concerns/Billable.php`:
   - `$charge = $this->findChargeByReference($reference); if ($charge === null) throw new GmbPayException("No Charge with reference [{$reference}] found for this Billable.");`
   - `$driver = app(PaymentManager::class)->driver($charge->driver);`
   - `$result = $driver->refund(new RefundRequest(chargeReference: $reference, amountMinor: $amountMinor));`
   - `Refund::create(['charge_id' => $charge->id, 'reference' => $result->reference, 'provider_reference' => $result->providerReference, 'amount_minor' => $result->amountMinor, 'status' => $result->status]);`
   - `return $result;`
3. Run `vendor/bin/pest`. Tick F24, append done.md entry, commit `F24: Billable::refund() — drive + persist a Refund row`

### Files this feature will touch

- `src/Concerns/Billable.php` (modified — adds `refund()`)
- `tests/Billable/BillableRefundTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the four new cases above)
- A missing/cross-billable reference fails fast with `GmbPayException` and **no** Refund row inserted
- Demo-mode refunds persist a `Refund` row that the F09 webhook listener could later update by `provider_reference` (if/when refund webhooks land)

### Notes for the implementer

- Refund DTO has no `currency` field (currency is inherited from the parent Charge in the schema). Don't try to pass currency to the driver
- Idempotency on refunds isn't wired through this Billable helper — `RefundRequest::$idempotencyKey` exists in the DTO but no `PaymentManager::refund` equivalent of F12 has been built. Leave that for a later hardening pass when Modempay's refund API surfaces
- Phase E closes after F24. F25+ open Phase F (Plans & subscriptions). Subscriptions can run end-to-end on the demo driver — no further provider doc-fetches needed for F25–F31

**Goal:** Tiny lookup helper. `$billable->findChargeByReference($ref)` returns the `Charge` model if it belongs to one of this billable's customers, else `null`. Hides the gmbPayCharges()-through-Customer plumbing from the caller.

### Steps

1. **RED — write the test first** at `tests/Billable/FindChargeByReferenceTest.php`:
   - Test (a): finds a Charge whose `customer_id` belongs to this billable
   - Test (b): returns `null` when no Charge with that reference exists
   - Test (c): returns `null` when the Charge exists but belongs to a **different** billable's customer (isolation, same shape as F20 test d)
   - Test (d): returns `null` for orphan charges (customer_id is null) — they're not reachable from any billable
2. **Implement** `findChargeByReference(string $reference): ?Charge` in `src/Concerns/Billable.php`:
   ```php
   return $this->gmbPayCharges()->where('gmb_pay_charges.reference', $reference)->first();
   ```
   Column qualified to `gmb_pay_charges.reference` to avoid ambiguity with any future column on `gmb_pay_customers` of the same name
3. Run `vendor/bin/pest`. Tick F23, append done.md entry, commit `F23: Billable::findChargeByReference()`

### Files this feature will touch

- `src/Concerns/Billable.php` (modified — single-line method)
- `tests/Billable/FindChargeByReferenceTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the four new cases above)
- Orphan and cross-billable Charges return `null` — never a stranger's row

### Notes for the implementer

- The hasManyThrough `gmbPayCharges()` already applies the `billable_type` filter (per F20). Combined with the reference where, the result is scoped both by class and by `(billable_id)` automatically

**Goal:** App-developer-facing one-liner `$user->charge(5000, 'GMD', ['returnUrl' => '...'])` that drives the configured driver, links the resulting Charge to the Billable's Customer row, and returns a `ChargeResult`. Resolves the asymmetry F12 left where Charge persistence only happened on the idempotency path — F22 always persists, regardless of `idempotencyKey`.

### Steps

1. **RED — write the test first** at `tests/Billable/BillableChargeTest.php`:
   - `beforeEach`: demo mode true (default tests), default driver `modempay`
   - Test (a): `$billable->charge(5000, 'GMD', ['customerPhone' => '+2203000000'])` returns a `ChargeResult` whose `checkoutUrl` matches the demo stub, and persists exactly one `Charge` row with `customer_id` set to the `Customer` produced by `createGmbPayCustomer()`
   - Test (b): explicit `$opts['driver']` resolves a different driver — call with `'wave'`, assert `Charge::driver === 'wave'` and the Customer is the one under `wave`
   - Test (c): repeating the call with the same `$opts['idempotencyKey']` returns an equivalent `ChargeResult` (same `reference`) and `Charge::count()` stays at 1 (F12's dedup carries through)
   - Test (d): distinct idempotency keys for the same billable produce two Charges, both with the same `customer_id`
   - Test (e): `$opts['metadata']`, `description`, `returnUrl`, `customerName`, `customerEmail` all reach the underlying `ChargeRequest` — assert by inspecting `$charge->metadata['_gmbpay_raw']` or simply that the ChargeRequest fields land in the DTO the driver receives (best via a custom test driver, but for now check that `$opts['metadata']` ends up on the persisted Charge's `metadata` JSON)
2. **Implement** `Billable::charge(int $amountMinor, string $currency = 'GMD', array $opts = []): ChargeResult` in `src/Concerns/Billable.php`:
   - Resolve `$driverName` from `$opts['driver']` or `config('gmb-pay.default')`
   - `$customer = $this->createGmbPayCustomer($driverName)` — idempotent attach
   - Build a `ChargeRequest` from `$amountMinor`, `$currency`, and `$opts` (keys: `customerPhone`, `customerName`, `customerEmail`, `description`, `callbackUrl`, `returnUrl`, `idempotencyKey`, `metadata`). Required keys missing → sensible defaults (`customerPhone` defaults to `''`, metadata to `[]`)
   - `$result = app(\Africs\GmbPay\PaymentManager::class)->charge($request, $driverName)` — F12 handles idempotency-keyed dedup and may have already persisted a Charge if a key is set
   - **Unify persistence:** `Charge::firstOrNew(['reference' => $result->reference])`, fill `driver`, `customer_id`, `provider_reference`, `amount_minor`, `currency`, `status`. If `! $charge->exists` (F12 didn't persist because no idempotencyKey, OR fresh idempotent call), additionally set `metadata` to the `array_merge($opts['metadata'] ?? [], ['_gmbpay_checkout_url' => …, '_gmbpay_failure_reason' => …, '_gmbpay_raw' => …])` blob. Save. This way the no-idempotencyKey path also gets a Charge row, and the idempotency replay path just back-fills `customer_id` on the F12-created row
   - Return `$result`
3. Run `vendor/bin/pest`. Tick F22, append done.md entry, commit `F22: Billable::charge() — drive + persist + link`

### Files this feature will touch

- `src/Concerns/Billable.php` (modified — adds `charge()`)
- `tests/Billable/BillableChargeTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the five new cases above)
- After `$billable->charge(...)`, a `Charge` row exists with the right `customer_id` — regardless of whether `idempotencyKey` was supplied
- Existing F12 `ChargeIdempotencyTest` still passes (the `Charge::count() === 1` assertion holds because F22 uses `firstOrNew` to back-fill, not create-fresh)

### Notes for the implementer

- **The unified `firstOrNew` path** resolves F20's noted asymmetry. Both paths go through F22 cleanly; F12 stays useful for callers that need lower-level access (no Billable, just `GmbPay::charge($request)`)
- **`customerPhone` defaults to `''`** because `ChargeRequest::$customerPhone` is non-nullable in the DTO. Modempay's `/v1/payments` doesn't forward it anyway (per F14's notes), so empty is harmless. Drivers that *do* require a phone (Wave/Waychit when those land) can validate at the driver level
- **The `_gmbpay_*` metadata stash** mirrors F12 exactly — same keys, same purpose. The duplication between F22 and F12 is small enough to live with; if a third caller surfaces, extract `persistChargeFromResult()` to a `src/Internal/ChargeBridge.php` static helper
- F23 (next) is `findChargeByReference()` — a one-liner on the trait. F24 (`Billable::refund`) is technically blocked on F16's Modempay refund endpoint but we can still ship a Billable-side wrapper that delegates to `$driver->refund()` and bubbles the `BadMethodCallException` for Modempay (demo mode still works)

**Goal:** Add a `createGmbPayCustomer(?string $driver = null, array $opts = []): Customer` helper to the `Billable` trait. Creates (or returns the existing) local `gmb_pay_customers` row for this billable+driver. Provider-side customer creation (a separate HTTP call to e.g. Modempay's `/v1/customers`) is **deferred** — F21 stays local-only per the original spec.

### Steps

1. **RED — write the test first** at `tests/Billable/CreateGmbPayCustomerTest.php`:
   - Test (a): `$billable->createGmbPayCustomer()` returns a `Customer` model with `billable_type === FakeBillable::class`, `billable_id === $billable->id`, `driver === 'modempay'` (the config default)
   - Test (b): explicit driver argument is respected
   - Test (c): calling twice with the same `(billable, driver)` returns the **same** `Customer` row (idempotent — `firstOrCreate` semantics)
   - Test (d): `$opts['metadata']` is persisted on first create, and subsequent calls keep the original metadata (firstOrCreate doesn't overwrite when finding an existing row)
   - Test (e): a billable can hold multiple customers under different drivers concurrently — `createGmbPayCustomer('modempay')` and `createGmbPayCustomer('wave')` produce two rows
2. **Implement** in `src/Concerns/Billable.php`:
   ```php
   public function createGmbPayCustomer(?string $driver = null, array $opts = []): Customer
   {
       $driver = $driver ?? (string) config('gmb-pay.default', 'modempay');

       return Customer::firstOrCreate(
           [
               'billable_type' => $this->getMorphClass(),
               'billable_id' => $this->getKey(),
               'driver' => $driver,
           ],
           [
               'metadata' => $opts['metadata'] ?? [],
           ],
       );
   }
   ```
3. Run `vendor/bin/pest`. Tick F21, append done.md entry, commit `F21: Billable::createGmbPayCustomer()`

### Files this feature will touch

- `src/Concerns/Billable.php` (modified — adds `createGmbPayCustomer()`)
- `tests/Billable/CreateGmbPayCustomerTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the five new cases above)
- The unique constraint on `(billable_type, billable_id, driver)` is the source of truth for idempotency — `firstOrCreate` reads it; we don't add an explicit transaction
- **No** HTTP calls are made — provider customer creation lands later (probably F22 or a follow-up). F21 is local only

### Notes for the implementer

- The `provider_customer_id` column stays null — F22+ will populate it after the first successful charge or when a future helper creates a provider-side Customer
- `firstOrCreate` is intentional over `create` to keep the trait method idempotent. Calling it on every login or every checkout shouldn't blow up

**Goal:** Open Phase E by giving consuming apps a one-liner (`use Africs\GmbPay\Concerns\Billable`) on their `User` (or any Eloquent model) that exposes the relevant gmb-pay rows. F20 is just the relation surface — F21 adds the customer-creation helper, F22 wraps charging, F23 looks up by reference, F24 wraps refunds.

**Schema reminder (already shipped in F01/F02):**

- `gmb_pay_customers` has `morphs('billable')` (`billable_type` + `billable_id`) plus a unique on `(billable_type, billable_id, driver)`
- `gmb_pay_charges` has `customer_id` (nullable, `nullOnDelete`) — no direct polymorphic link to a Billable. Orphan one-shot charges (no customer_id) won't be reachable from `gmbPayCharges()` and that's intentional

### Steps

1. **RED — write the test first** at `tests/Billable/BillableTraitTest.php`:
   - Use the existing `Africs\GmbPay\Tests\Fixtures\Models\FakeBillable` (the fixture already lives at `tests/Fixtures/Models/FakeBillable.php`); add `use Billable;` to it in step 2
   - Test (a): `$billable->gmbPayCustomers` returns all `Customer` rows where `billable_type === FakeBillable::class` and `billable_id === $billable->id`. Create two customers under two drivers, assert count=2
   - Test (b): `$billable->gmbPayCharges` traverses customers and returns the linked charges. Create one customer + one charge linked to it; assert count=1 and the reference matches
   - Test (c): orphan charges (`customer_id = null`) do **not** appear in `gmbPayCharges()`. Insert one, assert relation still empty
   - Test (d): two `FakeBillable` rows are fully isolated — `$b1->gmbPayCharges` only sees `$b1`'s customers' charges, never `$b2`'s
2. **Implement** `src/Concerns/Billable.php`:
   - Namespace `Africs\GmbPay\Concerns`
   - Trait `Billable`
   - `gmbPayCustomers(): MorphMany` — `$this->morphMany(Customer::class, 'billable')`
   - `gmbPayCharges(): HasManyThrough` — `Billable → Customer → Charge` via `customer_id`. Add `->where('gmb_pay_customers.billable_type', $this->getMorphClass())` so two different Billable classes with the same numeric id don't cross-pollinate
3. **Update the fixture** `tests/Fixtures/Models/FakeBillable.php` to `use Africs\GmbPay\Concerns\Billable;` — this is what the new test exercises and is the canonical demo of how a consumer's `User` model adopts the trait
4. Run `vendor/bin/pest`. Tick F20, append done.md entry, commit `F20: Billable trait — gmbPayCustomers + gmbPayCharges`

### Files this feature will touch

- `src/Concerns/Billable.php` (new)
- `tests/Fixtures/Models/FakeBillable.php` (modified — `use Billable;`)
- `tests/Billable/BillableTraitTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the four new cases above)
- The trait is **side-effect-free** — adding `use Billable` to a model just exposes two relations; no boot hooks, no observer registration, no DB writes on attach
- Existing persistence tests (`tests/Persistence/CustomerTest.php`, etc.) keep passing — the trait is additive

### Notes for the implementer

- The spec line says "`gmbPayCharges()` morphMany" but the schema only has `customer_id` on charges, not a polymorphic billable. `HasManyThrough` is the correct relation type — note this divergence in the done entry
- The `->where('gmb_pay_customers.billable_type', $this->getMorphClass())` matters: two distinct billable models with overlapping primary keys would otherwise mix charges. `getMorphClass()` returns the morph alias if you've registered one, else the FQCN — both work here
- F22 will likely want a `chargeRecord()` or similar helper on the trait so a one-shot charge writes a Charge row + sets `customer_id` when one exists. Don't add it in F20 — keep this feature focused

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
