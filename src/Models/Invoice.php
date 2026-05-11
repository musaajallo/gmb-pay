<?php

declare(strict_types=1);

namespace Africs\GmbPay\Models;

use Africs\GmbPay\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $subscription_id
 * @property int|null $charge_id
 * @property int $amount_minor
 * @property string $currency
 * @property InvoiceStatus $status
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property-read Subscription|null $subscription
 * @property-read Charge|null $charge
 */
class Invoice extends Model
{
    protected $table = 'gmb_pay_invoices';

    protected $guarded = [];

    protected $casts = [
        'status' => InvoiceStatus::class,
        'amount_minor' => 'int',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }
}
