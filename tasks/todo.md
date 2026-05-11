# TODO

## Active: F11 — IdempotencyStore service

**Goal:** A small service that wraps any `(driver, key) → Model` operation so a repeated call with the same key returns the prior model instead of running the callback again. F12 will plug this into `PaymentManager::charge()` so `ChargeRequest::$idempotencyKey` short-circuits double-submits; F11 just delivers the primitive.

### Steps

1. **RED — write the test first** at `tests/Idempotency/IdempotencyStoreTest.php`:
   - First call with a fresh `(driver, key)` runs the callback, returns the Eloquent model it produced, and persists a single `IdempotencyKey` row pointing at that model (`target_type` + `target_id` populated)
   - Second call with the same `(driver, key)` does **not** run the callback again and returns the same model the first call returned (same primary key, equal via `Model::is()`)
   - Same key across two different drivers runs the callback independently for each driver
   - Different keys under the same driver run the callback independently
   - Confirm fails first (no class yet)
2. **Implement** `src/Idempotency/IdempotencyStore.php`:
   - Namespace `Africs\GmbPay\Idempotency`
   - Public method `remember(string $driver, string $key, callable $callback): \Illuminate\Database\Eloquent\Model`
   - Read existing row; if it has both `target_type` and `target_id`, resolve and return `$row->target`
   - Otherwise run the callback, `updateOrCreate` the `IdempotencyKey` row with `target_type = $target::class` and `target_id = $target->getKey()`, return the target
   - Keep it small. No singleton binding, no transaction, no lockForUpdate yet — F12 / a later hardening pass can add those once we know how it gets called
3. Run `vendor/bin/pest`. Tick F11 in `tasks/all-features.md`, append a metadata block to `tasks/done.md` matching the existing format, commit as `F11: IdempotencyStore service`

### Files this feature will touch

- `src/Idempotency/IdempotencyStore.php` (new)
- `tests/Idempotency/IdempotencyStoreTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass (full suite green, including the four new cases above)
- A repeat `remember()` with the same `(driver, key)` proves the callback isn't re-invoked (use a counter in the test closure)
- No new dependency on `PaymentManager` or any driver — `IdempotencyStore` is callable on its own

### Notes for the implementer

- The `IdempotencyKey` model already has a `morphTo` relation called `target` (see `src/Models/IdempotencyKey.php`) — use it to hydrate the prior model
- The table's `(driver, key)` unique constraint is the long-term concurrency guarantee; F11 doesn't have to exercise it, F12 should
- The callback's return type is `Illuminate\Database\Eloquent\Model`, not `ChargeResult`. F12 will be responsible for the ChargeResult ↔ Charge bridge

---

## Blocked

_(features paused mid-implementation — none yet)_
