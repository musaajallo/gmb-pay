# TODO

## Active: F07 — Webhook persistence + dedup

**Goal:** `WebhookController::handle()` writes a `gmb_pay_webhook_events` row before dispatching `WebhookReceived`. A duplicate delivery (same `driver` + `provider_event_id`) returns 200 and does not dispatch the event a second time.

### Steps

1. **RED — write the test first** at `tests/Webhook/WebhookPersistenceTest.php`:
   - Hit `POST /gmb-pay/webhook/modempay` with a JSON body whose parsed event has `provider_event_id=evt_123`. In demo mode signature passes.
   - Assert response is 200, a `gmb_pay_webhook_events` row exists with that id, and `WebhookReceived` was dispatched once
   - Re-post the same body; assert still 200, **still one row**, and `WebhookReceived` dispatched **only once total**
   - Post a payload with no `provider_event_id` (DTO returns `null`); assert a row is written and the event dispatched (every null insert is treated as new)
2. **DTO change** — add `?string $providerEventId = null` to `Africs\GmbPay\DataObjects\WebhookEvent`. Keep it last so existing callers don't break.
3. **AbstractDriver::parseWebhook()** — extract `id` from the request payload (best-effort) and pass to the DTO. Drivers can override.
4. **Controller change** — after signature validation and parse:
   - Look up an existing row by `(driver, provider_event_id)` when `providerEventId` is non-null. If found, return 200 without dispatching.
   - Otherwise insert a `Models\WebhookEvent` row with `driver`, `provider_event_id`, `type=$dto->type`, `payload=$dto->payload`, `received_at=now()`.
   - Dispatch `WebhookReceived` and return 200.
5. Run `vendor/bin/pest` — confirm green and no other tests regressed
6. Tick F07 in `tasks/all-features.md`, append to `tasks/done.md`, commit `F07: webhook persistence + dedup`

### Files this feature will touch

- `src/DataObjects/WebhookEvent.php` (modified — add `providerEventId`)
- `src/Drivers/AbstractDriver.php` (modified — populate `providerEventId` from payload)
- `src/Http/Controllers/WebhookController.php` (modified — persist + dedup)
- `tests/Webhook/WebhookPersistenceTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- Duplicate POST with same `provider_event_id` returns 200, writes one row, dispatches once
- Null `provider_event_id` always writes a fresh row (SQLite NULLs are distinct)
- All Pest tests pass

### Notes for the implementer

- Use `Event::fake([WebhookReceived::class])` and `Event::assertDispatchedTimes(...)` to count dispatches without breaking other listeners
- Persisting **before** dispatch is intentional: if a queued listener fails later, the raw event is still on disk for replay
- Don't add a hash check on the payload yet — `(driver, provider_event_id)` is the unit of dedup. Two distinct events with the same id from one provider would already be a provider bug

---

## Blocked

_(features paused mid-implementation — none yet)_
