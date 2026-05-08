# TODO

## Active: F08 — UpdateChargeFromWebhook listener

**Goal:** When `WebhookReceived` carries a `charge.*` event, find the matching `gmb_pay_charges` row by `(driver, provider_reference)` and update its status. No-op if no row matches (provider may emit events for charges this app didn't initiate).

### Steps

1. **RED — write the test first** at `tests/Webhook/UpdateChargeFromWebhookTest.php`:
   - Persist a `Charge` with `driver=modempay`, `provider_reference=ch_provider_1`, `status=Pending`
   - Dispatch `WebhookReceived` carrying a DTO with `type=ChargeSucceeded`, `driver=modempay`, `providerReference=ch_provider_1`
   - Assert the charge is reloaded and `status=Succeeded`
   - Repeat with `ChargeFailed` and `ChargeCancelled` mapping to `Failed` / `Cancelled`
   - Assert no-op when there's no matching row (no exception, charge count unchanged)
   - Assert the listener ignores `refund.*` and `unknown` events
2. **Listener** at `src/Listeners/UpdateChargeFromWebhook.php`:
   - `__invoke(WebhookReceived $event)`. Switch on `$event->event->type` for the three charge cases; map to `ChargeStatus`. Return early otherwise
   - Lookup: `Charge::where('driver', $dto->driver)->where('provider_reference', $dto->providerReference)->first()`. Skip if null or `providerReference` is null
   - Update via `update(['status' => ...])` — Eloquent will respect the enum cast
3. **Wiring** — for this feature, register the listener manually inside the test's `defineEnvironment()` or via `Event::listen` in a setup hook. F10 will auto-register from the service provider.
4. Run `vendor/bin/pest`. Tick F08, append done entry, commit `F08: UpdateChargeFromWebhook listener`

### Files this feature will touch

- `src/Listeners/UpdateChargeFromWebhook.php` (new)
- `tests/Webhook/UpdateChargeFromWebhookTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass
- The three `charge.*` types map to the right `ChargeStatus`
- A webhook with no matching local charge does not throw and does not create a row
- Refund and unknown events are no-ops for this listener

### Notes for the implementer

- Use enum-to-status mapping in a private match expression — keep the public surface a single `__invoke`
- Don't update `provider_reference` or `amount_minor` from the webhook — the local row is the source of truth for those; the webhook only confirms terminal state. F37/F38 (subscription advance) will handle period rolling separately
- The test should NOT call `Event::fake()` here — it needs the listener to actually fire. Use the controller route or call `event(new WebhookReceived(...))` directly

---

## Blocked

_(features paused mid-implementation — none yet)_
