<?php

declare(strict_types=1);

namespace Africs\GmbPay\Models;

use Africs\GmbPay\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $table = 'gmb_pay_refunds';

    protected $guarded = [];

    protected $casts = [
        'status' => RefundStatus::class,
        'amount_minor' => 'int',
    ];

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }
}
