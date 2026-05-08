# TODO

## Active: F05 — Webhook events table + model

**Goal:** Persist every accepted webhook so listeners are decoupled from HTTP, and so duplicate provider deliveries can be ignored. Dedup hinges on a unique index on `(driver, provider_event_id)` — F07 (the controller change) relies on this.

### Steps

1. **RED — write the test first** at `tests/Persistence/WebhookEventTest.php`:
   - Assert the `gmb_pay_webhook_events` table exists with all expected columns
   - Assert a `WebhookEvent` row persists with `driver`, `provider_event_id`, `type`, `payload`, `received_at`
   - Assert `type` is cast to `WebhookEventType` enum, `payload` is cast to array
   - Assert `received_at` round-trips as a `Carbon` instance
   - Assert `unique(driver, provider_event_id)` fires on duplicate insert (`QueryException`)
   - Assert `provider_event_id = null` is allowed (some providers don't send one)
2. **Migration** at `database/migrations/2026_01_01_000005_create_gmb_pay_webhook_events_table.php`:
   - `id`, `string('driver', 32)`, `string('provider_event_id')->nullable()`, `string('type', 64)`, `json('payload')`, `timestamp('received_at')`, `timestamps`
   - Composite unique `(driver, provider_event_id)` named `gmb_pay_webhook_events_driver_provider_event_id_unique`
   - Index on `type` named `gmb_pay_webhook_events_type_index` for future "replay all charge.failed" queries
3. **Model** at `src/Models/WebhookEvent.php`:
   - **Note the namespace clash with `Africs\GmbPay\DataObjects\WebhookEvent`** — the model lives in `Africs\GmbPay\Models\WebhookEvent`. Listeners receive the DTO; the DB row is the model. Don't confuse them.
   - `$table = 'gmb_pay_webhook_events'`, `$guarded = []`
   - Casts: `type=Africs\GmbPay\Enums\WebhookEventType::class`, `payload='array'`, `received_at='datetime'`
4. Run `vendor/bin/pest` — confirm green and the full suite still passes
5. Tick F05 in `tasks/all-features.md`, append entry to `tasks/done.md`, commit `F05: webhook_events migration + model`

### Files this feature will touch

- `database/migrations/2026_01_01_000005_create_gmb_pay_webhook_events_table.php` (new)
- `src/Models/WebhookEvent.php` (new)
- `tests/Persistence/WebhookEventTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass: `vendor/bin/pest`
- Both `payload` (array) and `type` (enum) cast round-trip cleanly
- Duplicate `(driver, provider_event_id)` insert throws `QueryException`
- A null `provider_event_id` is accepted, and two such rows for the same driver are allowed (SQLite treats NULLs as distinct in unique indexes)

### Notes for the implementer

- Existing enum `WebhookEventType` already has `Unknown` for unrecognized providers — use it, don't add a new "received" or "raw" case
- F07 will write to this table inside `WebhookController::handle()` *before* dispatching the `WebhookReceived` event. Keep this table cheap to insert: no FKs, no JSON validation
- `received_at` is separate from `created_at` because retries / replays may insert rows whose original webhook arrived earlier than the row's create time

---

## Blocked

_(features paused mid-implementation — none yet)_
