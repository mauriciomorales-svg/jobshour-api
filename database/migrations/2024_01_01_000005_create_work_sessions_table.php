<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('worker_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('job_id')->constrained()->onDelete('cascade');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->decimal('actual_hours', 5, 2)->nullable();
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->string('payment_status', 20)->default('pending');
            $table->boolean('employer_confirmed')->default(false);
            $table->boolean('worker_confirmed')->default(false);
            $table->timestamps();

            $table->index(['employer_id', 'payment_status']);
            $table->index(['worker_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_sessions');
    }
};
