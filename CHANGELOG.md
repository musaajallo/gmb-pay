# Changelog

All notable changes to `africs/gmb-pay` are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html) and the format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.1.0-alpha] - 2026-05-11

First public alpha. Modempay is the only live driver. Wave Gambia and Waychit are stubs blocked on merchant onboarding.

### Added

#### Public API
- `Africs\GmbPay\Concerns\Billable` trait — drop into any Eloquent model:
  - `gmbPayCustomers` morphMany, `gmbPayCharges` hasManyThrough, `subscriptions` morphMany
  - `createGmbPayCustomer(?$driver, $opts)` — idempotent local Customer attach per driver
  - `charge(int $amountMinor, string $currency, array $opts)` — drives the driver, persists a Charge linked to the Billable's Customer, supports `idempotencyKey` short-circuit
  - `findChargeByReference(string $reference): ?Charge` — billable-scoped lookup
  - `refund(string $reference, ?int $amountMinor)` — full or partial refund (driver must support refunds — Modempay currently does not)
  - `subscribeToPlan(Plan|string $plan, $opts)` — creates Subscription + first SubscriptionItem, dispatches `InitiateRecurringChargeJob`
  - `subscribed(?string $planSlug)` — bool, single EXISTS query
- `GmbPay::charge(ChargeRequest, ?string $driverName): ChargeResult` — lower-level facade access with idempotency built in

#### Drivers
- **Modempay (live):** `charge()` via `POST /v1/payments`, `verify()` via `GET /v1/payments/verify`, `payout()` via `POST /v1/transfers`, HMAC-SHA512 webhook signature verification, wrapped webhook payload parsing (`{"event": ..., "payload": ...}` shape)
- **Wave, Waychit:** demo-mode stubs in place; real implementations blocked on merchant onboarding
- `AbstractDriver` provides demo-mode stubs for `charge` / `verify` / `refund` / `payout` so `GMB_PAY_DEMO=true` works out of the box for tests + local dev

#### Subscription engine
- `gmb-pay:cycle` Artisan command — dispatches `InitiateRecurringChargeJob` for due Active subs, marks PastDue subs Canceled after `grace_days`
- `InitiateRecurringChargeJob` — first-cycle Charge + Invoice creation; trial-aware
- `RetryFailedChargeJob` — three-attempt backoff (`60min → 6h → 24h` by default); marks Subscription `PastDue` on exhaustion
- Webhook listeners (auto-registered, toggle via `gmb-pay.events.auto_register`):
  - `UpdateChargeFromWebhook` — reconciles Charge status from `charge.*` events
  - `UpdateRefundFromWebhook` — same for `refund.*`
  - `MarkInvoicePaidFromWebhook` — flips Invoice → Paid; recovers PastDue subs
  - `RetryChargeFromWebhook` — `charge.failed`/`expired` → PastDue + dispatch retry

#### Persistence
- 10 migrations: `gmb_pay_customers`, `gmb_pay_charges`, `gmb_pay_refunds`, `gmb_pay_payouts`, `gmb_pay_webhook_events`, `gmb_pay_idempotency_keys`, `gmb_pay_plans`, `gmb_pay_subscriptions`, `gmb_pay_subscription_items`, `gmb_pay_invoices`
- 11 Eloquent models with typed `@property` docblocks
- Composite indexes on `(status, current_period_end)` and `(status, period_end)` for cycle-command queries
- `IdempotencyStore` service (`remember(driver, key, callback): Model`) backing `ChargeRequest::$idempotencyKey`
- Webhook event dedup at the `(driver, provider_event_id)` unique-index level

#### Tooling
- Pint (`pint.json`, `laravel` preset) for formatting
- Larastan/PHPStan level 6 (`phpstan.neon`) clean across `src/`
- GitHub Actions CI matrix: PHP 8.3/8.4 × Laravel 11/12/13 × Pint/PHPStan/Pest
- `gmb-pay:install` Artisan command — publishes config, migrations, views, prints scheduling guidance

### Known limitations

- **Modempay refunds**: Modempay's public docs document a `refunded` transaction status but no API endpoint to create a refund. `Billable::refund()` works against demo mode; against the live driver it throws `BadMethodCallException`. Will unblock when Modempay publishes a refund endpoint.
- **Wave Gambia, Waychit**: real driver implementations blocked on merchant onboarding (no public API docs).
- **Gamswitch, QMoney, Africell Money**: planned for Phase 2; no implementation yet.
- **No shipped Blade views**: customer portal / checkout / status views deferred. Build per-app since shipped Blade often clashes with the host app's layout.
- **Period advancement uses `updated_at` as a "PastDue since" proxy** in the grace-period enforcer. Works as long as nothing else writes to a PastDue subscription before grace expires. A dedicated `past_due_since` timestamp column may land later if a real flow surfaces the limitation.

### Tested matrix

- PHP 8.3 + Laravel 11
- PHP 8.3 + Laravel 12
- PHP 8.3 + Laravel 13
- PHP 8.4 + Laravel 11
- PHP 8.4 + Laravel 12
- PHP 8.4 + Laravel 13

186 Pest assertions across persistence, webhook, driver, idempotency, billable, job, console layers.

[Unreleased]: https://github.com/musaajallo/gmb-pay/compare/0.1.0-alpha...HEAD
[0.1.0-alpha]: https://github.com/musaajallo/gmb-pay/releases/tag/0.1.0-alpha
