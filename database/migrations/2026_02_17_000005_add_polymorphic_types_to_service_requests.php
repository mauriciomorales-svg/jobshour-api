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
            $table->string('category_type', 20)->default('fixed')->after('category_id');
            $table->jsonb('payload')->nullable()->after('description');
        });

        DB::statement("ALTER TABLE service_requests ADD CONSTRAINT service_requests_category_type_check CHECK (category_type IN ('fixed', 'travel', 'errand'))");

        // Columna GEOGRAPHY para rutas de viaje (LineString)
        DB::statement('ALTER TABLE service_requests ADD COLUMN route GEOGRAPHY(LINESTRING, 4326)');

        // Índice para búsquedas por tipo
        DB::statement('CREATE INDEX idx_service_requests_category_type ON service_requests (category_type, status)');

        // Índice GIN para búsquedas en payload JSONB
        DB::statement('CREATE INDEX idx_service_requests_payload ON service_requests USING GIN (payload)');

        // Índice espacial para rutas
        DB::statement('CREATE INDEX idx_service_requests_route ON service_requests USING GIST(route)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_service_requests_route');
        DB::statement('DROP INDEX IF EXISTS idx_service_requests_payload');
        DB::statement('DROP INDEX IF EXISTS idx_service_requests_category_type');
        
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('route');
            $table->dropColumn('payload');
            $table->dropColumn('category_type');
        });
    }
};
