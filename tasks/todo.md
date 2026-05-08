# TODO

## Active: F10 — Auto-register webhook listeners

**Goal:** The package should make `UpdateChargeFromWebhook` and `UpdateRefundFromWebhook` fire on `WebhookReceived` automatically — no manual `Event::listen` in user code. Gated by `gmb-pay.events.auto_register` so consumers can opt out and wire their own.

### Steps

1. **RED — write the test first** at `tests/Webhook/AutoRegisterListenersTest.php`:
   - **Top of file**: override the test environment to set `gmb-pay.events.auto_register=true` (override `defineEnvironment` in a per-file extension OR use `tap($app['config'])->set(...)` in a `beforeEach`)
   - Persist a charge in `Pending`. POST `/gmb-pay/webhook/modempay` with payload `{"id":"evt_auto","type":"charge.succeeded","provider_reference":"prov_auto"}` — but the AbstractDriver doesn't currently extract `provider_reference` from the payload. So the test should dispatch `WebhookReceived` directly OR the driver must learn to pull `provider_reference` (do that here)
   - Assert charge is `Succeeded` after the dispatch — without registering listeners manually
   - Add a second test that flips `auto_register=false` and asserts the charge stays `Pending`
2. **AbstractDriver::parseWebhook()** — additionally extract `provider_reference` from the payload when present. Keep extraction permissive: accept either `provider_reference` or `reference` keys
3. **Service provider boot()** — when `gmb-pay.events.auto_register` is truthy, call:
   ```php
   Event::listen(WebhookReceived::class, UpdateChargeFromWebhook::class);
   Event::listen(WebhookReceived::class, UpdateRefundFromWebhook::class);
   ```
4. **Config default** — add `'events' => ['auto_register' => true]` to `config/gmb-pay.php`
5. Run `vendor/bin/pest`. Tick F10, append done entry, commit `F10: auto-register webhook listeners`

### Files this feature will touch

- `config/gmb-pay.php` (modified — add `events.auto_register`)
- `src/GmbPayServiceProvider.php` (modified — `Event::listen` calls in boot)
- `src/Drivers/AbstractDriver.php` (modified — also extract `provider_reference`)
- `tests/Webhook/AutoRegisterListenersTest.php` (new)
- `tasks/all-features.md` (check the box)
- `tasks/done.md` (append entry)

### Done criteria

- All Pest tests pass
- With auto-register on (default), a webhook POST advances the charge status
- With auto-register off, a webhook POST writes the row but does not advance the charge
- Existing F08/F09 tests continue to pass (their explicit `Event::listen` is now redundant but not harmful — leave as-is)

### Notes for the implementer

- Use `Event::listen` from the `Illuminate\Support\Facades\Event` facade. Don't invent a new "Listener registration class"
- Idempotency: it's fine to register twice (Laravel handles dup listeners by class). But guard with `if (! $this->app->bound(...))` only if you measure a real problem — premature
- Tests overriding env: per-file extension. See `tests/TestCase.php` — define a child class in the same file that flips the config value, or use `Config::set(...)` in `beforeEach`. Avoid a global flip in `TestCase` so default tests still represent the package default

---

## Blocked

_(features paused mid-implementation — none yet)_
