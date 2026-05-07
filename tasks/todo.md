# TODO

## Active: F03 — Refunds migration + Refund model

**Goal:** Create the `gmb_pay_refunds` table and `Africs\GmbPay\Models\Refund` Eloquent model. A refund always belongs to a charge; partial refunds are allowed via `amount_minor`.

### Steps

1. **RED — write the test first** at `tests/Persistence/RefundTest.php`:
   - Assert the `gmb_pay_refunds` table exists with all expected columns
   - Assert a `Refund` row persists with `charge_id`, `reference`, `provider_reference`, `amount_minor`, `status`
   - Assert `status` is cast to `RefundStatus` enum, `amount_minor` is `int`
   - Assert `Refund::belongsTo(Charge::class)` resolves (and `Charge::hasMany(Refund::class)` works the other way)
   - Assert `unique(reference)` on the local refund reference
2. **Migration** at `database/migrations/2026_01_01_000003_create_gmb_pay_refunds_table.php`:
   - `id`, `foreignId('charge_id')->constrained('gmb_pay_charges')->cascadeOnDelete()`, `string('reference')->unique()`, `string('provider_reference')->nullable()`, `unsignedBigInteger('amount_minor')`, `string('status', 32)`, `timestamps`
   - Index on `charge_id` is auto-created by `constrained()` — nothing extra needed
3. **Model** at `src/Models/Refund.php`:
   - `$table = 'gmb_pay_refunds'`, `$guarded = []`
   - Casts: `status=Africs\GmbPay\Enums\RefundStatus::class`, `amount_minor=int`
   - `charge(): BelongsTo`
4. **Update** `src/Models/Charge.php` to add `refunds(): HasMany` so the inverse direction is testable
5. Run `vendor/bin/pest` — confirm green and the full suite still passes
6. Tick F03 in `tasks/all-features.md`, append entry to `tasks/done.md`, commit `F03: refunds migration + model`

### Files this feature will touch

- `database/migrations/2026_01_01_000003_create_gmb_pay_refunds_table.php` (new)
- `src/Models/Refund.php` (new)
- `src/Models/Charge.php` (modified — add `refunds()` HasMany)
- `tests/Persistence/RefundTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass: `vendor/bin/pest`
- `Refund::belongsTo(Charge::class)` returns the parent charge
- `Charge::hasMany(Refund::class)` returns the refund collection (used by webhook handlers in F09)
- The `status` enum cast round-trips correctly

### Notes for the implementer

- Existing enum lives at `src/Enums/RefundStatus.php` — read it first to confirm cases
- `cascadeOnDelete` on `charge_id` is intentional: deleting a charge deletes its refund rows (rare in production but keeps test cleanup simple). Soft deletes can come later if needed
- This feature is the last in the Phase A trio that needs morph-test fixtures; F04 (payouts) and F05 (webhook events) don't need a billable owner so the fixture stays unused there

---

## Blocked

_(features paused mid-implementation — none yet)_
