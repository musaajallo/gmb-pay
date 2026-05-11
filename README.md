# gmb-pay

A unified Laravel package for Gambian payment gateways. One install, one API, one webhook surface — across Modempay, Wave, Waychit, and Phase-2 gateways behind them.

> **Status:** pre-1.0. Modempay is the only gateway with a real driver today; everything else is demo-only until merchant onboarding lands.

## Supported gateways

| Gateway        | Driver name   | Status                                             |
|----------------|---------------|----------------------------------------------------|
| Modempay       | `modempay`    | **Live** (charge / verify / payout / webhooks)     |
| Wave (Gambia)  | `wave`        | Demo-only — blocked on merchant onboarding         |
| Waychit        | `waychit`     | Demo-only — blocked on merchant onboarding         |
| Gamswitch      | `gamswitch`   | Planned (Phase 2)                                  |
| QMoney         | `qmoney`      | Planned (Phase 2)                                  |
| Africell Money | `afrimoney`   | Planned (Phase 2)                                  |

> Modempay does not currently expose a public refund endpoint. `Billable::refund()` works against the demo driver; against Modempay it throws `BadMethodCallException` until that endpoint becomes available.

## Requirements

- PHP 8.3 or 8.4
- Laravel 11, 12, or 13

## Install

The current release is **`0.1.0-alpha`**. Because of the `-alpha` suffix, Composer needs an explicit stability hint:

```bash
composer require "africs/gmb-pay:^0.1.0-alpha@dev"
php artisan gmb-pay:install
```

The `@dev` flag tells Composer "this constraint is allowed to resolve to a non-stable version." It only widens stability for *this one package* — your app's `minimum-stability` stays untouched. Drop the `@dev` once a stable `0.1.0` is tagged.

If you'd rather not type the stability flag at every `require`, set it once in your app's `composer.json`:

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

…then `composer require africs/gmb-pay:^0.1.0-alpha` (no `@dev`) works. `prefer-stable: true` keeps everything *else* on stable releases — only packages without a stable version (this one, for now) fall through to `dev`.

`gmb-pay:install` publishes:

- `config/gmb-pay.php`
- the package migrations into `database/migrations`
- Blade views into `resources/views/vendor/gmb-pay`

…then runs `php artisan migrate`.

After install, the command prints scheduling guidance — register the cycle command in your app's `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('gmb-pay:cycle')->everyFiveMinutes();
```

This drives recurring charges and grace-period cancellations for subscriptions.

## Configure

In `.env`:

```env
GMB_PAY_DEFAULT=modempay
GMB_PAY_CURRENCY=GMD
GMB_PAY_DEMO=false                # set true locally for stubbed responses

MODEMPAY_PUBLIC_KEY=pk_live_...
MODEMPAY_SECRET_KEY=sk_live_...
MODEMPAY_WEBHOOK_SECRET=whsec_...
MODEMPAY_BASE_URL=https://api.modempay.com
MODEMPAY_TEST_MODE=false

# Wave + Waychit currently demo-only; populate when merchant access lands
WAVE_API_KEY=
WAVE_WEBHOOK_SECRET=
WAYCHIT_API_KEY=
WAYCHIT_WEBHOOK_SECRET=
```

Configure the webhook URL in each provider's dashboard. The package exposes one route per driver:

```
POST https://your-app.test/gmb-pay/webhook/{driver}
```

`{driver}` is `modempay`, `wave`, or `waychit`. The route prefix is configurable via `GMB_PAY_WEBHOOK_PREFIX`.

## The Billable trait — main API

Attach the trait to any Eloquent model (typically `User`):

```php
use Africs\GmbPay\Concerns\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

That exposes five methods + four relations:

```php
$user->charge($amountMinor, $currency, $opts);          // one-shot charge
$user->refund($reference, $partialAmountMinor);         // refund a prior charge
$user->subscribeToPlan($planOrSlug, $opts);             // start a subscription
$user->findChargeByReference($reference);               // ?Charge
$user->subscribed($planSlug = null);                    // bool

$user->gmbPayCustomers;     // MorphMany Customer (one per driver per billable)
$user->gmbPayCharges;       // HasManyThrough Customer → Charge
$user->subscriptions;       // MorphMany Subscription
$user->createGmbPayCustomer($driver, $opts);            // idempotent attach
```

