<?php

declare(strict_types=1);

namespace Africs\GmbPay\Concerns;

use Africs\GmbPay\Models\Charge;
use Africs\GmbPay\Models\Customer;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Billable
{
    public function gmbPayCustomers(): MorphMany
    {
        return $this->morphMany(Customer::class, 'billable');
    }

    public function gmbPayCharges(): HasManyThrough
    {
        return $this->hasManyThrough(
            Charge::class,
            Customer::class,
            'billable_id',
            'customer_id',
            $this->getKeyName(),
            'id',
        )->where('gmb_pay_customers.billable_type', $this->getMorphClass());
    }

    public function createGmbPayCustomer(?string $driver = null, array $opts = []): Customer
    {
        $driver = $driver ?? (string) config('gmb-pay.default', 'modempay');

        return Customer::firstOrCreate(
            [
                'billable_type' => $this->getMorphClass(),
                'billable_id' => $this->getKey(),
                'driver' => $driver,
            ],
            [
                'metadata' => $opts['metadata'] ?? [],
            ],
        );
    }
}
