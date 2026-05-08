# Done

_Completed features logged here with metadata. Append one block per feature when you tick it in `all-features.md`._

## F04 — Payouts migration + Payout model ✓
- **Tests:** 5/5 passing (full suite 27/27) — `vendor/bin/pest`
- **Files changed:** 3 (3 new)
  - `database/migrations/2026_01_01_000004_create_gmb_pay_payouts_table.php` (new)
  - `src/Models/Payout.php` (new)
  - `tests/Persistence/PayoutTest.php` (new)
- **Lines:** +137 / -0
- **Complexity:** Low — single table, single model, no relations, mirror of charges' unique-index strategy
- **Notes:**
  - `recipient_phone` stored as plain string (not nullable) — driver code normalizes to E.164 on the way in. Phase-1 gateways all require a phone, so non-null is correct now; non-phone rails (bank account, card) would need a column rename later
  - Composite unique `(driver, provider_reference)` matches the charges table — keeps dedup logic identical for whichever subsystem (or future cycle command) needs it
  - No `customer_id` and no `charge_id` — payouts are merchant-initiated money-out, decoupled from inbound charge state. F37/F38 (subscription webhooks) will not touch this table

## F03 — Refunds migration + Refund model ✓
- **Tests:** 6/6 passing (full suite 22/22) — `vendor/bin/pest`
- **Files changed:** 4 (3 new, 1 modified)
  - `database/migrations/2026_01_01_000003_create_gmb_pay_refunds_table.php` (new)
  - `src/Models/Refund.php` (new)
  - `src/Models/Charge.php` (modified — added `refunds(): HasMany`)
  - `tests/Persistence/RefundTest.php` (new)
- **Lines:** +75 / -1
- **Complexity:** Low — single table, single model, one belongsTo + inverse hasMany + enum cast
- **Notes:**
  - `charge_id` uses `constrained()->cascadeOnDelete()` — deleting a charge wipes its refund rows. Acceptable because production refund history will live in the provider too; revisit with soft deletes when audit needs surface
  - No `(driver, provider_reference)` unique on refunds (unlike charges) because provider_reference alone is not globally unique across drivers, but refunds are always reached via `charge_id` so dedup is the listener's job in F09 anyway
  - `unique(reference)` is the local invariant tested explicitly so duplicate-create surfaces a `QueryException` rather than silently inserting

## F02 — Charges migration + Charge model ✓
- **Tests:** 6/6 passing (full suite 16/16) — `vendor/bin/pest`
- **Files changed:** 3 (3 new)
  - `database/migrations/2026_01_01_000002_create_gmb_pay_charges_table.php` (new)
  - `src/Models/Charge.php` (new)
  - `tests/Persistence/ChargeTest.php` (new)
- **Lines:** +156 / -0
- **Complexity:** Low — single table, single model, one belongsTo + enum cast
- **Notes:**
  - `status` cast to `Africs\GmbPay\Enums\ChargeStatus`; backed-string values (`pending`, `succeeded`, `failed`, `cancelled`, `refunded`) round-trip through SQLite cleanly
  - Two unique indexes: `reference` (local id, always set) and `(driver, provider_reference)` (provider id, nullable). SQLite treats multiple NULLs as distinct so dedup works as soon as the provider responds
  - `customer_id` is `nullable + nullOnDelete` because one-shot phone payments don't need a stored customer (see F22)
  - Index on `status` added now to keep the cycle command's `where status = 'active'` lookups cheap when subscriptions land (F34)

## F01 — Customers migration + Customer model ✓
- **Tests:** 4/4 passing (full suite 10/10) — `vendor/bin/pest`
- **Files changed:** 6 (5 new, 1 modified)
  - `database/migrations/2026_01_01_000001_create_gmb_pay_customers_table.php` (new)
  - `src/Models/Customer.php` (new)
  - `tests/Persistence/CustomerTest.php` (new)
  - `tests/Fixtures/Models/FakeBillable.php` (new)
  - `tests/Fixtures/migrations/0000_00_00_000000_create_fake_billables_table.php` (new)
  - `tests/TestCase.php` (added `defineDatabaseMigrations()`)
- **Lines:** +132 / -0
- **Complexity:** Low — single table, single model, polymorphic morphTo
- **Notes:**
  - `defineDatabaseMigrations()` in `TestCase` loads both fixture and package migrations; this is reused by every persistence test going forward
  - Fixture `FakeBillable` lives under `tests/Fixtures/Models/` and a tiny migration creates `fake_billables` — kept generic so it can serve any morph-owner test (charges, refunds, subscriptions)
  - Unique index `(billable_type, billable_id, driver)` is named explicitly (`gmb_pay_customers_billable_driver_unique`) because Laravel's auto-generated name exceeds the 64-char limit on some MySQL configs
  - `metadata` JSON cast to array; column is nullable so rows can be created without provider customer creation (deferred until a driver actually needs it — see F21)

## F00 — Initial scaffold ✓
- **Tests:** 6/6 passing (`vendor/bin/pest`)
- **Files:** 36 new (composer.json, service provider, manager, facade, contract + capability interfaces, 8 DTOs, 4 enums, 3 exceptions, abstract driver + 3 concrete stub drivers, install command, webhook routing + controller, event, phpunit.xml, Pest bootstrap, smoke test, README, LICENSE, .gitignore)
- **Lines:** +900 / -0 (approx)
- **Complexity:** Medium — package scaffolding requires several coordinated pieces (manager + facade + provider + contract + DTOs) before any test can boot
- **Notes:**
  - Demo mode (`GMB_PAY_DEMO=true`) returns stubbed success across all drivers — used as test default and as the local-dev fallback before merchant onboarding
  - Service provider auto-publishes config (`gmb-pay-config`), migrations (`gmb-pay-migrations`), and views (`gmb-pay-views`) tags
  - `routes/webhooks.php` is auto-loaded; webhook URL pattern: `{prefix}/{driver}` where prefix defaults to `gmb-pay/webhook`
  - Composer install resolved to Laravel 13.8.0 + Pest 4.7.0 + Orchestra Testbench 11.1.0 on PHP 8.4.20
