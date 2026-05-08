<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmb_pay_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 32);
            $table->string('key', 191);
            $table->string('target_type', 191)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['driver', 'key'],
                'gmb_pay_idempotency_keys_driver_key_unique'
            );
            $table->index(
                ['target_type', 'target_id'],
                'gmb_pay_idempotency_keys_target_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmb_pay_idempotency_keys');
    }
};
