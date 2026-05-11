<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de webhooks MP procesados para garantizar idempotencia.
 * La combinación (mp_payment_id, event_type) debe ser única: si el webhook
 * llega dos veces (MP reintenta), el segundo se ignora sin duplicar efectos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mp_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('mp_payment_id', 64)->index();
            $table->string('event_type', 32); // 'service_payment', 'boost', 'credits'
            $table->string('external_reference', 128)->nullable();
            $table->string('mp_status', 32)->nullable();
            $table->string('result', 32)->default('ok'); // ok | ignored | error
            $table->text('notes')->nullable();
            $table->timestamps();

            // Clave de idempotencia: mismo pago + mismo tipo de evento → un solo registro
            $table->unique(['mp_payment_id', 'event_type'], 'mp_webhook_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mp_webhook_events');
    }
};
