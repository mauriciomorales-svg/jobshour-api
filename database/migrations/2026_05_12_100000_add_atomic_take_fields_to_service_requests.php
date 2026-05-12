<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Añade los campos necesarios para el cierre atómico de demandas públicas:
 *
 *  taken_by_worker_id  – FK al worker que "reclamó" la demanda pública.
 *                        NULL = libre; NOT NULL = ya tomada (mutex de BD).
 *  taken_at            – Timestamp en que fue reclamada.
 *  derived_from_demand_id – FK a la demanda pública original (en la solicitud derivada).
 *
 * El flujo queda así:
 *   1. Worker llama POST /demand/{id}/take
 *   2. UPDATE atómico: SET taken_by_worker_id = ? WHERE id = ? AND taken_by_worker_id IS NULL
 *   3. Si 0 filas afectadas → otro worker llegó primero → 409 Conflict
 *   4. Si 1 fila afectada → crear ServiceRequest derivada con derived_from_demand_id = demanda.id
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('taken_by_worker_id')->nullable()->after('worker_id');
            $table->timestamp('taken_at')->nullable()->after('taken_by_worker_id');
            $table->unsignedBigInteger('derived_from_demand_id')->nullable()->after('taken_at');

            $table->foreign('taken_by_worker_id')
                ->references('id')->on('workers')
                ->nullOnDelete();

            $table->foreign('derived_from_demand_id')
                ->references('id')->on('service_requests')
                ->nullOnDelete();

            $table->index('taken_by_worker_id');
            $table->index('derived_from_demand_id');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropForeign(['taken_by_worker_id']);
            $table->dropForeign(['derived_from_demand_id']);
            $table->dropIndex(['taken_by_worker_id']);
            $table->dropIndex(['derived_from_demand_id']);
            $table->dropColumn(['taken_by_worker_id', 'taken_at', 'derived_from_demand_id']);
        });
    }
};
