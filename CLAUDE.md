# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`africs/gmb-pay` — a **Laravel package** (not an application) that unifies Gambian payment gateways behind one API, manager, and webhook surface. PHP 8.3+, Laravel 11/12/13. PSR-4 namespace `Africs\GmbPay\` under `src/`, tests under `Africs\GmbPay\Tests\` in `tests/`.

Status is pre-1.0 scaffolding: drivers are stubbed and only respond when `GMB_PAY_DEMO=true`. No live merchant integrations exist yet.

## Workflow (read this before doing anything)

This repo is built feature-by-feature against a written plan. Before writing code:

1. Read `tasks/all-features.md` — master plan, F01–F61, with a **Context** section documenting test runner, conventions, and reference files. This is authoritative; don't re-derive.
2. Read `tasks/todo.md` — active feature with steps, files-to-touch, and done criteria.
3. Read `tasks/done.md` — what's already shipped and the metadata format for the entry you'll append.

Loop per feature: **RED test first → minimal implementation → full suite green → tick the box in `all-features.md` → append a metadata block to `done.md` → commit `F<NN>: <short description>`.** One commit per feature. The commit subject prefix `F<NN>:` is mandatory and matches the feature numbering in `all-features.md`.

The `Blocked` list in `todo.md` is for features paused mid-implementation, not for `(BLOCKED)` items in `all-features.md` (those are blocked on external merchant access, not on code).

## Commands

```bash
vendor/bin/pest                          # full suite
vendor/bin/pest tests/Path/To/Test.php   # one file
vendor/bin/pest --filter='pattern'       # one test
composer install                          # deps (Pest 4, Orchestra Testbench 11, PHP 8.4 in lock)
```

`phpunit.xml` boots Pest with `APP_ENV=testing` and `GMB_PAY_DEMO=true`. The test base class `Africs\GmbPay\Tests\TestCase` (extends Orchestra Testbench) registers the service provider, aliases the `GmbPay` facade, and `defineDatabaseMigrations()` loads `tests/Fixtures/migrations/` (for the `FakeBillable` morph owner) **plus** `database/migrations/` (the package's own migrations). Persistence tests inherit this for free — don't re-load migrations manually.

There is no Pint/PHPStan config yet (those are F57/F58 in the plan).

## Architecture

**Laravel Manager pattern.** `PaymentManager extends Illuminate\Support\Manager`. Each gateway is registered by adding `protected function create<Name>Driver(): PaymentDriver` and reading `gmb-pay.drivers.<name>` from config. The default driver comes from `GMB_PAY_DEFAULT`. `parent::createDriver()` is wrapped to throw `UnknownDriverException` for unknown names.

**Driver inheritance.** Concrete drivers extend `Drivers/AbstractDriver` and override only what they implement. `AbstractDriver` provides:
- demo-mode stubs for `charge`/`verify`/`refund`/`payout` that return stubbed `*Result` DTOs when `gmb-pay.demo_mode` is true,
- a `notImplemented()` helper that throws `BadMethodCallException` with a directive to flip demo mode on.

This is why a Phase-1 driver like `ModempayDriver` is currently a one-method file (`name()`): tests and local dev work via the demo path until the real implementation lands (F13–F19).

**DTOs and enums are immutable and explicit.** All DataObjects in `src/DataObjects/` are `final readonly class` with constructor property promotion only — no setters, no array-shape `$data`. All enums in `src/Enums/` are backed string enums. **Currency is always in minor units (`amountMinor: int`)** in code and DB; never floats. GMD is the default currency.

**Webhook surface.** `routes/webhooks.php` (auto-loaded by the service provider) exposes `POST {prefix}/{driver}` → `WebhookController::handle()`. The controller resolves the driver via the manager, validates signature (`webhookSignatureValid()`), parses (`parseWebhook()`), and dispatches `Africs\GmbPay\Events\WebhookReceived` carrying a `WebhookEvent` DTO. Persistence (F07) and listener auto-registration (F10) are not yet wired — `parseWebhook` defaults to `WebhookEventType::Unknown`.

**Service provider responsibilities** (`GmbPayServiceProvider`):
- merges `config/gmb-pay.php`, registers the manager singleton + `gmb-pay` alias,
- auto-loads webhook routes, package migrations, and `gmb-pay`-namespaced views,
- publishes config / migrations / views under tags `gmb-pay-config`, `gmb-pay-migrations`, `gmb-pay-views`,
- registers `gmb-pay:install` (which calls all three publishes then `migrate`).

## Conventions

- `declare(strict_types=1);` on every PHP file.
- Custom exceptions extend `Africs\GmbPay\Exceptions\GmbPayException`.
- Demo mode (`GMB_PAY_DEMO=true`) is the default for tests and local dev — drivers must keep their demo path working when adding real implementations (override the method, but call/match the demo branch when `isDemo()` is true if the test needs to keep passing).
- Capability split: drivers that support extras implement `SupportsRecurring` / `SupportsTokenization` (in `src/Contracts/`) instead of bloating `PaymentDriver`.
- The `tasks/all-features.md` **Context** section lists external docs to consult during implementation; use **context7** (the MCP server) when you need Laravel 13 or Modempay docs — don't rely on training data.

## Commits and PRs

Per the user's global instructions:
- Do **not** add `Co-Authored-By: Claude ...` trailers.
- Do **not** add `🤖 Generated with [Claude Code]...` footers.
- Subject must be `F<NN>: <short description>` matching the feature number from `tasks/all-features.md`.
