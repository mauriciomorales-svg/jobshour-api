<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->onDelete('cascade');
            $table->string('buyer_name');
            $table->string('buyer_email');
            $table->string('buyer_phone')->nullable();
            $table->json('items'); // [{id, nombre, cantidad, precio}]
            $table->integer('total');
            $table->boolean('delivery')->default(false);
            $table->string('delivery_address')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'rejected', 'expired', 'paid'])->default('pending');
            $table->string('mp_payment_id')->nullable();
            $table->string('mp_preference_id')->nullable();
            $table->string('mp_status')->nullable();
            $table->timestamp('expires_at')->nullable(); // 24h para confirmar
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_orders');
    }
};
