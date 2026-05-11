<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmb_pay_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('interval', 16);
            $table->unsignedInteger('interval_count')->default(1);
            $table->unsignedInteger('trial_days')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('active', 'gmb_pay_plans_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmb_pay_plans');
    }
};
