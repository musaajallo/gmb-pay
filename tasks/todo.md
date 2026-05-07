# TODO

## Active: F02 — Charges migration + Charge model

**Goal:** Create the `gmb_pay_charges` table and `Africs\GmbPay\Models\Charge` Eloquent model. The charge is the central record that webhooks update by `provider_reference` and that subscription invoices link to.

### Steps

1. **RED — write the test first** at `tests/Persistence/ChargeTest.php`:
   - Assert the `gmb_pay_charges` table exists with all expected columns
   - Assert a `Charge` row persists with `reference`, `provider_reference`, `driver`, `customer_id`, `amount_minor`, `currency`, `status`, `metadata`
   - Assert `status` is cast to the `ChargeStatus` enum
   - Assert `metadata` is cast to array
   - Assert `Charge::belongsTo(Customer::class)` resolves
   - Assert `unique(driver, provider_reference)` so webhook dedup-by-provider-ref is safe (when `provider_reference` is non-null)
2. **Migration** at `database/migrations/2026_01_01_000002_create_gmb_pay_charges_table.php`:
   - `id`, `string('reference')->unique()`, `string('provider_reference')->nullable()`, `string('driver', 32)`, `foreignId('customer_id')->nullable()->constrained('gmb_pay_customers')->nullOnDelete()`, `unsignedBigInteger('amount_minor')`, `string('currency', 3)`, `string('status', 32)`, `json('metadata')->nullable()`, `timestamps`
   - Composite unique on `(driver, provider_reference)` — partial / nullable-safe via raw if SQLite is the test driver
   - Index on `status` for the cycle command's lookups
3. **Model** at `src/Models/Charge.php`:
   - `$table = 'gmb_pay_charges'`, `$guarded = []`
   - Casts: `metadata=array`, `status=Africs\GmbPay\Enums\ChargeStatus::class`, `amount_minor=int`
   - `customer(): BelongsTo` relationship
4. Run `vendor/bin/pest` — confirm green and the full suite still passes
5. Tick F02 in `tasks/all-features.md`, append entry to `tasks/done.md`, commit `F02: charges migration + model`

### Files this feature will touch

- `database/migrations/2026_01_01_000002_create_gmb_pay_charges_table.php` (new)
- `src/Models/Charge.php` (new)
- `tests/Persistence/ChargeTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass: `vendor/bin/pest`
- `Charge::belongsTo(Customer::class)` returns the related customer
- The `status` enum cast round-trips correctly (string in DB ↔ `ChargeStatus` in PHP)
- Composite uniqueness on `(driver, provider_reference)` is enforced for non-null provider references

### Notes for the implementer

- Existing enum lives at `src/Enums/ChargeStatus.php` — read it first; the migration's status column should match its backed-string values
- SQLite (used in tests by default with Testbench) supports unique indexes including nullable columns; if MySQL/Postgres compatibility is needed later, that's a separate concern handled in F61
- Foreign key to `gmb_pay_customers` is nullable because some charges (one-shot phone payments) won't have a stored customer — see F22

---

## Blocked

_(features paused mid-implementation — none yet)_
