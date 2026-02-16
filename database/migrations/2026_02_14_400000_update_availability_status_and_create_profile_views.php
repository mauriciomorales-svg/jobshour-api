<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Migrar availability_status de (offline, available, busy) a (active, intermediate, inactive)
        DB::statement("ALTER TABLE workers DROP CONSTRAINT IF EXISTS workers_availability_status_check");
        DB::statement("UPDATE workers SET availability_status = 'active' WHERE availability_status = 'available'");
        DB::statement("UPDATE workers SET availability_status = 'inactive' WHERE availability_status IN ('offline', 'busy')");
        DB::statement("ALTER TABLE workers ADD CONSTRAINT workers_availability_status_check CHECK (availability_status IN ('active', 'intermediate', 'inactive'))");

        // 2. Profile views (Shadow Interest)
        Schema::create('profile_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->onDelete('cascade');
            $table->foreignId('viewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('viewer_ip', 45)->nullable();
            $table->string('viewer_city')->nullable();
            $table->boolean('notified')->default(false);
            $table->timestamps();

            $table->index(['worker_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_views');

        DB::statement("ALTER TABLE workers DROP CONSTRAINT IF EXISTS workers_availability_status_check");
        DB::statement("UPDATE workers SET availability_status = 'available' WHERE availability_status = 'active'");
        DB::statement("UPDATE workers SET availability_status = 'offline' WHERE availability_status IN ('intermediate', 'inactive')");
        DB::statement("ALTER TABLE workers ADD CONSTRAINT workers_availability_status_check CHECK (availability_status IN ('offline', 'available', 'busy'))");
    }
};
