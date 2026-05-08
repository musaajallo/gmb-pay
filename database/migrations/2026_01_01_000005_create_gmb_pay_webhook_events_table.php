<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmb_pay_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 32);
            $table->string('provider_event_id')->nullable();
            $table->string('type', 64);
            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(
                ['driver', 'provider_event_id'],
                'gmb_pay_webhook_events_driver_provider_event_id_unique'
            );
            $table->index('type', 'gmb_pay_webhook_events_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmb_pay_webhook_events');
    }
};
