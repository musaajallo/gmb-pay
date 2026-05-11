<?php

declare(strict_types=1);

namespace Africs\GmbPay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $billable_type
 * @property int $billable_id
 * @property string $driver
 * @property string|null $provider_customer_id
 * @property array<string, mixed>|null $metadata
 * @property-read Model|null $billable
 */
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
