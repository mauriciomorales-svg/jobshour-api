<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('integrated_quote_items')) {
            return;
        }

        Schema::create('integrated_quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integrated_quote_id')->constrained('integrated_quotes')->onDelete('cascade');

            // product|service_labor|delivery_fee|tool_wear
            $table->string('type', 30);

            // Referencia externa (ej: idproducto inventario-api)
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->string('title')->nullable(); // nombre visible
            $table->integer('quantity')->default(1);
            $table->integer('unit_amount')->default(0); // CLP entero
            $table->integer('subtotal_amount')->default(0); // CLP entero
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['integrated_quote_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrated_quote_items');
    }
};

