<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            // 'driver' = chofer publica ruta | 'passenger' = pasajero publica necesidad
            $table->string('travel_role', 20)->nullable()->after('type');
        });

        DB::statement("
            ALTER TABLE service_requests
            ADD CONSTRAINT service_requests_travel_role_check
            CHECK (travel_role IS NULL OR travel_role IN ('driver', 'passenger'))
        ");

        DB::statement('CREATE INDEX idx_service_requests_travel_role ON service_requests (travel_role) WHERE travel_role IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_service_requests_travel_role');
        DB::statement('ALTER TABLE service_requests DROP CONSTRAINT IF EXISTS service_requests_travel_role_check');
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('travel_role');
        });
    }
};
