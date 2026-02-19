<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ServiceRequestCreated;
use App\Events\ServiceRequestUpdated;
use App\Events\PinDiedEvent;
use App\Events\LocationUpdated;
use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Models\Worker;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceRequestController extends Controller
{
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'worker_id' => 'required|exists:workers,id',
                'category_id' => 'nullable|exists:categories,id',
                'description' => 'nullable|string|max:500',
                'urgency' => 'nullable|in:normal,urgent',
                'offered_price' => 'nullable|numeric|min:0',
                'type' => 'nullable|in:fixed_job,ride_share,express_errand',
                'category_type' => 'nullable|in:fixed,travel,errand',
                'payload' => 'nullable|array',
                'carga_tipo' => 'nullable|in:sobre,paquete,bulto',
                'carga_peso' => 'nullable|numeric|min:0',
                'pickup_address' => 'nullable|string|max:255',
                'delivery_address' => 'nullable|string|max:255',
                'pickup_lat' => 'nullable|numeric|between:-90,90',
                'pickup_lng' => 'nullable|numeric|between:-180,180',
                'delivery_lat' => 'nullable|numeric|between:-90,90',
                'delivery_lng' => 'nullable|numeric|between:-180,180',
                // Campos específicos para ride_share
                'departure_time' => 'nullable|date|after:now',
                'seats' => 'nullable|integer|min:1|max:8',
                'destination_name' => 'nullable|string|max:255',
                'vehicle_type' => 'nullable|string|max:50',
                // Campos específicos para express_errand
                'store_name' => 'nullable|string|max:255',
                'items_count' => 'nullable|integer|min:1',
                'load_type' => 'nullable|in:light,medium,heavy',
                'requires_vehicle' => 'nullable|boolean',
            ]);

            // Validar que el worker existe y está disponible
            $worker = Worker::find($validated['worker_id']);
            if (!$worker) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Trabajador no encontrado'
                ], 404);
            }

            // Validar que el worker no sea el mismo usuario (no puede solicitarse a sí mismo)
            if ($worker->user_id === $request->user()->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No puedes solicitarte servicios a ti mismo'
                ], 422);
            }

            // Validar que el worker esté disponible (no inactive)
            if ($worker->availability_status === 'inactive') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Este trabajador no está disponible en este momento'
                ], 422);
            }

            // Construir payload según el tipo de solicitud
            $payload = [];
            if ($validated['type'] === 'ride_share') {
                $payload = [
                    'seats' => $validated['seats'] ?? 1,
                    'departure_time' => $validated['departure_time'] ?? null,
                    'destination_name' => $validated['destination_name'] ?? null,
                    'vehicle_type' => $validated['vehicle_type'] ?? null,
                ];
            } elseif ($validated['type'] === 'express_errand') {
                $payload = [
                    'store_name' => $validated['store_name'] ?? null,
                    'items_count' => $validated['items_count'] ?? null,
                    'load_type' => $validated['load_type'] ?? null,
                    'requires_vehicle' => $validated['requires_vehicle'] ?? false,
                ];
            }
            
            // Si viene payload desde el frontend, mergearlo
            if (!empty($validated['payload'])) {
                $payload = array_merge($payload, $validated['payload']);
            }

            $sr = null;
            DB::transaction(function() use ($request, $validated, $payload, &$sr) {
                $sr = ServiceRequest::create([
                    'client_id' => $request->user()->id,
                    'worker_id' => $validated['worker_id'],
                    'category_id' => $validated['category_id'] ?? null,
                    'type' => $validated['type'] ?? 'fixed_job',
                    'category_type' => $validated['category_type'] ?? 'fixed',
                    'description' => $validated['description'] ?? null,
                    'urgency' => $validated['urgency'] ?? 'normal',
                    'offered_price' => $validated['offered_price'] ?? null,
                    'status' => 'pending',
                    'expires_at' => now()->addMinutes(5),
                    'payload' => !empty($payload) ? $payload : null,
                    'carga_tipo' => $validated['carga_tipo'] ?? null,
                    'carga_peso' => $validated['carga_peso'] ?? null,
                    'pickup_address' => $validated['pickup_address'] ?? null,
                    'delivery_address' => $validated['delivery_address'] ?? null,
                    'pickup_lat' => $validated['pickup_lat'] ?? null,
                    'pickup_lng' => $validated['pickup_lng'] ?? null,
                    'delivery_lat' => $validated['delivery_lat'] ?? null,
                    'delivery_lng' => $validated['delivery_lng'] ?? null,
                ]);
            });

            if (!$sr) {
                throw new \Exception('Error al crear solicitud');
            }

            // Intentar broadcast pero no fallar si falla
            try {
                $event = new ServiceRequestCreated($sr);
                broadcast($event);
                $event->handle();
            } catch (\Exception $e) {
                \Log::warning('ServiceRequestController::store - Error en broadcast', [
                    'error' => $e->getMessage(),
                    'request_id' => $sr->id
                ]);
                // Continuar aunque falle el broadcast
            }

            return response()->json([
                'status' => 'success',
                'data' => $sr->load(['client:id,name,avatar', 'category:id,display_name,color']),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('ServiceRequestController::store - Error crítico', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al crear solicitud. Por favor intenta nuevamente.'
            ], 500);
        }
    }

    public function respond(Request $request, ServiceRequest $serviceRequest)
    {
        try {
            $validated = $request->validate([
                'action' => 'required|in:accept,reject',
                'final_price' => 'nullable|numeric|min:0',
            ]);

            // Validar que el usuario sea el worker
            $user = $request->user();
            if (!$serviceRequest->worker || $serviceRequest->worker->user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No autorizado. Solo el trabajador asignado puede responder.'
                ], 403);
            }

            if ($serviceRequest->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Esta solicitud ya fue procesada'
                ], 422);
            }

            if ($serviceRequest->isExpired()) {
                $serviceRequest->update(['status' => 'cancelled']);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Esta solicitud ha expirado'
                ], 422);
            }

            $worker = Worker::find($serviceRequest->worker_id);
            if (!$worker) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Trabajador no encontrado'
                ], 404);
            }

            if ($validated['action'] === 'accept') {
                // Validación de estado busy
                if ($worker->availability_status === 'intermediate') {
                    $activeJobs = ServiceRequest::where('worker_id', $worker->id)
                        ->whereIn('status', ['accepted', 'in_progress'])
                        ->count();
                    
                    if ($activeJobs > 0) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Ya tienes un servicio en progreso. Completa o cancela el servicio actual antes de aceptar otro.',
                            'code' => 'WORKER_BUSY'
                        ], 403);
                    }
                }

                DB::transaction(function() use ($serviceRequest, $validated, $worker) {
                    // ⚡ MUERTE INSTANTÁNEA DEL PIN
                    $serviceRequest->update([
                        'status' => 'accepted',
                        'accepted_at' => now(),
                        'started_at' => now(),
                        'final_price' => $validated['final_price'] ?? $serviceRequest->offered_price,
                        'pin_expires_at' => now(), // Pin muere al instante
                    ]);

                    // Cambiar worker a estado busy automáticamente
                    if ($worker && $worker->availability_status === 'active') {
                        $worker->update(['availability_status' => 'intermediate']);
                    }

                    // Mensaje de sistema automático
                    try {
                        Message::create([
                            'service_request_id' => $serviceRequest->id,
                            'sender_id' => $serviceRequest->worker->user_id,
                            'body' => 'Solicitud aceptada. ¡Puedes escribirme!',
                            'type' => 'system',
                        ]);
                    } catch (\Exception $e) {
                        \Log::warning('ServiceRequestController::respond - Error creando mensaje', [
                            'error' => $e->getMessage(),
                            'request_id' => $serviceRequest->id
                        ]);
                        // Continuar aunque falle el mensaje
                    }

                    // Broadcast muerte del pin (WebSocket) - no fallar si falla
                    try {
                        broadcast(new PinDiedEvent($serviceRequest->id));
                    } catch (\Exception $e) {
                        \Log::warning('ServiceRequestController::respond - Error en broadcast PinDied', [
                            'error' => $e->getMessage()
                        ]);
                    }
                });
            } else {
                $serviceRequest->update(['status' => 'rejected']);
            }

            // Broadcast actualización - no fallar si falla
            try {
                $event = new ServiceRequestUpdated($serviceRequest->fresh());
                broadcast($event);
                $event->handle();
            } catch (\Exception $e) {
                \Log::warning('ServiceRequestController::respond - Error en broadcast Update', [
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => $validated['action'] === 'accept' ? 'Solicitud aceptada exitosamente' : 'Solicitud rechazada',
                'data' => $serviceRequest->fresh()->load(['client:id,name,avatar', 'worker.user:id,name,avatar', 'category:id,display_name,color']),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('ServiceRequestController::respond - Error crítico', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $serviceRequest->id ?? null,
                'user_id' => $request->user()?->id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al procesar respuesta. Por favor intenta nuevamente.'
            ], 500);
        }
    }

    public function adjustPrice(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();

        // Validar que el usuario sea el worker
        if (!$serviceRequest->worker || $serviceRequest->worker->user_id !== $user->id) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        // Solo se puede ajustar si está accepted o in_progress
        if (!in_array($serviceRequest->status, ['accepted', 'in_progress'])) {
            return response()->json(['error' => 'No se puede ajustar precio en este estado'], 422);
        }

        $validated = $request->validate([
            'adjusted_price' => 'required|numeric|min:0',
            'reason' => 'required|string|max:500',
        ]);

        $serviceRequest->update([
            'adjusted_price' => $validated['adjusted_price'],
            'price_adjustment_reason' => $validated['reason'],
            'price_adjusted_at' => now(),
            'client_approved_adjustment' => false,
        ]);

        // Notificar al cliente (aquí iría push notification)
        
        return response()->json([
            'status' => 'success',
            'message' => 'Ajuste de precio propuesto. Esperando aprobación del cliente.',
            'adjusted_price' => $validated['adjusted_price'],
        ]);
    }

    public function approveAdjustment(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();

        // Validar que el usuario sea el cliente
        if ($serviceRequest->client_id !== $user->id) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        if (!$serviceRequest->adjusted_price) {
            return response()->json(['error' => 'No hay ajuste de precio pendiente'], 422);
        }

        $serviceRequest->update([
            'client_approved_adjustment' => true,
            'final_price' => $serviceRequest->adjusted_price,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Ajuste de precio aprobado.',
        ]);
    }

    public function complete(Request $request, ServiceRequest $serviceRequest)
    {
        try {
            $user = $request->user();
            
            // Validar que el usuario sea el worker
            if (!$serviceRequest->worker || $serviceRequest->worker->user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solo el trabajador asignado puede completar este servicio'
                ], 403);
            }

            if (!in_array($serviceRequest->status, ['accepted', 'in_progress'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solo se pueden completar solicitudes aceptadas o en progreso'
                ], 422);
            }

            // Verificar que no haya ajuste de precio pendiente
            if ($serviceRequest->adjusted_price && !$serviceRequest->client_approved_adjustment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Hay un ajuste de precio pendiente de aprobación del cliente'
                ], 422);
            }

            $validated = $request->validate([
                'delivery_photo' => 'nullable|string|max:5000',
                'delivery_signature' => 'nullable|string|max:5000',
            ]);

            DB::transaction(function() use ($serviceRequest, $validated) {
                $serviceRequest->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'delivery_photo' => $validated['delivery_photo'] ?? null,
                    'delivery_signature' => $validated['delivery_signature'] ?? null,
                ]);

                // Restaurar disponibilidad del worker solo si no tiene otros trabajos activos
                $worker = Worker::find($serviceRequest->worker_id);
                if ($worker && $worker->availability_status === 'intermediate') {
                    $activeJobs = ServiceRequest::where('worker_id', $worker->id)
                        ->whereIn('status', ['accepted', 'in_progress'])
                        ->where('id', '!=', $serviceRequest->id)
                        ->count();

                    if ($activeJobs === 0) {
                        $worker->update(['availability_status' => 'active']);
                    }
                }
            });

            // Broadcast actualización - no fallar si falla
            try {
                $event = new ServiceRequestUpdated($serviceRequest->fresh());
                broadcast($event);
                $event->handle();
            } catch (\Exception $e) {
                \Log::warning('ServiceRequestController::complete - Error en broadcast', [
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Servicio completado exitosamente. El cliente puede calificar tu trabajo.',
                'data' => $serviceRequest->fresh()->load(['client:id,name,avatar', 'worker.user:id,name,avatar', 'category:id,display_name,color']),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('ServiceRequestController::complete - Error crítico', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $serviceRequest->id ?? null,
                'user_id' => $request->user()?->id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al completar servicio. Por favor intenta nuevamente.'
            ], 500);
        }
    }

    public function cancel(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();

        // Validar que el usuario sea el cliente o el worker
        $isClient = $serviceRequest->client_id === $user->id;
        $isWorker = $serviceRequest->worker && $serviceRequest->worker->user_id === $user->id;

        if (!$isClient && !$isWorker) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        // Solo se puede cancelar si está pending, accepted o in_progress
        if (!in_array($serviceRequest->status, ['pending', 'accepted', 'in_progress'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede cancelar en este estado'
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        // Calcular penalización según quién cancela y cuándo
        $penaltyAmount = 0;
        $penaltyApplied = false;

        if ($isClient) {
            // Cliente cancela: penalización si ya fue aceptada o está en progreso
            if (in_array($serviceRequest->status, ['accepted', 'in_progress'])) {
                // Penalización del 10% del precio ofrecido o final
                $baseAmount = $serviceRequest->final_price ?? $serviceRequest->offered_price ?? 0;
                $penaltyAmount = $baseAmount * 0.10;
                $penaltyApplied = true;
            }
        } else {
            // Worker cancela: penalización más alta si ya fue aceptada
            if (in_array($serviceRequest->status, ['accepted', 'in_progress'])) {
                // Penalización del 20% del precio ofrecido o final
                $baseAmount = $serviceRequest->final_price ?? $serviceRequest->offered_price ?? 0;
                $penaltyAmount = $baseAmount * 0.20;
                $penaltyApplied = true;
            }
        }

        $serviceRequest->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancellation_reason' => $validated['reason'] ?? null,
            'penalty_amount' => $penaltyAmount,
            'penalty_applied' => $penaltyApplied,
        ]);

        // CRÍTICO #1: Auto-restore de disponibilidad
        $worker = Worker::find($serviceRequest->worker_id);
        if ($worker && $worker->availability_status === 'intermediate') {
            // Verificar que no tenga otros trabajos activos
            $activeJobs = ServiceRequest::where('worker_id', $worker->id)
                ->whereIn('status', ['accepted', 'in_progress'])
                ->where('id', '!=', $serviceRequest->id)
                ->count();

            if ($activeJobs === 0) {
                $worker->update(['availability_status' => 'active']);
            }
        }

        $event = new ServiceRequestUpdated($serviceRequest);
        broadcast($event);
        $event->handle();

        return response()->json([
            'status' => 'success',
            'message' => 'Solicitud cancelada exitosamente',
            'penalty_applied' => $penaltyApplied,
            'penalty_amount' => $penaltyAmount,
            'worker_restored' => $worker && $activeJobs === 0,
        ]);
    }

    public function pause(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();

        // Validar que el usuario sea el worker
        if (!$serviceRequest->worker || $serviceRequest->worker->user_id !== $user->id) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        // Solo se puede pausar si está accepted o in_progress
        if (!in_array($serviceRequest->status, ['accepted', 'in_progress'])) {
            return response()->json(['error' => 'No se puede pausar en este estado'], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $serviceRequest->update([
            'status' => 'paused',
            'paused_at' => now(),
            'pause_reason' => $validated['reason'],
        ]);

        // P0-3: Liberar availability_status para que pueda tomar recados
        $worker = Worker::find($serviceRequest->worker_id);
        if ($worker && $worker->availability_status === 'intermediate') {
            $worker->update(['availability_status' => 'active']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Trabajo pausado. Puedes tomar otros servicios mientras tanto.',
        ]);
    }

    public function resume(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();

        if (!$serviceRequest->worker || $serviceRequest->worker->user_id !== $user->id) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        if ($serviceRequest->status !== 'paused') {
            return response()->json(['error' => 'El trabajo no está pausado'], 422);
        }

        $serviceRequest->update([
            'status' => 'in_progress',
            'paused_at' => null,
        ]);

        // Volver a estado busy
        $worker = Worker::find($serviceRequest->worker_id);
        if ($worker && $worker->availability_status === 'active') {
            $worker->update(['availability_status' => 'intermediate']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Trabajo reanudado.',
        ]);
    }

    public function updateActivity(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();

        // Validar que el usuario sea el worker
        if (!$serviceRequest->worker || $serviceRequest->worker->user_id !== $user->id) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        // Solo actualizar si está accepted o in_progress
        if (!in_array($serviceRequest->status, ['accepted', 'in_progress'])) {
            return response()->json(['error' => 'El trabajo no está activo'], 422);
        }

        $validated = $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        $serviceRequest->update([
            'last_activity_at' => now(),
            'last_known_lat' => $validated['lat'],
            'last_known_lng' => $validated['lng'],
        ]);

        // Broadcast actualización de ubicación via WebSocket
        try {
            broadcast(new LocationUpdated(
                $serviceRequest->id,
                $validated['lat'],
                $validated['lng'],
                $request->input('accuracy')
            ));
        } catch (\Exception $e) {
            \Log::warning('ServiceRequestController::updateActivity - Error en broadcast LocationUpdated', [
                'error' => $e->getMessage(),
                'request_id' => $serviceRequest->id
            ]);
            // Continuar aunque falle el broadcast
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Actividad registrada',
        ]);
    }

    public function show(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();

        // Verificar que el usuario tenga acceso (sea cliente o worker)
        $hasAccess = $serviceRequest->client_id === $user->id ||
                     ($serviceRequest->worker && $serviceRequest->worker->user_id === $user->id);

        if (!$hasAccess) {
            return response()->json([
                'status' => 'error',
                'message' => 'No autorizado',
            ], 403);
        }

        $serviceRequest->load([
            'client:id,name,avatar',
            'worker.user:id,name,avatar',
            'category:id,display_name,color,slug',
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $serviceRequest->id,
                'status' => $serviceRequest->status,
                'description' => $serviceRequest->description,
                'urgency' => $serviceRequest->urgency,
                'offered_price' => $serviceRequest->offered_price,
                'final_price' => $serviceRequest->final_price,
                'created_at' => $serviceRequest->created_at,
                'accepted_at' => $serviceRequest->accepted_at,
                'completed_at' => $serviceRequest->completed_at,
                'client' => $serviceRequest->client ? [
                    'id' => $serviceRequest->client->id,
                    'name' => $serviceRequest->client->name,
                    'avatar' => $serviceRequest->client->avatar,
                ] : null,
                'worker' => $serviceRequest->worker ? [
                    'id' => $serviceRequest->worker->id,
                    'name' => $serviceRequest->worker->user->name ?? 'Sin nombre',
                    'avatar' => $serviceRequest->worker->user->avatar ?? null,
                ] : null,
                'category' => $serviceRequest->category ? [
                    'id' => $serviceRequest->category->id,
                    'name' => $serviceRequest->category->display_name ?? $serviceRequest->category->name,
                    'color' => $serviceRequest->category->color,
                    'slug' => $serviceRequest->category->slug,
                ] : null,
                'pickup_address' => $serviceRequest->pickup_address,
                'delivery_address' => $serviceRequest->delivery_address,
                'pickup_lat' => $serviceRequest->pickup_lat,
                'pickup_lng' => $serviceRequest->pickup_lng,
                'delivery_lat' => $serviceRequest->delivery_lat,
                'delivery_lng' => $serviceRequest->delivery_lng,
                'last_known_lat' => $serviceRequest->last_known_lat,
                'last_known_lng' => $serviceRequest->last_known_lng,
                'last_activity_at' => $serviceRequest->last_activity_at,
                'type' => $serviceRequest->type,
                'category_type' => $serviceRequest->category_type,
                'payload' => $serviceRequest->payload,
            ],
        ]);
    }

    public function myRequests(Request $request)
    {
        $userId = $request->user()->id;

        $requests = ServiceRequest::where('client_id', $userId)
            ->orWhereHas('worker', fn($q) => $q->where('user_id', $userId))
            ->with(['client:id,name,avatar', 'worker.user:id,name,avatar', 'category:id,display_name,color'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $requests,
        ]);
    }

    public function uploadDeliveryPhoto(Request $request, ServiceRequest $serviceRequest)
    {
        try {
            $user = $request->user();
            $isWorker = $serviceRequest->worker && $serviceRequest->worker->user_id === $user->id;

            // Solo el worker puede subir foto de entrega
            if (!$isWorker) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solo el trabajador puede subir fotos de entrega'
                ], 403);
            }

            // Validar que el servicio esté en progreso o completado
            if (!in_array($serviceRequest->status, ['in_progress', 'completed'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solo se pueden subir fotos para servicios en progreso o completados'
                ], 422);
            }

            $validated = $request->validate([
                'photo' => 'required|image|max:5120', // 5MB max
                'type' => 'nullable|in:delivery,completion',
            ]);

            // Guardar foto
            $photoPath = $request->file('photo')->store('delivery_photos', 'public');
            $photoUrl = asset('storage/' . $photoPath);

            // Actualizar service request
            $serviceRequest->update([
                'delivery_photo' => $photoUrl,
            ]);

            // Si está en progreso y se sube foto, marcar como completado automáticamente
            if ($serviceRequest->status === 'in_progress' && ($validated['type'] ?? 'delivery') === 'completion') {
                $serviceRequest->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Foto subida exitosamente',
                'data' => [
                    'photo_url' => $photoUrl,
                    'status' => $serviceRequest->status,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('ServiceRequestController::uploadDeliveryPhoto - Error crítico', [
                'error' => $e->getMessage(),
                'request_id' => $serviceRequest->id ?? null,
                'user_id' => $request->user()?->id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al subir la foto. Por favor intenta nuevamente.'
            ], 500);
        }
    }
}
