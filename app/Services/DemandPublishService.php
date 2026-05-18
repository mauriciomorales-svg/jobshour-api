<?php

namespace App\Services;

use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Geofence;
use Illuminate\Support\Facades\DB;

/**
 * Crea una publicación dorada (demanda en mapa). Usado por el modal web y por integraciones de tienda.
 *
 * @param  array<string,mixed>  $validated  Datos ya validados (misma forma que DemandMapController::publish)
 */
class DemandPublishService
{
    /**
     * @param  array<string,mixed>  $validated
     */
    public function publish(User $user, array $validated, ?string $storedImageRelativePath = null): ServiceRequest
    {
        if (Geofence::enabled() && ! Geofence::isInsideZone((float) $validated['lat'], (float) $validated['lng'])) {
            throw new \InvalidArgumentException('outside_zone');
        }

        $ttl = $validated['ttl_minutes'] ?? 30;

        $urgencyMap = [
            'low' => 'normal',
            'medium' => 'normal',
            'high' => 'urgent',
            'normal' => 'normal',
            'urgent' => 'urgent',
        ];
        $dbUrgency = $urgencyMap[$validated['urgency'] ?? 'medium'] ?? 'normal';

        $type = $validated['type'] ?? 'fixed_job';

        $payload = [];
        if ($type === 'ride_share') {
            $role = $validated['travel_role'] ?? 'passenger';
            $payload = [
                'travel_role' => $role,
                'seats' => $validated['seats'] ?? 1,
                'departure_time' => $validated['departure_time'] ?? null,
                'destination_name' => $validated['destination_name'] ?? $validated['delivery_address'] ?? null,
                'vehicle_type' => is_array($validated['payload'] ?? null)
                    ? ($validated['payload']['vehicle_type'] ?? null)
                    : null,
                'origin_address' => $validated['pickup_address'] ?? null,
                'destination_address' => $validated['delivery_address'] ?? null,
            ];
        } elseif ($type === 'express_errand') {
            $payload = [
                'store_name' => $validated['store_name'] ?? null,
                'items_count' => $validated['items_count'] ?? null,
                'load_type' => $validated['load_type'] ?? null,
                'requires_vehicle' => $validated['requires_vehicle'] ?? false,
            ];
        }

        if (! empty($validated['payload']) && is_array($validated['payload'])) {
            $payload = array_merge($payload, $validated['payload']);
        }

        if ($storedImageRelativePath) {
            $payload['image'] = '/storage/'.$storedImageRelativePath;
        }

        $serviceRequest = ServiceRequest::create([
            'client_id' => $user->id,
            'category_id' => $validated['category_id'],
            'type' => $type,
            'travel_role' => $validated['travel_role'] ?? null,
            'category_type' => $validated['category_type'] ?? 'fixed',
            'description' => $validated['description'],
            'offered_price' => array_key_exists('offered_price', $validated) ? $validated['offered_price'] : null,
            'urgency' => $dbUrgency,
            'status' => 'pending',
            'pin_expires_at' => now()->addMinutes($ttl),
            'payload' => ! empty($payload) ? $payload : null,
            'pickup_address' => $validated['pickup_address'] ?? null,
            'delivery_address' => $validated['delivery_address'] ?? null,
            'pickup_lat' => $validated['pickup_lat'] ?? null,
            'pickup_lng' => $validated['pickup_lng'] ?? null,
            'delivery_lat' => $validated['delivery_lat'] ?? null,
            'delivery_lng' => $validated['delivery_lng'] ?? null,
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'workers_needed' => $validated['workers_needed'] ?? 1,
            'recurrence' => $validated['recurrence'] ?? 'once',
            'recurrence_days' => ! empty($validated['recurrence_days']) ? json_encode($validated['recurrence_days']) : null,
        ]);

        $locationLat = $validated['pickup_lat'] ?? $validated['lat'];
        $locationLng = $validated['pickup_lng'] ?? $validated['lng'];

        DB::update(
            'UPDATE service_requests SET client_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
            [$locationLng, $locationLat, $serviceRequest->id]
        );

        $serviceRequest->refresh();

        ServiceRequestNotificationDispatcher::published($serviceRequest);

        return $serviceRequest;
    }
}
