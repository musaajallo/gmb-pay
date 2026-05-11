<?php

declare(strict_types=1);

namespace Africs\GmbPay\Models;

use Africs\GmbPay\Enums\ChargeStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $reference
 * @property string|null $provider_reference
 * @property string $driver
 * @property int|null $customer_id
 * @property int $amount_minor
 * @property string $currency
 * @property ChargeStatus $status
 * @property array<string, mixed>|null $metadata
 * @property-read Customer|null $customer
 * @property-read Collection<int, Refund> $refunds
 */
class Charge extends Model
{
    protected $table = 'gmb_pay_charges';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'status' => ChargeStatus::class,
        'amount_minor' => 'int',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
