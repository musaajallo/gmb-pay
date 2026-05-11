<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmb_pay_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')
                ->constrained('gmb_pay_subscriptions')
                ->cascadeOnDelete();
            $table->foreignId('charge_id')
                ->nullable()
                ->constrained('gmb_pay_charges')
                ->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 32);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamps();

            $table->index(
                ['status', 'period_end'],
                'gmb_pay_invoices_status_period_end_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmb_pay_invoices');
    }
};
