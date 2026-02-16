<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->onDelete('cascade');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('original_path');
            $table->string('processed_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->integer('file_size_bytes');
            $table->enum('status', ['uploading', 'processing', 'ready', 'failed'])->default('uploading');
            $table->enum('type', ['profile', 'skill_demo', 'job_completion'])->default('profile');
            $table->integer('view_count')->default(0);
            $table->json('processing_metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['worker_id', 'status']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
