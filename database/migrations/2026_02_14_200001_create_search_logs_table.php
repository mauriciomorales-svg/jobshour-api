<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('category_requested')->nullable();
            $table->integer('results_found')->default(0);
            $table->integer('radius_used')->nullable();
            $table->boolean('was_expanded')->default(false);
            $table->string('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('category_requested');
            $table->index('results_found');
        });

        DB::statement('ALTER TABLE search_logs ADD COLUMN coords geography(POINT, 4326)');
        DB::statement('CREATE INDEX search_logs_coords_idx ON search_logs USING GIST (coords)');
    }

    public function down(): void
    {
        Schema::dropIfExists('search_logs');
    }
};
