<?php

declare(strict_types=1);

namespace Africs\GmbPay\Jobs;

use Africs\GmbPay\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RetryFailedChargeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public int $attempt = 1,
    ) {}

    public function handle(): void
    {
        $backoffs = (array) config('gmb-pay.subscriptions.retry_backoff_minutes', [60, 360, 1440]);

        if ($this->attempt > count($backoffs)) {
            $this->subscription->fresh()?->markPastDue();

            return;
        }

        try {
            (new InitiateRecurringChargeJob($this->subscription))->handle();
        } catch (\Throwable $e) {
            $delayMinutes = (int) ($backoffs[$this->attempt - 1] ?? end($backoffs));

            self::dispatch($this->subscription, $this->attempt + 1)
                ->delay(now()->addMinutes($delayMinutes));
        }
    }
}
