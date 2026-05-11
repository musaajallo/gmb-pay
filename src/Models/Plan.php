<?php

declare(strict_types=1);

namespace Africs\GmbPay\Models;

use Africs\GmbPay\Enums\PlanInterval;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $table = 'gmb_pay_plans';

    protected $guarded = [];

    protected $casts = [
        'amount_minor' => 'int',
        'interval' => PlanInterval::class,
        'interval_count' => 'int',
        'trial_days' => 'int',
        'active' => 'bool',
    ];

    public function nextPeriodEnd(Carbon $start): Carbon
    {
        return match ($this->interval) {
            PlanInterval::Day => $start->copy()->addDays($this->interval_count),
            PlanInterval::Week => $start->copy()->addWeeks($this->interval_count),
            PlanInterval::Month => $start->copy()->addMonths($this->interval_count),
            PlanInterval::Year => $start->copy()->addYears($this->interval_count),
        };
    }
}
