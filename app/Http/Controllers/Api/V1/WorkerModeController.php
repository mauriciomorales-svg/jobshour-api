<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\WorkerActiveUpdated;
use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'multitask' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $status = $validated['status'];

        // Mapear 'listening' a 'intermediate' para la BD
        $dbStatus = $status === 'listening' ? 'intermediate' : $status;

        // Buscar worker existente
        $worker = Worker::where('user_id', $user->id)->first();

        // Si envía categories (multitasking), sincronizar
        if ($worker && !empty($validated['categories'])) {
            // Sincronizar categorías en tabla pivote
            $worker->categories()->sync($validated['categories']);
            
            // Actualizar category_id principal (primera del array)
            $worker->category_id = $validated['categories'][0];
            $worker->save();
        } elseif ($worker && !empty($validated['category_id'])) {
            // Modo legacy: solo una categoría
            $worker->category_id = $validated['category_id'];
            $worker->save();
        }

        // Validar que tenga al menos una categoría para active o listening
        if (in_array($status, ['active', 'listening'])) {
            $hasCategories = !empty($validated['categories']) || (!empty($validated['category_id']));
            $workerHasCategory = $worker && ($worker->category_id || $worker->categories()->count() > 0);
            
            if (!$hasCategories && !$workerHasCategory) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'REQUIRE_CATEGORY',
                    'message' => 'Debes seleccionar al menos una categoría para aparecer en el mapa',
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
            $primaryCategory = $validated['categories'][0] ?? $validated['category_id'] ?? null;
            
            $worker = Worker::create([
                'user_id' => $user->id,
                'category_id' => $primaryCategory,
                'hourly_rate' => 10000,
                'availability_status' => $dbStatus,
                'user_mode' => 'socio', // IMPORTANTE: Establecer como socio para aparecer en el mapa
            ]);
            
            // Si es multitasking, sincronizar todas las categorías
            if (!empty($validated['categories'])) {
                $worker->categories()->sync($validated['categories']);
            }
        } else {
            // Actualizar ubicación y estado
            // IMPORTANTE: Si va a active o listening, asegurar que user_mode = 'socio' para aparecer en el mapa
            $bindings = [
                'status' => $dbStatus,
                'last_seen' => now()->toDateTimeString(),
                'updated' => now()->toDateTimeString(),
                'lng' => $validated['lng'],
                'lat' => $validated['lat'],
                'id' => $worker->id,
            ];
            
            $sql = "UPDATE workers SET availability_status = :status, last_seen_at = :last_seen, updated_at = :updated, location = ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)";
            
            // Si va a active o listening, asegurar user_mode = 'socio' para aparecer en el mapa
            if (in_array($status, ['active', 'listening'])) {
                $sql .= ", user_mode = 'socio'";
            }
            // Si va a inactive, NO cambiar user_mode (mantener el que tiene)
            // El backend en ExpertController ya filtra por availability_status, así que inactive no aparecerá
            
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

        // Cargar categorías para la respuesta
        $worker->load('categories');

        // Broadcast al mapa de clientes (Pusher — canal público 'workers')
        try {
            broadcast(new WorkerActiveUpdated($worker, $dbStatus === 'active'));
        } catch (\Throwable $e) {
            Log::warning('[Broadcast] WorkerActiveUpdated falló', ['error' => $e->getMessage()]);
        }

        // FCM push al propio worker notificando cambio de estado
        $this->notifyWorkerStatusChange($worker, $status);

        // Actualizar cache "último visto"
        Cache::put("worker_last_seen_{$worker->id}", now()->toISOString(), 3600);
        
        return response()->json([
            'status' => 'success',
            'message' => $messages[$status],
            'data' => [
                'worker_id' => $worker->id,
                'availability_status' => $status,
                'category_id' => $worker->category_id,
                'categories' => $worker->categories->pluck('id')->toArray(),
                'multitask' => $validated['multitask'] ?? false,
                'lat' => $validated['lat'],
                'lng' => $validated['lng'],
            ],
        ]);
    }

    public function toggle(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'is_active' => 'boolean',
        ]);

        $user = $request->user();
        $isActive = $validated['is_active'] ?? true;

        // Determinar categoría primaria (primera del array o category_id individual)
        $primaryCategory = $validated['categories'][0] ?? $validated['category_id'] ?? null;

        // Buscar o crear worker - default es 'intermediate' (en escucha/amarillo)
        $worker = Worker::firstOrCreate(
            ['user_id' => $user->id],
            [
                'category_id' => $primaryCategory,
                'hourly_rate' => 10000,
                'availability_status' => 'intermediate',
                'user_mode' => 'socio',
            ]
        );

        // Si envía categories (multitasking), sincronizar tabla pivote
        if (!empty($validated['categories'])) {
            $worker->categories()->sync($validated['categories']);
            $worker->category_id = $validated['categories'][0];
            $worker->save();
        } elseif (!empty($validated['category_id'])) {
            // Modo legacy: solo una categoría
            $worker->category_id = $validated['category_id'];
            $worker->save();
        }

        // Usar named bindings para asegurar que el string tenga comillas
        $statusValue = $isActive ? 'active' : 'inactive';
        
        // Construir SQL dinámicamente
        $bindings = [
            'last_seen' => now()->toDateTimeString(),
            'status' => $statusValue,
            'lng' => $validated['lng'],
            'lat' => $validated['lat'],
            'updated' => now()->toDateTimeString(),
            'id' => $worker->id,
        ];
        
        $sql = "UPDATE workers SET last_seen_at = :last_seen, availability_status = :status, location = ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), updated_at = :updated";
        
        // Si va a active, asegurar user_mode = 'socio' para aparecer en el mapa
        if ($isActive) {
            $sql .= ", user_mode = 'socio'";
        }
        
        // Agregar category_id si se proporcionó
        if ($primaryCategory) {
            $sql .= ", category_id = :cat_id";
            $bindings['cat_id'] = $primaryCategory;
        }
        
        $sql .= " WHERE id = :id";
        
        DB::update($sql, $bindings);
        
        // Refrescar modelo
        $worker->refresh();
        $worker->load('categories');

        // Broadcast al mapa de clientes
        try {
            broadcast(new WorkerActiveUpdated($worker, $isActive));
        } catch (\Throwable $e) {
            Log::warning('[Broadcast] WorkerActiveUpdated toggle falló', ['error' => $e->getMessage()]);
        }

        // FCM push al worker
        $statusLabel = $isActive ? 'active' : 'inactive';
        $this->notifyWorkerStatusChange($worker, $statusLabel);

        // Cache último visto
        Cache::put("worker_last_seen_{$worker->id}", now()->toISOString(), 3600);

        return response()->json([
            'status' => 'success',
            'message' => $isActive ? 'Modo Worker activado' : 'Modo Worker desactivado',
            'data' => [
                'worker_id' => $worker->id,
                'is_active' => $isActive,
                'availability_status' => $worker->availability_status,
                'category_id' => $worker->category_id,
                'categories' => $worker->categories->pluck('id')->toArray(),
                'lat' => $validated['lat'],
                'lng' => $validated['lng'],
            ],
        ]);
    }

    /**
     * Cambiar entre modo Socio y Empresa
     */
    public function switchMode(Request $request)
    {
        $validated = $request->validate([
            'user_mode' => 'required|in:socio,empresa',
        ]);

        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes un perfil de worker activo',
            ], 404);
        }

        $worker->user_mode = $validated['user_mode'];
        $worker->save();

        $messages = [
            'socio' => '🟡 Modo Socio activado. Visible en mapa público',
            'empresa' => '🏢 Modo Empresa activado. Solo visible como referencia',
        ];

        return response()->json([
            'status' => 'success',
            'message' => $messages[$validated['user_mode']],
            'data' => [
                'user_mode' => $worker->user_mode,
                'visible_in_map' => $worker->user_mode === 'socio',
            ],
        ]);
    }

    private function notifyWorkerStatusChange(Worker $worker, string $status): void
    {
        $worker->loadMissing('user');
        $fcmToken = $worker->user?->fcm_token ?? null;

        if (!$fcmToken) {
            return;
        }

        $messages = [
            'active'    => ['title' => '✅ Estás activo', 'body' => 'Eres visible en el mapa. Espera solicitudes.'],
            'listening' => ['title' => '🟡 Modo escucha', 'body' => 'Solo te avisamos de ofertas cercanas.'],
            'inactive'  => ['title' => '⚫ Desconectado', 'body' => 'Ya no apareces en el mapa de clientes.'],
        ];

        $msg = $messages[$status] ?? null;
        if (!$msg) {
            return;
        }

        try {
            (new FirebaseService())->sendToDevice($fcmToken, $msg['title'], $msg['body'], [
                'type'   => 'status_change',
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[FCM] notifyWorkerStatusChange falló', ['error' => $e->getMessage()]);
        }
    }
}
