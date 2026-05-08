# TODO

## Active: F06 — Idempotency keys table + model

**Goal:** Persist a `(driver, key) → target` mapping so a duplicate `charge()` call with the same idempotency key short-circuits to the prior result. F11 (the service) and F12 (manager wiring) build on this row.

### Steps

1. **RED — write the test first** at `tests/Persistence/IdempotencyKeyTest.php`:
   - Assert the `gmb_pay_idempotency_keys` table exists with all expected columns
   - Assert a row persists with `driver`, `key`, `target_type`, `target_id`
   - Assert `unique(driver, key)` fires on duplicate insert
   - Assert two rows with the same `key` but different `driver` are allowed
2. **Migration** at `database/migrations/2026_01_01_000006_create_gmb_pay_idempotency_keys_table.php`:
   - `id`, `string('driver', 32)`, `string('key', 191)`, `string('target_type', 191)->nullable()`, `unsignedBigInteger('target_id')->nullable()`, `timestamps`
   - Composite unique `(driver, key)` named `gmb_pay_idempotency_keys_driver_key_unique`
   - Index on `(target_type, target_id)` for reverse lookups (rare but cheap; named `gmb_pay_idempotency_keys_target_index`)
3. **Model** at `src/Models/IdempotencyKey.php`:
   - `$table = 'gmb_pay_idempotency_keys'`, `$guarded = []`
   - Casts: `target_id='int'`
   - `target(): MorphTo` so `$row->target` returns the linked Charge / Refund / Payout
4. Run `vendor/bin/pest` — confirm green and the full suite still passes
5. Tick F06 in `tasks/all-features.md`, append entry to `tasks/done.md`, commit `F06: idempotency_keys migration + model`

### Files this feature will touch

- `database/migrations/2026_01_01_000006_create_gmb_pay_idempotency_keys_table.php` (new)
- `src/Models/IdempotencyKey.php` (new)
- `tests/Persistence/IdempotencyKeyTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass: `vendor/bin/pest`
- `unique(driver, key)` enforced; same key allowed across distinct drivers
- `target()` morphTo returns a Charge model when `target_type=Africs\GmbPay\Models\Charge` and `target_id` is set

### Notes for the implementer

- `key` is 191 chars (not 255) so the composite index fits MySQL's old `utf8mb4` 767-byte index limit without extra config; this matches the historical Laravel default
- `target_type` and `target_id` are nullable for the brief window between row creation (RED in F11) and target persistence — the F11 service writes the row first, runs the callable, then back-fills target. Tests in F06 use a non-null target so the morphTo assertion is meaningful
- This is the last Phase A row. F07 starts using F05 immediately

---

## Blocked

_(features paused mid-implementation — none yet)_
