<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title')->nullable();
            $table->text('bio')->nullable();
            $table->json('skills')->nullable();
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->enum('availability_status', ['active', 'intermediate', 'inactive'])->default('inactive');
            $table->timestamp('last_seen_at')->nullable();
            $table->decimal('location_accuracy', 8, 2)->nullable();
            $table->json('service_area')->nullable();
            $table->integer('total_jobs_completed')->default(0);
            $table->decimal('rating', 2, 1)->default(0.0);
            $table->integer('rating_count')->default(0);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->index(['availability_status', 'last_seen_at']);
        });

        DB::statement('ALTER TABLE workers ADD COLUMN location geometry(Point, 4326)');
        DB::statement('CREATE INDEX workers_location_spatial ON workers USING GIST(location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};