## One-shot charges

```php
$result = $user->charge(
    amountMinor: 5000,          // 50.00 GMD (always minor units)
    currency: 'GMD',
    opts: [
        'description' => 'Order #1234',
        'returnUrl'   => 'https://app.test/payments/return',
        'metadata'    => ['order_id' => 1234],
        'idempotencyKey' => 'order-1234',   // optional dedup token
    ],
);

return redirect($result->checkoutUrl);
```

`charge()` always persists a local `gmb_pay_charges` row linked to the billable's `Customer` for that driver — `idempotencyKey` makes the call replay-safe (duplicate requests return the same `ChargeResult` without re-hitting the provider).

The lower-level facade is available too, when you don't need a Billable:

```php
use Africs\GmbPay\Facades\GmbPay;
use Africs\GmbPay\DataObjects\ChargeRequest;

$result = GmbPay::charge(new ChargeRequest(
    amountMinor: 5000,
    currency: 'GMD',
    customerPhone: '+2207000000',
    idempotencyKey: 'order-1234',
));
```

## Subscriptions

Define a Plan once (typically via a seeder):

```php
use Africs\GmbPay\Models\Plan;
use Africs\GmbPay\Enums\PlanInterval;

Plan::create([
    'slug'          => 'pro-monthly',
    'name'          => 'Pro Monthly',
    'amount_minor'  => 50000,    // 500.00 GMD/month
    'currency'      => 'GMD',
    'interval'      => PlanInterval::Month,
    'trial_days'    => 14,
]);
```

Subscribe a user:

```php
$subscription = $user->subscribeToPlan('pro-monthly');

// Trial users: status === Active, current_period_end === trial_ends_at, no charge fired.
// Non-trial: an InitiateRecurringChargeJob is dispatched to charge for cycle 1.

if ($user->subscribed('pro-monthly')) {
    // gate access to your Pro feature
}

$subscription->cancel();              // immediate
$subscription->cancelAtPeriodEnd();   // soft cancel — runs until period ends
$subscription->resume();              // restore if Canceled or undo soft-cancel
```

The cycle command (`gmb-pay:cycle`, scheduled per your `routes/console.php`) handles renewals: every five minutes it picks up Active subs whose period has expired and dispatches `InitiateRecurringChargeJob` for each. Failed charges flow into `RetryFailedChargeJob` with a backoff schedule (default `60min → 6h → 24h`); after the schedule is exhausted, the subscription moves to `past_due`. If it stays past_due longer than `grace_days` (default 3), it's auto-canceled by the next cycle run.

## Webhooks

The package handles signature verification, dedup, and event routing for you. Modempay-shape payloads (`{"event": "...", "payload": {...}}`) are parsed into a typed `WebhookEvent` DTO and dispatched as the `WebhookReceived` event.

Three listeners are auto-registered (toggleable via `gmb-pay.events.auto_register`):

| Listener                            | Triggers on            | Effect                                                           |
|-------------------------------------|------------------------|------------------------------------------------------------------|
| `UpdateChargeFromWebhook`           | `charge.*` events      | Updates `gmb_pay_charges.status` by `provider_reference`         |
| `UpdateRefundFromWebhook`           | `refund.*` events      | Updates `gmb_pay_refunds.status` similarly                       |
| `MarkInvoicePaidFromWebhook`        | `charge.succeeded`     | Flips Invoice → Paid; recovers PastDue subs to Active            |
| `RetryChargeFromWebhook`            | `charge.failed`/`expired` | Marks sub PastDue + dispatches RetryFailedChargeJob           |

If you want to add your own listener:

```php
use Illuminate\Support\Facades\Event;
use Africs\GmbPay\Events\WebhookReceived;

Event::listen(WebhookReceived::class, function (WebhookReceived $event) {
    $dto = $event->event;
    // $dto->type           — WebhookEventType enum
    // $dto->driver         — 'modempay' / 'wave' / 'waychit'
    // $dto->providerReference — Modempay's payment_intent_id
    // $dto->providerEventId   — composite "<event>:<resource_id>" for dedup
    // $dto->payload        — full raw body as array
});
```

Every incoming webhook is persisted to `gmb_pay_webhook_events` before dispatch, with `(driver, provider_event_id)` deduplicated — replays from the provider don't re-fire listeners.

