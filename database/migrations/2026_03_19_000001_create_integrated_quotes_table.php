<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('integrated_quotes')) {
            return;
        }

        Schema::create('integrated_quotes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('worker_id')->constrained('workers')->onDelete('cascade');

            $table->string('status', 40)->default('draft');

            // Totales
            $table->integer('total_amount')->default(0); // CLP en entero
            $table->integer('service_amount')->default(0);
            $table->integer('materials_amount')->default(0);
            $table->integer('delivery_amount')->default(0);
            $table->integer('tool_wear_amount')->default(0);

            // Servicio asociado (si aplica)
            $table->string('service_type', 30)->nullable(); // fixed_job|express_errand|ride_share|null
            $table->text('service_description')->nullable();

            // Delivery (si aplica)
            $table->boolean('wants_delivery')->default(false);
            $table->string('delivery_address')->nullable();
            $table->decimal('delivery_lat', 10, 8)->nullable();
            $table->decimal('delivery_lng', 11, 8)->nullable();

            // Pago
            $table->string('payment_link')->nullable();
            $table->string('mp_preference_id')->nullable();
            $table->string('mp_payment_id')->nullable();
            $table->string('mp_status')->nullable();

            // Extra flexible (MVP)
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->index(['worker_id', 'status']);
            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrated_quotes');
    }
};

