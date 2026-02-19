<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_reveals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('worker_id')->constrained()->onDelete('cascade');
            $table->boolean('was_free')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'worker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_reveals');
    }
};
