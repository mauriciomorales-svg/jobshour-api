<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('worker_id')->nullable()->constrained('workers')->onDelete('set null');
            $table->string('title');
            $table->text('description');
            $table->json('skills_required')->nullable();
            $table->string('address')->nullable();
            $table->decimal('budget', 10, 2)->nullable();
            $table->enum('payment_type', ['hourly', 'fixed', 'negotiable'])->default('negotiable');
            $table->enum('urgency', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('status', ['open', 'assigned', 'in_progress', 'completed', 'cancelled'])->default('open');
            $table->timestamp('scheduled_at')->nullable();
            $table->integer('estimated_duration_minutes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('final_price', 10, 2)->nullable();
            $table->timestamps();

            $table->index(['status', 'urgency']);
            $table->index(['created_at']);
        });

        DB::statement('ALTER TABLE jobs ADD COLUMN location geometry(Point, 4326)');
        DB::statement('CREATE INDEX jobs_location_spatial ON jobs USING GIST(location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
