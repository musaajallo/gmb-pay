<?php

declare(strict_types=1);

namespace Africs\GmbPay\Models;

use Africs\GmbPay\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    protected $table = 'gmb_pay_payouts';

    protected $guarded = [];

    protected $casts = [
        'status' => PayoutStatus::class,
        'amount_minor' => 'int',
    ];
}
