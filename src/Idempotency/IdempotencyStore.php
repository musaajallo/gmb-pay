<?php

declare(strict_types=1);

namespace Africs\GmbPay\Idempotency;

use Africs\GmbPay\Models\IdempotencyKey;
use Illuminate\Database\Eloquent\Model;

class IdempotencyStore
{
    public function remember(string $driver, string $key, callable $callback): Model
    {
        $existing = IdempotencyKey::where('driver', $driver)
            ->where('key', $key)
            ->first();

        if ($existing !== null && $existing->target_type !== null && $existing->target_id !== null) {
            return $existing->target;
        }

        $target = $callback();

        IdempotencyKey::updateOrCreate(
            ['driver' => $driver, 'key' => $key],
            ['target_type' => $target::class, 'target_id' => $target->getKey()],
        );

        return $target;
    }
}
