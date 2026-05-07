# TODO

## Active: F01 — Customers migration + Customer model

**Goal:** Create the `gmb_pay_customers` table and `Africs\GmbPay\Models\Customer` Eloquent model. Ship a Pest test that proves the migration runs and a polymorphic owner can be attached.

### Steps

1. **RED — write the test first** at `tests/Persistence/CustomerTest.php`:
   - Boot the package, run migrations (Testbench provides `loadLaravelMigrations` and the package's own `loadMigrationsFrom` should already pick it up)
   - Create a fake billable model in the test fixture (Testbench supports inline migrations / models — see Phase 1 patterns)
   - Assert `Customer::create([...])` persists `provider_customer_id`, `driver`, `billable_type`, `billable_id`
   - Assert `$customer->billable` returns the fake model
2. **Migration** at `database/migrations/2026_01_01_000001_create_gmb_pay_customers_table.php`:
   - `id`, `morphs('billable')`, `string('driver', 32)`, `string('provider_customer_id')->nullable()`, `json('metadata')->nullable()`, `timestamps`
   - Unique index on `(billable_type, billable_id, driver)`
3. **Model** at `src/Models/Customer.php`:
   - `$table = 'gmb_pay_customers'`, `$guarded = []`
   - `morphTo billable()`, `casts metadata=array`
4. Run `vendor/bin/pest` — confirm green and nothing else broke
5. Tick F01 in `tasks/all-features.md`, append entry to `tasks/done.md`, commit `F01: customers migration + model`

### Files this feature will touch

- `database/migrations/2026_01_01_000001_create_gmb_pay_customers_table.php` (new)
- `src/Models/Customer.php` (new)
- `tests/Persistence/CustomerTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass: `vendor/bin/pest`
- Migration is picked up by `Testbench` automatically (the service provider already calls `loadMigrationsFrom`)
- The model is reachable from the package namespace and exposes the morph relationship

---

## Blocked

_(features paused mid-implementation — none yet)_
