# TODO

## Active: F12 — Wire idempotency into PaymentManager::charge()

**Goal:** When a caller passes a `ChargeRequest` with `$idempotencyKey` set, `PaymentManager::charge()` should short-circuit repeat calls — the driver fires exactly once, a single `Charge` row is persisted, and every subsequent call with the same key returns the same `ChargeResult` (reconstructed from the persisted row). When the key is null, behavior is the pure passthrough we have today: no `Charge` row, no `IdempotencyKey` row, no extra persistence. Broader Charge persistence on every call is F22's job via the `Billable` trait.

### Steps

1. **RED — write the test first** at `tests/PaymentManager/ChargeIdempotencyTest.php`:
   - With `idempotencyKey: 'order-1'`: call `GmbPay::charge($request)` twice. Assert (a) both returns are `ChargeResult` with the same `reference`, `amountMinor`, `currency`, `status`, `checkoutUrl`; (b) `Charge::count()` is `1`; (c) `IdempotencyKey::where('driver', 'modempay')->where('key', 'order-1')->count()` is `1` with `target_type = Charge::class`
   - Without `idempotencyKey` (null): one call. Assert `Charge::count()` is still `0` and `IdempotencyKey::count()` is `0` — today's passthrough behavior preserved
   - With two distinct `idempotencyKey`s under the same driver: two `Charge` rows, two `IdempotencyKey` rows, distinct references
   - Confirm fails first (no `PaymentManager::charge()` method yet — currently magic-proxied)
2. **Implement** `src/PaymentManager.php`:
   - Inject `Africs\GmbPay\Idempotency\IdempotencyStore` via constructor (extend the existing `__construct(Container)`)
   - Add an explicit method `public function charge(ChargeRequest $request, ?string $driverName = null): ChargeResult`
   - If `$request->idempotencyKey === null`: `return $this->driver($driverName)->charge($request);` — passthrough
   - Else: call `IdempotencyStore::remember($driver->name(), $request->idempotencyKey, callback)`. The callback runs the driver and persists a `Charge` row from the result via a private `persistChargeFromResult()` helper. Then build a `ChargeResult` from the returned `Charge` via a private `resultFromCharge()` helper
3. **Charge ↔ ChargeResult round-trip** (private helpers on `PaymentManager`):
   - `persistChargeFromResult(string $driver, ChargeRequest $request, ChargeResult $result): Charge`
     - Writes a Charge row with `reference`, `driver`, `provider_reference`, `amount_minor`, `currency`, `status`, plus `metadata` merging `$request->metadata` with `_gmbpay_checkout_url`, `_gmbpay_failure_reason`, `_gmbpay_raw` so we can reconstruct the DTO losslessly on replay
   - `resultFromCharge(Charge $charge): ChargeResult`
     - Rebuilds the DTO; pulls `checkoutUrl`/`failureReason`/`raw` back out of `metadata`
4. **Service provider** (`src/GmbPayServiceProvider.php`):
   - Update the `PaymentManager` singleton closure to pass an `IdempotencyStore` instance: `new PaymentManager($app, $app->make(IdempotencyStore::class))`
   - No need to bind `IdempotencyStore` explicitly — Laravel auto-resolves the concrete class
5. **PaymentManager docblock**: drop the `@method charge(ChargeRequest $request)` line since `charge` is now a real method. Keep the `verify`/`refund`/`payout` `@method` lines — those stay magic-proxied
6. Run `vendor/bin/pest`. Tick F12 in `tasks/all-features.md`, append a metadata block to `tasks/done.md`, commit `F12: wire idempotency into PaymentManager::charge()`

### Files this feature will touch

- `src/PaymentManager.php` (modified — constructor dep + explicit `charge()` + two private helpers)
- `src/GmbPayServiceProvider.php` (modified — pass `IdempotencyStore` into the PaymentManager singleton)
- `tests/PaymentManager/ChargeIdempotencyTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the three new cases above)
- Existing SmokeTest still passes — `GmbPay::driver('modempay')->charge(...)` (driver-direct) keeps returning a pure DTO with `checkoutUrl` set and writes nothing
- `GmbPay::charge($request)` with `idempotencyKey` set persists exactly one `Charge` row per key, no matter how many times it's called

### Notes for the implementer

- The `metadata` JSON column on `gmb_pay_charges` is the round-trip vehicle for DTO fields that don't have their own column. Use a `_gmbpay_` prefix on those keys so they don't collide with caller metadata
- `Charge::$casts` already casts `metadata` to `array` and `status` to `ChargeStatus`, so reads come back typed
- The `Manager::__call` magic still forwards `verify`/`refund`/`payout` to the default driver — leave that alone
- Concurrency hardening (`DB::transaction` + `lockForUpdate` in `IdempotencyStore`) is still deferred. Add it in this feature only if a test surfaces a need; otherwise leave a note for a later hardening pass

---

## Blocked

_(features paused mid-implementation — none yet)_
