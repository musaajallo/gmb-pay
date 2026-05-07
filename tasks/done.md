# Done

_Completed features logged here with metadata. Append one block per feature when you tick it in `all-features.md`._

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
