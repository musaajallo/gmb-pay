# TODO

## Active: F09 — UpdateRefundFromWebhook listener

**Goal:** Mirror F08 for refunds. When `WebhookReceived` carries a `refund.*` event, find the matching `gmb_pay_refunds` row by `(charge.driver, provider_reference)` and update its status. No-op if no row matches.

### Steps

1. **RED — write the test first** at `tests/Webhook/UpdateRefundFromWebhookTest.php`:
   - Persist a `Charge` with `driver=modempay` and a `Refund` with `provider_reference=rfd_provider_1`, `status=Pending`
   - Dispatch `WebhookReceived` carrying a DTO with `type=RefundSucceeded`, `driver=modempay`, `providerReference=rfd_provider_1`
   - Assert the refund is reloaded with `status=Succeeded`
   - Repeat for `RefundFailed` → `Failed`
   - Assert no-op when there's no matching row, and ignore for `charge.*` and `unknown` events
   - Assert cross-driver isolation: a refund whose parent charge has `driver=wave` is **not** updated by a `modempay` webhook even with the same `provider_reference`
2. **Listener** at `src/Listeners/UpdateRefundFromWebhook.php`:
   - `__invoke(WebhookReceived $event)`. Switch on the two refund cases; map to `RefundStatus`. Return early otherwise
   - Lookup joins via parent charge: `Refund::whereHas('charge', fn ($q) => $q->where('driver', $dto->driver))->where('provider_reference', $dto->providerReference)->first()`
   - Update via `update(['status' => ...])`
3. Run `vendor/bin/pest`. Tick F09, append done entry, commit `F09: UpdateRefundFromWebhook listener`

### Files this feature will touch

- `src/Listeners/UpdateRefundFromWebhook.php` (new)
- `tests/Webhook/UpdateRefundFromWebhookTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass
- Both `refund.*` types map to the right `RefundStatus`
- A webhook with no matching local refund does not throw
- Driver scoping is enforced via the parent charge — a wave-driver refund is not mutated by a modempay webhook

### Notes for the implementer

- The Refund table has no `driver` column; the parent Charge does. That's why the lookup uses `whereHas` on the relation. Don't add a `driver` column to refunds — it's a denormalization with no current callers
- Same `?->update(...)` pattern as F08 to make missing-row a silent no-op

---

## Blocked

_(features paused mid-implementation — none yet)_
