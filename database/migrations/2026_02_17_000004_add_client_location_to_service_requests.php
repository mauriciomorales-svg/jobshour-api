<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Agregar columnas para Publicación Dorada
        Schema::table('service_requests', function (Blueprint $table) {
            $table->timestamp('pin_expires_at')->nullable()->after('expires_at');
        });

        // Agregar columna GEOGRAPHY para ubicación del cliente
        DB::statement('ALTER TABLE service_requests ADD COLUMN client_location GEOGRAPHY(POINT, 4326)');

        // Índice espacial GIST para búsquedas geográficas
        DB::statement('CREATE INDEX idx_service_requests_client_location ON service_requests USING GIST(client_location)');

        // Índice compuesto para solicitudes activas en mapa
        DB::statement("CREATE INDEX idx_service_requests_status_location ON service_requests (status) WHERE client_location IS NOT NULL AND status = 'pending'");

        // Índice para limpieza de pins expirados
        DB::statement("CREATE INDEX idx_service_requests_pin_expiry ON service_requests (pin_expires_at) WHERE status = 'pending'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_service_requests_pin_expiry');
        DB::statement('DROP INDEX IF EXISTS idx_service_requests_status_location');
        DB::statement('DROP INDEX IF EXISTS idx_service_requests_client_location');
        
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('client_location');
            $table->dropColumn('pin_expires_at');
        });
    }
};
