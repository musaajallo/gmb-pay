<?php

declare(strict_types=1);

namespace Africs\GmbPay\Models;

use Africs\GmbPay\Enums\PlanInterval;
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
}
