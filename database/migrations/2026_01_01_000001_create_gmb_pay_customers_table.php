<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmb_pay_customers', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('driver', 32);
            $table->string('provider_customer_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['billable_type', 'billable_id', 'driver'],
                'gmb_pay_customers_billable_driver_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmb_pay_customers');
    }
};
