<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmb_pay_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charge_id')
                ->constrained('gmb_pay_charges')
                ->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->string('provider_reference')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 32);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmb_pay_refunds');
    }
};
