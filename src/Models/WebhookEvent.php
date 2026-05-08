<?php

declare(strict_types=1);

namespace Africs\GmbPay\Models;

use Africs\GmbPay\Enums\WebhookEventType;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $table = 'gmb_pay_webhook_events';

    protected $guarded = [];

    protected $casts = [
        'type' => WebhookEventType::class,
        'payload' => 'array',
        'received_at' => 'datetime',
    ];
}
