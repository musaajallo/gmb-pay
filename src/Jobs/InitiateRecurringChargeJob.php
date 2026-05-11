<?php

declare(strict_types=1);

namespace Africs\GmbPay\Jobs;

use Africs\GmbPay\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InitiateRecurringChargeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public Subscription $subscription) {}

    public function handle(): void
    {
        // F32 fills in: build ChargeRequest from Subscription + Plan, call driver->charge(),
        // create Charge + Invoice rows linking the cycle. Stubbed here so F29's subscribeToPlan()
        // can dispatch without the queue worker erroring on an unknown class.
    }
}
