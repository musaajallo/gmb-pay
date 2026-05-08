# TODO

## Active: F04 — Payouts migration + Payout model

**Goal:** Create the `gmb_pay_payouts` table and `Africs\GmbPay\Models\Payout` Eloquent model. A payout is a money-out transaction (merchant → recipient phone), independent of charges. No `charge_id`, no `customer_id` for now.

### Steps

1. **RED — write the test first** at `tests/Persistence/PayoutTest.php`:
   - Assert the `gmb_pay_payouts` table exists with all expected columns
   - Assert a `Payout` row persists with `reference`, `provider_reference`, `driver`, `recipient_phone`, `amount_minor`, `currency`, `status`
   - Assert `status` is cast to `PayoutStatus` enum, `amount_minor` is `int`
   - Assert `unique(reference)` on the local payout reference
   - Assert `unique(driver, provider_reference)` mirroring the charges table (provider may reuse ids across drivers)
2. **Migration** at `database/migrations/2026_01_01_000004_create_gmb_pay_payouts_table.php`:
   - `id`, `string('reference')->unique()`, `string('provider_reference')->nullable()`, `string('driver', 32)`, `string('recipient_phone')`, `unsignedBigInteger('amount_minor')`, `string('currency', 3)`, `string('status', 32)`, `timestamps`
   - Composite unique `(driver, provider_reference)` with explicit name `gmb_pay_payouts_driver_provider_ref_unique`
   - Index on `status` (`gmb_pay_payouts_status_index`) for future cycle-style queries
3. **Model** at `src/Models/Payout.php`:
   - `$table = 'gmb_pay_payouts'`, `$guarded = []`
   - Casts: `status=Africs\GmbPay\Enums\PayoutStatus::class`, `amount_minor=int`
   - No relations yet — payouts stand alone until a future feature (not in scope here) attaches them to invoices/refunds
4. Run `vendor/bin/pest` — confirm green and the full suite still passes
5. Tick F04 in `tasks/all-features.md`, append entry to `tasks/done.md`, commit `F04: payouts migration + model`

### Files this feature will touch

- `database/migrations/2026_01_01_000004_create_gmb_pay_payouts_table.php` (new)
- `src/Models/Payout.php` (new)
- `tests/Persistence/PayoutTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass: `vendor/bin/pest`
- The `status` enum cast round-trips correctly
- Both unique constraints fire on duplicate inserts (`QueryException`)

### Notes for the implementer

- Existing enum lives at `src/Enums/PayoutStatus.php` — read it first to confirm cases
- `recipient_phone` is stored as a plain `string` (no E.164 validation at the DB layer); driver code will normalize on the way in. Keeping it nullable would allow non-phone payout rails later, but Phase-1 gateways (Modempay/Wave/Waychit) all require a phone, so non-null is correct now
- This is the last persistence row that doesn't need a polymorphic owner. F05 (webhook_events) and F06 (idempotency_keys) follow the same single-table-no-relations shape

---

## Blocked

_(features paused mid-implementation — none yet)_
