<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ELASTICIDAD DEL OBJETO service_request
     * 
     * No estamos construyendo un módulo cerrado. Este campo request_type
     * es deliberadamente abierto para absorber situaciones futuras:
     * - Hoy: service, ride, delivery
     * - Mañana: asistencia_en_ruta, carga_pesada, servicio_tecnico_movil
     * 
     * La app debe ser maleable y adaptable.
     */
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            // Campo elástico para tipo de solicitud
            // VARCHAR(50) en lugar de ENUM para máxima flexibilidad
            $table->string('request_type', 50)->default('service')->after('category_id');
            
            // Pasajeros para viajes compartidos
            $table->integer('passenger_count')->default(1)->after('request_type');
            
            // Metadata elástica para casos futuros
            $table->jsonb('request_metadata')->nullable()->after('passenger_count');
            
            // Índice compuesto para búsquedas eficientes
            $table->index(['request_type', 'status', 'created_at'], 'idx_request_type_status_time');
        });
        
        // Índice GIN para búsquedas en metadata
        DB::statement('CREATE INDEX service_requests_metadata_gin ON service_requests USING GIN(request_metadata)');
        
        // Comentarios para documentación
        DB::statement("
            COMMENT ON COLUMN service_requests.request_type IS 
            'Tipo de solicitud (elástico):
            - service: Trabajo por horas (estático)
            - ride: Transporte de pasajeros (modo viaje)
            - delivery: Envío de encomiendas (modo viaje)
            - asistencia_en_ruta: Ayuda técnica durante traslado
            - servicio_tecnico_movil: Técnico que se desplaza
            - carga_pesada: Mudanzas, transporte de materiales
            Extensible sin cambios de schema.'
        ");
        
        DB::statement("
            COMMENT ON COLUMN service_requests.request_metadata IS 
            'Metadata adicional específica por tipo de request:
            {
                \"vehicle_type\": \"auto|camioneta|camion\",
                \"tools_required\": [\"llave_inglesa\", \"taladro\"],
                \"special_requirements\": \"Acceso para silla de ruedas\",
                \"estimated_duration_hours\": 2.5,
                \"is_recurring\": false,
                \"recurrence_pattern\": \"weekly\"
            }
            Permite absorber casos edge sin modificar schema.'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS service_requests_metadata_gin');
        
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropIndex('idx_request_type_status_time');
            $table->dropColumn(['request_type', 'passenger_count', 'request_metadata']);
        });
    }
};
