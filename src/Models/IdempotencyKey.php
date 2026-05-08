<?php

declare(strict_types=1);

namespace Africs\GmbPay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class IdempotencyKey extends Model
{
    protected $table = 'gmb_pay_idempotency_keys';

    protected $guarded = [];

    protected $casts = [
        'target_id' => 'int',
    ];

    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