## Refunds

```php
$user->refund('chg_abc123');             // full refund
$user->refund('chg_abc123', 1500);       // partial (15.00 GMD)
```

Refunds use the driver's `refund()` method. **Modempay does not currently expose a public refund endpoint**; calling refund against Modempay in non-demo mode throws `BadMethodCallException` until that lands. Demo mode (`GMB_PAY_DEMO=true`) returns a stubbed success.

## Demo mode

`GMB_PAY_DEMO=true` (default in tests, opt-in locally) makes every driver return stubbed successful responses without hitting the network. Use it to exercise checkout/return flows end-to-end before merchant onboarding. The webhook signature check returns `true` for any payload in demo mode.

## Modempay specifics

A few Modempay quirks worth knowing:

- **Payouts (`Billable::payout` / `GmbPay::driver('modempay')->payout()`)** require `metadata['network']` to be set (the mobile-money provider code: `africell`, `qcell`, `gamcel`, …). Omitting it raises `GmbPayException` before any HTTP call.
- **Webhook signature** is HMAC-SHA512 over the raw body, sent as `x-modem-signature`. Set `MODEMPAY_WEBHOOK_SECRET` from the Modempay dashboard.
- **Verify endpoint** (`GET /v1/payments/verify`) uses `intent_secret`, not the payment-intent UUID. After redirect-back from checkout, call `GmbPay::driver('modempay')->verify($intentSecret)` to confirm.
- The PI UUID stored in `Charge::$provider_reference` is what webhooks reference (via `payment_intent_id`) — that's what `UpdateChargeFromWebhook` reconciles against.

## Testing your app against the package

The package's own tests run with `GMB_PAY_DEMO=true`, so adding a payment flow to your app won't hit the network in test environments. If you need realistic responses in tests, `Http::fake()` works:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'api.modempay.com/v1/payments' => Http::response([
        'data' => [
            'intent_secret' => 'pi_secret_test',
            'payment_link'  => 'https://checkout.modempay.com/abc',
            'status'        => 'requires_payment_method',
        ],
    ], 200),
]);
```

## Troubleshooting

- **`No Charge with reference [...] found for this Billable.`** — `refund()` only sees Charges linked to one of *this billable's* `Customer` rows. Direct charges via `GmbPay::charge()` (without going through `$user->charge()`) won't show up. Always charge through the trait when you want trait-side helpers to find the row.
- **Webhook listener didn't fire** — check `gmb_pay_webhook_events`. If the row is there but `provider_event_id` is null, the provider didn't send an event id and your dedup window may be wider than expected. If no row at all, the signature check is rejecting — verify `MODEMPAY_WEBHOOK_SECRET` and that you're hitting the right URL.
- **`Modempay payout requires metadata["network"]`** — see Modempay specifics above.
- **Subscription stuck `incomplete`** — the first `InitiateRecurringChargeJob` either hasn't run yet (queue worker?) or its driver call threw. Check `php artisan queue:failed`.

## Scope notes (what's not here and why)

### Blocked on external access — will land when the block lifts

- **Modempay refunds** — Modempay's public docs document a `refunded` transaction status but no endpoint to create a refund. `Billable::refund()` works against the demo driver; against Modempay it throws `BadMethodCallException` until Modempay publishes the endpoint
- **Wave Gambia + Waychit real drivers** — no public API docs; blocked on merchant onboarding
- **Gamswitch / QMoney / Africell Money** — Phase 2 gateways, blocked on merchant onboarding

### Deliberately deferred — not coming unless a real need surfaces

- **Shipped customer-portal Blade views** (originally Phase H: F39–F45) — packaged Blade templates almost always clash with the host app's layout. The package exposes the data you need (`ChargeResult::$checkoutUrl`, `gmb_pay_invoices`, the Billable trait), so build per-app
- **Pre-emptive driver scaffolds for Phase 2 gateways** (F50–F52) — a stub class that can only return demo-mode responses is maintenance surface with no payoff; will land alongside the real implementations
- **`CONTRIBUTING.md`** (F60) — solo project right now, no contribution flow to document. Add when someone outside the maintainer wants to contribute

See `tasks/all-features.md` for the full plan and `tasks/done.md` for shipped-feature metadata. `CHANGELOG.md` tracks releases.

## License

MIT
