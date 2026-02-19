<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * MODO VIAJE: Elasticidad del Worker
     * 
     * El worker no es solo un punto estático. Es un nodo móvil que puede
     * "absorber" necesidades en ruta. Este campo JSONB permite que el sistema
     * entienda el movimiento y haga matches quirúrgicos.
     */
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            // Campo elástico para rutas activas
            $table->jsonb('active_route')->nullable()->after('location');
            
            // Índice GIN para búsquedas rápidas en JSON
            // Permite queries como: WHERE active_route->>'status' = 'active'
        });
        
        // Crear índice GIN para búsquedas eficientes en el JSONB
        DB::statement('CREATE INDEX workers_active_route_gin ON workers USING GIN(active_route)');
        
        // Comentario para futuros devs
        DB::statement("
            COMMENT ON COLUMN workers.active_route IS 
            'Ruta activa del worker. Estructura elástica:
            {
                \"status\": \"active|completed|cancelled\",
                \"origin\": {\"lat\": float, \"lng\": float, \"address\": string},
                \"destination\": {\"lat\": float, \"lng\": float, \"address\": string},
                \"departure_time\": timestamp,
                \"arrival_time\": timestamp,
                \"available_seats\": int,
                \"cargo_space\": \"sobre|paquete|bulto|null\",
                \"route_type\": \"personal|comercial|mixto\"
            }
            Permite absorber necesidades de transporte, carga o asistencia en ruta.'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS workers_active_route_gin');
        
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn('active_route');
        });
    }
};
