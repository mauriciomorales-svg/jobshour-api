<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->enum('reason', ['no_show', 'wrong_description', 'wrong_address', 'material_missing', 'other']);
            $table->text('description');
            $table->json('evidence_photos')->nullable();
            $table->decimal('worker_lat', 10, 8)->nullable();
            $table->decimal('worker_lng', 11, 8)->nullable();
            $table->decimal('compensation_amount', 10, 2)->nullable();
            $table->boolean('auto_approved')->default(false);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('admin_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['service_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_disputes');
    }
};
