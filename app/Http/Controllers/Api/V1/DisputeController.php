<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceDispute;
use App\Models\ServiceRequest;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DisputeController extends Controller
{
    public function reportIncident(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();

        $isClient = (int) $serviceRequest->client_id === (int) $user->id;
        $isWorker = $serviceRequest->worker
            && (int) $serviceRequest->worker->user_id === (int) $user->id;

        if (!$isClient && !$isWorker) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $existing = ServiceDispute::where('service_request_id', $serviceRequest->id)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();
        if ($existing) {
            return response()->json([
                'error' => 'Ya existe un reporte abierto para esta solicitud.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|in:no_show,wrong_description,wrong_address,material_missing,other',
            'description' => 'required|string|max:1000',
            'evidence_photos' => 'nullable|array',
            'evidence_photos.*' => 'string',
            'evidence' => 'nullable|image|max:5120',
            'worker_lat' => 'nullable|numeric',
            'worker_lng' => 'nullable|numeric',
        ]);

        $evidencePhotos = $validated['evidence_photos'] ?? [];
        if ($request->hasFile('evidence')) {
            $path = $request->file('evidence')->store('dispute-evidence', 'public');
            $evidencePhotos[] = Storage::disk('public')->url($path);
        }

        $compensationAmount = null;
        $autoApproved = false;

        // Compensación automática solo cuando reporta el trabajador (no_show + proximidad).
        if ($isWorker && $validated['reason'] === 'no_show') {
            $isNearDestination = $this->verifyProximity(
                $validated['worker_lat'] ?? null,
                $validated['worker_lng'] ?? null,
                $serviceRequest->delivery_lat,
                $serviceRequest->delivery_lng
            );

            if ($isNearDestination) {
                $base = $serviceRequest->final_price ?? $serviceRequest->offered_price ?? 0;
                $compensationAmount = $base * 0.30;
                $autoApproved = true;
            }
        }

        $dispute = ServiceDispute::create([
            'service_request_id' => $serviceRequest->id,
            'reported_by' => $user->id,
            'reason' => $validated['reason'],
            'description' => $validated['description'],
            'evidence_photos' => count($evidencePhotos) > 0 ? $evidencePhotos : null,
            'worker_lat' => $validated['worker_lat'] ?? null,
            'worker_lng' => $validated['worker_lng'] ?? null,
            'compensation_amount' => $compensationAmount,
            'auto_approved' => $autoApproved,
            'status' => $autoApproved ? 'approved' : 'pending',
            'resolved_at' => $autoApproved ? now() : null,
        ]);

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
                ? 'Incidente registrado. Compensación aplicada según las reglas del servicio.'
                : 'Reporte enviado. Te contactaremos en 24–48 h hábiles si hace falta.',
        ]);
    }

    private function verifyProximity($workerLat, $workerLng, $destLat, $destLng)
    {
        if (!$workerLat || !$workerLng || !$destLat || !$destLng) {
            return false;
        }

        $earthRadius = 6371000;
        $dLat = deg2rad($destLat - $workerLat);
        $dLng = deg2rad($destLng - $workerLng);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($workerLat)) * cos(deg2rad($destLat)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earthRadius * $c;

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
