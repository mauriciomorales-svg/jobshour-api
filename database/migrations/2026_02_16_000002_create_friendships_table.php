<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('addressee_id')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['pending', 'accepted', 'blocked'])->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            
            // Evitar duplicados
            $table->unique(['requester_id', 'addressee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friendships');
    }
};
