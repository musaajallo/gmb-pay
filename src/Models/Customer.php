<?php

declare(strict_types=1);

namespace Africs\GmbPay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Customer extends Model
{
    protected $table = 'gmb_pay_customers';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }
}
