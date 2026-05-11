<?php

declare(strict_types=1);

namespace Africs\GmbPay\Models;

use Africs\GmbPay\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $charge_id
 * @property string $reference
 * @property string|null $provider_reference
 * @property int $amount_minor
 * @property RefundStatus $status
 * @property-read Charge|null $charge
 */
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
