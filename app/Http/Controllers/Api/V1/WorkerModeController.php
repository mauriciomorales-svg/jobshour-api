<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\WorkerActiveUpdated;
use App\Http\Controllers\Controller;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkerModeController extends Controller
{
    /**
     * Ciclo de 3 estados: OFF → ACTIVE → LISTENING → OFF
     */
    public function status(Request $request)
    {
        $validated = $request->validate([
            'status' => 'required|in:active,listening,inactive',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'category_id' => 'nullable|exists:categories,id',
        ]);

        $user = $request->user();
        $status = $validated['status'];

        // Mapear 'listening' a 'intermediate' para la BD
        $dbStatus = $status === 'listening' ? 'intermediate' : $status;

        // Buscar worker existente
        $worker = Worker::where('user_id', $user->id)->first();

        // Validar que tenga categoría para active o listening
        if (in_array($status, ['active', 'listening'])) {
            if (!$worker || !$worker->category_id) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'REQUIRE_CATEGORY',
                    'message' => 'Debes seleccionar una categoría para aparecer en el mapa',
                ], 422);
            }
        }

        // Si no existe worker y va a inactive, no hacer nada
        if (!$worker && $status === 'inactive') {
            return response()->json([
                'status' => 'success',
                'message' => 'Estado actualizado',
                'data' => [
                    'availability_status' => 'inactive',
                ],
            ]);
        }

        // Crear o actualizar worker
        if (!$worker) {
            $worker = Worker::create([
                'user_id' => $user->id,
                'category_id' => $validated['category_id'] ?? null,
                'hourly_rate' => 10000,
                'availability_status' => $dbStatus,
            ]);
        } else {
            // Actualizar ubicación y estado
            $bindings = [
                'status' => $dbStatus,
                'last_seen' => now()->toDateTimeString(),
                'updated' => now()->toDateTimeString(),
                'lng' => $validated['lng'],
                'lat' => $validated['lat'],
                'id' => $worker->id,
            ];
            
            $sql = "UPDATE workers SET availability_status = :status, last_seen_at = :last_seen, updated_at = :updated, location = ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)";
            
            if (!empty($validated['category_id'])) {
                $sql .= ", category_id = :cat_id";
                $bindings['cat_id'] = $validated['category_id'];
            }
            
            $sql .= " WHERE id = :id";
            
            DB::update($sql, $bindings);
            $worker->refresh();
        }

        $messages = [
            'active' => '¡Modo Trabajo Activado! Eres visible para todos',
            'listening' => 'Modo Ahorro. Solo te avisaremos de ofertas cercanas',
            'inactive' => 'Te has desconectado del mapa',
        ];

        return response()->json([
            'status' => 'success',
            'message' => $messages[$status],
            'data' => [
                'worker_id' => $worker->id,
                'availability_status' => $status,
                'category_id' => $worker->category_id,
                'lat' => $validated['lat'],
                'lng' => $validated['lng'],
            ],
        ]);
    }

    public function toggle(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'is_active' => 'boolean',
        ]);

        $user = $request->user();
        $isActive = $validated['is_active'] ?? true;

        // Buscar o crear worker - default es 'intermediate' (en escucha/amarillo)
        $worker = Worker::firstOrCreate(
            ['user_id' => $user->id],
            [
                'category_id' => $validated['category_id'],
                'hourly_rate' => 10000,
                'availability_status' => 'intermediate',
            ]
        );

        // Usar named bindings para asegurar que el string tenga comillas
        $statusValue = $isActive ? 'active' : 'inactive';
        
        DB::update(
            "UPDATE workers SET category_id = :cat_id, last_seen_at = :last_seen, availability_status = :status, location = ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), updated_at = :updated WHERE id = :id",
            [
                'cat_id' => $validated['category_id'],
                'last_seen' => now()->toDateTimeString(),
                'status' => $statusValue,
                'lng' => $validated['lng'],
                'lat' => $validated['lat'],
                'updated' => now()->toDateTimeString(),
                'id' => $worker->id,
            ]
        );
        
        // Refrescar modelo
        $worker->refresh();

        // TEMPORALMENTE DESHABILITADO - Reverb no accesible desde producción (timeout 30s)
        // broadcast(new WorkerActiveUpdated($worker, $isActive));

        return response()->json([
            'status' => 'success',
            'message' => $isActive ? 'Modo Worker activado' : 'Modo Worker desactivado',
            'data' => [
                'worker_id' => $worker->id,
                'is_active' => $isActive,
                'availability_status' => $worker->availability_status,
                'category_id' => $worker->category_id,
                'lat' => $validated['lat'],
                'lng' => $validated['lng'],
            ],
        ]);
    }
}
