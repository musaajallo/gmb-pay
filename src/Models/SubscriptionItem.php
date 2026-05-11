<?php

declare(strict_types=1);

namespace Africs\GmbPay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $subscription_id
 * @property int $quantity
 * @property int $unit_amount_minor
 * @property-read Subscription|null $subscription
 */
class SubscriptionItem extends Model
{
    protected $table = 'gmb_pay_subscription_items';

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'int',
        'unit_amount_minor' => 'int',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
