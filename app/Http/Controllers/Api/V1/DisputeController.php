<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceDispute;
use App\Models\ServiceRequest;
use App\Models\Worker;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    public function reportIncident(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();
        
        // Validar que el usuario sea el worker del servicio
        if (!$serviceRequest->worker || $serviceRequest->worker->user_id !== $user->id) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'reason' => 'required|in:no_show,wrong_description,wrong_address,material_missing,other',
            'description' => 'required|string|max:1000',
            'evidence_photos' => 'nullable|array',
            'evidence_photos.*' => 'string',
            'worker_lat' => 'nullable|numeric',
            'worker_lng' => 'nullable|numeric',
        ]);

        // Calcular compensación automática para no_show
        $compensationAmount = null;
        $autoApproved = false;

        if ($validated['reason'] === 'no_show') {
            // Verificar si el worker está cerca del destino (radio de 100m)
            $isNearDestination = $this->verifyProximity(
                $validated['worker_lat'] ?? null,
                $validated['worker_lng'] ?? null,
                $serviceRequest->delivery_lat,
                $serviceRequest->delivery_lng
            );

            if ($isNearDestination) {
                // Compensación automática del 30%
                $compensationAmount = $serviceRequest->final_price * 0.30;
                $autoApproved = true;
            }
        }

        $dispute = ServiceDispute::create([
            'service_request_id' => $serviceRequest->id,
            'reported_by' => $user->id,
            'reason' => $validated['reason'],
            'description' => $validated['description'],
            'evidence_photos' => $validated['evidence_photos'] ?? null,
            'worker_lat' => $validated['worker_lat'] ?? null,
            'worker_lng' => $validated['worker_lng'] ?? null,
            'compensation_amount' => $compensationAmount,
            'auto_approved' => $autoApproved,
            'status' => $autoApproved ? 'approved' : 'pending',
            'resolved_at' => $autoApproved ? now() : null,
        ]);

        // Si fue auto-aprobado, cancelar el servicio y restaurar disponibilidad
        if ($autoApproved) {
            $serviceRequest->update(['status' => 'cancelled']);
            
            $worker = Worker::find($serviceRequest->worker_id);
            if ($worker && $worker->availability_status === 'intermediate') {
                $worker->update(['availability_status' => 'active']);
            }
        }

        return response()->json([
            'status' => 'success',
            'dispute' => $dispute,
            'message' => $autoApproved 
                ? "Incidente aprobado automáticamente. Compensación de $" . number_format($compensationAmount, 0, ',', '.') 
                : 'Incidente reportado. Será revisado por el equipo de soporte.',
        ]);
    }

    private function verifyProximity($workerLat, $workerLng, $destLat, $destLng)
    {
        if (!$workerLat || !$workerLng || !$destLat || !$destLng) {
            return false;
        }

        // Fórmula de Haversine para calcular distancia
        $earthRadius = 6371000; // metros
        $dLat = deg2rad($destLat - $workerLat);
        $dLng = deg2rad($destLng - $workerLng);
        
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($workerLat)) * cos(deg2rad($destLat)) *
             sin($dLng/2) * sin($dLng/2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distance = $earthRadius * $c;

        // Retornar true si está dentro de 100 metros
        return $distance <= 100;
    }

    public function myDisputes(Request $request)
    {
        $user = $request->user();

        $disputes = ServiceDispute::where('reported_by', $user->id)
            ->with(['serviceRequest.worker.user', 'serviceRequest.category'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'disputes' => $disputes,
        ]);
    }
}
