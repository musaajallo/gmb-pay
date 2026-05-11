<?php

declare(strict_types=1);

namespace Africs\GmbPay\Models;

use Africs\GmbPay\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $billable_type
 * @property int $billable_id
 * @property int $plan_id
 * @property string $driver
 * @property SubscriptionStatus $status
 * @property \Illuminate\Support\Carbon|null $current_period_start
 * @property \Illuminate\Support\Carbon|null $current_period_end
 * @property bool $cancel_at_period_end
 * @property \Illuminate\Support\Carbon|null $canceled_at
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property-read Model|null $billable
 * @property-read Plan|null $plan
 * @property-read Collection<int, SubscriptionItem> $items
 * @property-read Collection<int, Invoice> $invoices
 */
class Subscription extends Model
{
    protected $table = 'gmb_pay_subscriptions';

    protected $guarded = [];

    protected $casts = [
        'status' => SubscriptionStatus::class,
        'cancel_at_period_end' => 'bool',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'canceled_at' => 'datetime',
        'trial_ends_at' => 'datetime',
    ];

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function cancel(): self
    {
        $this->forceFill([
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => now(),
            'cancel_at_period_end' => false,
        ])->save();

        return $this;
    }

    public function cancelAtPeriodEnd(): self
    {
        $this->forceFill(['cancel_at_period_end' => true])->save();

        return $this;
    }

    public function resume(): self
    {
        $attributes = ['cancel_at_period_end' => false];

        if ($this->status === SubscriptionStatus::Canceled) {
            $attributes['status'] = SubscriptionStatus::Active;
            $attributes['canceled_at'] = null;
        }

        $this->forceFill($attributes)->save();

        return $this;
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function pastDue(): bool
    {
        return $this->status === SubscriptionStatus::PastDue;
    }

    public function active(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }

    public function markPastDue(): self
    {
        $this->forceFill(['status' => SubscriptionStatus::PastDue])->save();

        return $this;
    }

    public function markCanceled(): self
    {
        return $this->cancel();
    }
}
