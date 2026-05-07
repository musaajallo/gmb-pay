# gmb-pay

A unified Laravel package for Gambian payment gateways. One install, one API, one webhook surface.

> Status: **scaffolding** — drivers stubbed, demo mode works, no live merchant integrations yet.

## Supported gateways

| Gateway        | Phase | Driver       | Status          |
|----------------|-------|--------------|-----------------|
| Modempay       | 1     | `modempay`   | Stub (demo only)|
| Wave (Gambia)  | 1     | `wave`       | Stub (demo only)|
| Waychit        | 1     | `waychit`    | Stub (demo only)|
| Gamswitch      | 2     | `gamswitch`  | Not started     |
| QMoney         | 2     | `qmoney`     | Not started     |
| Africell Money | 2     | `afrimoney`  | Not started     |

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13

## Install

```bash
composer require africs/gmb-pay
php artisan gmb-pay:install
```

The install command publishes:

- `config/gmb-pay.php`
- migrations into `database/migrations`
- Blade views into `resources/views/vendor/gmb-pay`

…then runs `php artisan migrate`.

## Configure

In `.env`:

```env
GMB_PAY_DEFAULT=modempay
GMB_PAY_CURRENCY=GMD
GMB_PAY_DEMO=true   # stubbed responses for local dev

MODEMPAY_PUBLIC_KEY=
MODEMPAY_SECRET_KEY=
MODEMPAY_WEBHOOK_SECRET=

WAVE_API_KEY=
WAVE_WEBHOOK_SECRET=

WAYCHIT_API_KEY=
WAYCHIT_WEBHOOK_SECRET=
```

Webhook URL pattern (configure in each provider's dashboard):

```
https://your-app.test/gmb-pay/webhook/{driver}
```

## Use

```php
use Africs\GmbPay\Facades\GmbPay;
use Africs\GmbPay\DataObjects\ChargeRequest;

$charge = GmbPay::driver('modempay')->charge(new ChargeRequest(
    amountMinor: 5000,        // 50.00 GMD
    currency: 'GMD',
    customerPhone: '+2203000000',
    description: 'Order #1234',
));

return redirect($charge->checkoutUrl);
```

Listen for webhook events:

```php
use Africs\GmbPay\Events\WebhookReceived;

Event::listen(WebhookReceived::function ($event) {
    // $event->event is a WebhookEvent DTO
});
```

## Roadmap

- [x] Service provider + manager + driver contract + DTOs
- [x] Stubbed drivers behind a `GMB_PAY_DEMO` flag
- [x] Webhook routing skeleton
- [ ] Migrations + Eloquent models (customers, charges, refunds, subscriptions, plans, invoices, webhook_events)
- [ ] `Billable` trait
- [ ] Subscription engine (scheduled push-charge + retry/grace)
- [ ] Real Modempay driver
- [ ] Real Wave (Gambia) driver — pending merchant onboarding
- [ ] Real Waychit driver — pending merchant onboarding
- [ ] Publishable Blade views (checkout, status, customer portal)
- [ ] Phase 2 drivers: Gamswitch, QMoney, Africell Money

## License

MIT
