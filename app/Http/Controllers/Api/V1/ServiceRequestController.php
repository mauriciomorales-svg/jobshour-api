<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ServiceRequestCreated;
use App\Events\ServiceRequestUpdated;
use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Models\Worker;
use App\Models\Message;
use Illuminate\Http\Request;

class ServiceRequestController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'worker_id' => 'required|exists:workers,id',
            'category_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string|max:500',
            'urgency' => 'nullable|in:normal,urgent',
            'offered_price' => 'nullable|numeric|min:0',
            'carga_tipo' => 'nullable|in:sobre,paquete,bulto',
            'carga_peso' => 'nullable|numeric|min:0',
            'pickup_address' => 'nullable|string|max:255',
            'delivery_address' => 'nullable|string|max:255',
            'pickup_lat' => 'nullable|numeric',
            'pickup_lng' => 'nullable|numeric',
            'delivery_lat' => 'nullable|numeric',
            'delivery_lng' => 'nullable|numeric',
        ]);

        $sr = ServiceRequest::create([
            'client_id' => $request->user()->id,
            'worker_id' => $validated['worker_id'],
            'category_id' => $validated['category_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'urgency' => $validated['urgency'] ?? 'normal',
            'offered_price' => $validated['offered_price'] ?? null,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(5),
            'carga_tipo' => $validated['carga_tipo'] ?? null,
            'carga_peso' => $validated['carga_peso'] ?? null,
            'pickup_address' => $validated['pickup_address'] ?? null,
            'delivery_address' => $validated['delivery_address'] ?? null,
            'pickup_lat' => $validated['pickup_lat'] ?? null,
            'pickup_lng' => $validated['pickup_lng'] ?? null,
            'delivery_lat' => $validated['delivery_lat'] ?? null,
            'delivery_lng' => $validated['delivery_lng'] ?? null,
        ]);

        // TEMPORALMENTE DESHABILITADO - Reverb timeout
        // broadcast(new ServiceRequestCreated($sr));

        return response()->json([
            'status' => 'success',
            'data' => $sr->load(['client:id,name,avatar', 'category:id,display_name,color']),
        ], 201);
    }

    public function respond(Request $request, ServiceRequest $serviceRequest)
    {
        $validated = $request->validate([
            'action' => 'required|in:accept,reject',
            'final_price' => 'nullable|numeric|min:0',
        ]);

        if ($serviceRequest->status !== 'pending') {
            return response()->json(['error' => 'Solicitud ya procesada'], 422);
        }

        if ($serviceRequest->isExpired()) {
            $serviceRequest->update(['status' => 'cancelled']);
            return response()->json(['error' => 'Solicitud expirada'], 422);
        }

        if ($validated['action'] === 'accept') {
            // P0-2: Validación de estado busy
            $worker = Worker::find($serviceRequest->worker_id);
            
            if ($worker && $worker->availability_status === 'intermediate') {
                $activeJobs = ServiceRequest::where('worker_id', $worker->id)
                    ->whereIn('status', ['accepted', 'in_progress'])
                    ->count();
                
                if ($activeJobs > 0) {
                    return response()->json([
                        'error' => 'Ya tienes un servicio en progreso',
                        'code' => 'WORKER_BUSY'
                    ], 403);
                }
            }
            $serviceRequest->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'started_at' => now(),
                'final_price' => $validated['final_price'] ?? $serviceRequest->offered_price,
            ]);

            // Cambiar worker a estado busy automáticamente
            $worker = Worker::find($serviceRequest->worker_id);
            if ($worker && $worker->availability_status === 'active') {
                $worker->update(['availability_status' => 'intermediate']);
            }

            // Mensaje de sistema automático
            Message::create([
                'service_request_id' => $serviceRequest->id,
                'sender_id' => $serviceRequest->worker->user_id,
                'body' => 'Solicitud aceptada. ¡Puedes escribirme!',
                'type' => 'system',
            ]);
        } else {
            $serviceRequest->update(['status' => 'rejected']);
        }

        broadcast(new ServiceRequestUpdated($serviceRequest));

        return response()->json([
            'status' => 'success',
            'data' => $serviceRequest->fresh(),
        ]);
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
        if (!in_array($serviceRequest->status, ['accepted', 'in_progress'])) {
            return response()->json(['error' => 'Solo se pueden completar solicitudes aceptadas'], 422);
        }

        // Verificar que no haya ajuste de precio pendiente
        if ($serviceRequest->adjusted_price && !$serviceRequest->client_approved_adjustment) {
            return response()->json(['error' => 'Hay un ajuste de precio pendiente de aprobación'], 422);
        }

        $validated = $request->validate([
            'delivery_photo' => 'nullable|string',
            'delivery_signature' => 'nullable|string',
        ]);

        $serviceRequest->update([
            'status' => 'completed',
            'completed_at' => now(),
            'delivery_photo' => $validated['delivery_photo'] ?? null,
            'delivery_signature' => $validated['delivery_signature'] ?? null,
        ]);

        // Restaurar disponibilidad del worker
        $worker = Worker::find($serviceRequest->worker_id);
        if ($worker && $worker->availability_status === 'intermediate') {
            $worker->update(['availability_status' => 'active']);
        }

        broadcast(new ServiceRequestUpdated($serviceRequest));

        return response()->json([
            'status' => 'success',
            'data' => $serviceRequest->fresh(),
        ]);
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

        // Solo se puede cancelar si está pending o accepted
        if (!in_array($serviceRequest->status, ['pending', 'accepted'])) {
            return response()->json(['error' => 'No se puede cancelar en este estado'], 422);
        }

        $serviceRequest->update(['status' => 'cancelled']);

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

        broadcast(new ServiceRequestUpdated($serviceRequest));

        return response()->json([
            'status' => 'success',
            'message' => 'Solicitud cancelada exitosamente',
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

        return response()->json([
            'status' => 'success',
            'message' => 'Actividad registrada',
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
}
