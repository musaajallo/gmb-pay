<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmb_pay_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('provider_reference')->nullable();
            $table->string('driver', 32);
            $table->string('recipient_phone');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 32);
            $table->timestamps();

            $table->unique(
                ['driver', 'provider_reference'],
                'gmb_pay_payouts_driver_provider_ref_unique'
            );
            $table->index('status', 'gmb_pay_payouts_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmb_pay_payouts');
    }
};
