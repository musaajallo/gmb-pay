<?php

declare(strict_types=1);

use Africs\GmbPay\Contracts\PaymentDriver;
use Africs\GmbPay\DataObjects\ChargeRequest;
use Africs\GmbPay\DataObjects\ChargeResult;
use Africs\GmbPay\DataObjects\PayoutRequest;
use Africs\GmbPay\DataObjects\PayoutResult;
use Africs\GmbPay\DataObjects\RefundRequest;
use Africs\GmbPay\DataObjects\RefundResult;
use Africs\GmbPay\DataObjects\WebhookEvent;
use Africs\GmbPay\Enums\PlanInterval;
use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Jobs\RetryFailedChargeJob;
use Africs\GmbPay\Models\Plan;
use Africs\GmbPay\Models\Subscription;
use Africs\GmbPay\PaymentManager;
use Africs\GmbPay\Tests\Fixtures\Models\FakeBillable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

function makeRetrySubscription(): Subscription
{
    $billable = FakeBillable::create(['name' => 'Retry']);

    $plan = Plan::create([
        'slug' => 'retry-plan-' . uniqid(),
        'name' => 'Retry Plan',
        'amount_minor' => 5000,
        'currency' => 'GMD',
        'interval' => PlanInterval::Month,
    ]);

    $sub = Subscription::create([
        'billable_type' => FakeBillable::class,
        'billable_id' => $billable->id,
        'plan_id' => $plan->id,
        'driver' => 'modempay',
        'status' => SubscriptionStatus::Incomplete,
    ]);

    $sub->items()->create(['unit_amount_minor' => 5000]);

    return $sub;
}

function bindThrowingPaymentManager(): void
{
    $throwingDriver = new class implements PaymentDriver {
        public function name(): string { return 'throwing'; }
        public function charge(ChargeRequest $request): ChargeResult { throw new RuntimeException('Provider unreachable'); }
        public function verify(string $reference): ChargeResult { throw new RuntimeException('n/a'); }
        public function refund(RefundRequest $request): RefundResult { throw new RuntimeException('n/a'); }
        public function payout(PayoutRequest $request): PayoutResult { throw new RuntimeException('n/a'); }
        public function webhookSignatureValid(Request $request): bool { return false; }
        public function parseWebhook(Request $request): WebhookEvent { throw new RuntimeException('n/a'); }
    };

    $manager = new class($throwingDriver) {
        public function __construct(private $driver) {}
        public function driver($name = null) { return $this->driver; }
    };

    app()->instance(PaymentManager::class, $manager);
}

beforeEach(function () {
    Bus::fake();
    config([
        'gmb-pay.demo_mode' => true,
        'gmb-pay.subscriptions.retry_backoff_minutes' => [60, 360, 1440],
    ]);
});

it('marks the subscription PastDue and does not re-dispatch when attempts are exhausted', function () {
    $sub = makeRetrySubscription();

    (new RetryFailedChargeJob($sub, attempt: 4))->handle();

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::PastDue);

    Bus::assertNotDispatched(RetryFailedChargeJob::class);
});

it('re-dispatches with attempt+1 and the next backoff delay when the inner charge throws', function () {
    bindThrowingPaymentManager();

    $sub = makeRetrySubscription();

    (new RetryFailedChargeJob($sub, attempt: 1))->handle();

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Incomplete);

    Bus::assertDispatched(RetryFailedChargeJob::class, function ($job) use ($sub) {
        return $job->subscription->is($sub)
            && $job->attempt === 2
            && $job->delay !== null;
    });
});

it('does not re-dispatch and does not mark PastDue when the inner charge succeeds (demo mode)', function () {
    $sub = makeRetrySubscription();

    (new RetryFailedChargeJob($sub, attempt: 1))->handle();

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Active);

    Bus::assertNotDispatched(RetryFailedChargeJob::class);
});
