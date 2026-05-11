<?php

declare(strict_types=1);

namespace Africs\GmbPay\Console;

use Africs\GmbPay\Enums\SubscriptionStatus;
use Africs\GmbPay\Jobs\InitiateRecurringChargeJob;
use Africs\GmbPay\Models\Subscription;
use Illuminate\Console\Command;

class CycleCommand extends Command
{
    protected $signature = 'gmb-pay:cycle';

    protected $description = 'Initiate recurring charges for due subscriptions';

    public function handle(): int
    {
        Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->where('current_period_end', '<=', now())
            ->each(function (Subscription $subscription): void {
                InitiateRecurringChargeJob::dispatch($subscription);
            });

        $graceDays = (int) config('gmb-pay.subscriptions.grace_days', 3);

        Subscription::query()
            ->where('status', SubscriptionStatus::PastDue)
            ->where('updated_at', '<=', now()->subDays($graceDays))
            ->each(function (Subscription $subscription): void {
                $subscription->markCanceled();
            });

        return self::SUCCESS;
    }
}
