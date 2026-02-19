<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * TRAVEL REQUEST - Absorción de Necesidades en Ruta
 * 
 * El cliente no siente que cambió de aplicación. JobsHour simplemente
 * "se dio cuenta" de que alguien va hacia donde él necesita ir.
 * Interfaz transparente, match quirúrgico.
 */
class TravelRequestController extends Controller
{
    /**
     * Crear solicitud de viaje o envío
     * 
     * El cliente postula su necesidad. El sistema la absorbe y busca
     * workers que van en esa dirección.
     */
    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'request_type' => 'required|in:ride,delivery,service',
            'pickup_lat' => 'required|numeric|between:-90,90',
            'pickup_lng' => 'required|numeric|between:-180,180',
            'pickup_address' => 'required|string|max:255',
            'delivery_lat' => 'required|numeric|between:-90,90',
            'delivery_lng' => 'required|numeric|between:-180,180',
            'delivery_address' => 'required|string|max:255',
            'departure_time' => 'nullable|date|after:now',
            'passenger_count' => 'nullable|integer|min:1|max:8',
            'carga_tipo' => 'nullable|in:sobre,paquete,bulto',
            'carga_peso' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'offered_price' => 'nullable|numeric|min:0',
            'urgency' => 'nullable|in:normal,urgent',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Buscar worker dummy o crear uno temporal para el cliente
        // (necesario porque service_requests requiere worker_id)
        $dummyWorker = Worker::where('user_id', $user->id)->first();
        
        if (!$dummyWorker) {
            // Crear worker temporal para el cliente
            $dummyWorker = Worker::create([
                'user_id' => $user->id,
                'availability_status' => 'inactive',
            ]);
        }

        // Metadata elástica para casos futuros
        $metadata = [
            'is_travel_request' => true,
            'created_by_client' => true,
            'search_radius_km' => 50,
        ];

        if ($request->has('special_requirements')) {
            $metadata['special_requirements'] = $request->special_requirements;
        }

        // Crear la solicitud
        $serviceRequest = ServiceRequest::create([
            'client_id' => $user->id,
            'worker_id' => $dummyWorker->id, // Temporal, se actualizará cuando un worker acepte
            'request_type' => $request->request_type,
            'pickup_address' => $request->pickup_address,
            'delivery_address' => $request->delivery_address,
            'pickup_lat' => $request->pickup_lat,
            'pickup_lng' => $request->pickup_lng,
            'delivery_lat' => $request->delivery_lat,
            'delivery_lng' => $request->delivery_lng,
            'passenger_count' => $request->passenger_count ?? 1,
            'carga_tipo' => $request->carga_tipo,
            'carga_peso' => $request->carga_peso,
            'description' => $request->description,
            'offered_price' => $request->offered_price,
            'urgency' => $request->urgency ?? 'normal',
            'status' => 'pending',
            'request_metadata' => $metadata,
        ]);

        // Buscar matches inmediatamente
        $matches = $this->findMatches($serviceRequest);

        return response()->json([
            'status' => 'success',
            'message' => $request->request_type === 'ride' 
                ? '🚗 Buscando conductores que van en tu dirección...'
                : '📦 Buscando personas que pueden llevar tu encomienda...',
            'data' => [
                'request_id' => $serviceRequest->id,
                'request_type' => $serviceRequest->request_type,
                'matches_found' => $matches->count(),
                'matches' => $matches->take(10),
            ],
        ]);
    }

    /**
     * Buscar matches para una solicitud
     * 
     * Match quirúrgico PostGIS: encuentra workers cuya ruta pasa cerca
     * del pickup y delivery del cliente. Prioridad al recurso móvil.
     */
    public function findMatches(ServiceRequest $serviceRequest)
    {
        $pickupLat = $serviceRequest->pickup_lat;
        $pickupLng = $serviceRequest->pickup_lng;
        $deliveryLat = $serviceRequest->delivery_lat;
        $deliveryLng = $serviceRequest->delivery_lng;

        // Query quirúrgico: match de rutas activas con la necesidad
        $matches = DB::select("
            WITH request_points AS (
                SELECT 
                    ST_SetSRID(ST_MakePoint(?, ?), 4326) as pickup_point,
                    ST_SetSRID(ST_MakePoint(?, ?), 4326) as delivery_point
            ),
            active_routes AS (
                SELECT 
                    w.id as worker_id,
                    w.user_id,
                    w.active_route,
                    w.hourly_rate,
                    u.name as worker_name,
                    u.avatar as worker_avatar,
                    ST_MakeLine(
                        ST_SetSRID(ST_MakePoint(
                            (w.active_route->>'origin_lng')::float,
                            (w.active_route->>'origin_lat')::float
                        ), 4326),
                        ST_SetSRID(ST_MakePoint(
                            (w.active_route->>'destination_lng')::float,
                            (w.active_route->>'destination_lat')::float
                        ), 4326)
                    ) as route_line
                FROM workers w
                JOIN users u ON u.id = w.user_id
                WHERE 
                    w.active_route IS NOT NULL
                    AND (w.active_route->>'status') = 'active'
                    AND (w.active_route->>'departure_time')::timestamp > NOW()
                    -- Filtro de capacidad
                    AND (
                        ? = 'delivery' -- Si es delivery, solo necesita cargo_space
                        OR (
                            ? = 'ride' 
                            AND (w.active_route->>'available_seats')::int >= ?
                        )
                    )
            )
            SELECT 
                ar.worker_id,
                ar.user_id,
                ar.worker_name,
                ar.worker_avatar,
                ar.active_route,
                ar.hourly_rate,
                -- Desvío del pickup a la ruta del worker
                ST_Distance(
                    rp.pickup_point::geography,
                    ar.route_line::geography
                ) / 1000 as pickup_detour_km,
                -- Desvío del delivery a la ruta del worker
                ST_Distance(
                    rp.delivery_point::geography,
                    ar.route_line::geography
                ) / 1000 as delivery_detour_km,
                -- Desvío total
                (
                    ST_Distance(rp.pickup_point::geography, ar.route_line::geography) +
                    ST_Distance(rp.delivery_point::geography, ar.route_line::geography)
                ) / 1000 as total_detour_km,
                -- Distancia del viaje del cliente
                ST_Distance(
                    rp.pickup_point::geography,
                    rp.delivery_point::geography
                ) / 1000 as trip_distance_km
            FROM active_routes ar
            CROSS JOIN request_points rp
            WHERE
                -- Filtro quirúrgico: máximo 2km de desvío por punto (4km total)
                ST_Distance(rp.pickup_point::geography, ar.route_line::geography) < 2000
                AND ST_Distance(rp.delivery_point::geography, ar.route_line::geography) < 2000
            ORDER BY total_detour_km ASC
            LIMIT 20
        ", [
            $pickupLng, 
            $pickupLat, 
            $deliveryLng, 
            $deliveryLat,
            $serviceRequest->request_type,
            $serviceRequest->request_type,
            $serviceRequest->passenger_count ?? 1,
        ]);

        return collect($matches);
    }

    /**
     * Obtener matches para una solicitud específica
     */
    public function getMatches(Request $request, $requestId)
    {
        $serviceRequest = ServiceRequest::find($requestId);

        if (!$serviceRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'Request not found'
            ], 404);
        }

        // Verificar que el usuario sea el dueño de la solicitud
        if ($serviceRequest->client_id !== $request->user()->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 403);
        }

        $matches = $this->findMatches($serviceRequest);

        return response()->json([
            'status' => 'success',
            'data' => [
                'request' => $serviceRequest,
                'matches' => $matches,
            ],
        ]);
    }

    /**
     * Worker acepta una solicitud de viaje
     * 
     * Prioridad al recurso: el worker decide si le conviene el desvío
     */
    public function accept(Request $request, $requestId)
    {
        $serviceRequest = ServiceRequest::find($requestId);

        if (!$serviceRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'Request not found'
            ], 404);
        }

        if ($serviceRequest->status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Request already processed'
            ], 400);
        }

        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker || !$worker->active_route) {
            return response()->json([
                'status' => 'error',
                'message' => 'You need an active route to accept travel requests'
            ], 400);
        }

        // Actualizar la solicitud
        $serviceRequest->worker_id = $worker->id;
        $serviceRequest->status = 'accepted';
        $serviceRequest->accepted_at = now();
        $serviceRequest->save();

        // Actualizar metadata de la ruta activa (agregar solicitud aceptada)
        $route = $worker->active_route;
        if (!isset($route['accepted_requests'])) {
            $route['accepted_requests'] = [];
        }
        $route['accepted_requests'][] = $requestId;
        $worker->active_route = $route;
        $worker->save();

        return response()->json([
            'status' => 'success',
            'message' => '✅ Solicitud aceptada. Coordina con el cliente para la recogida.',
            'data' => [
                'request' => $serviceRequest->load('client'),
                'pickup_address' => $serviceRequest->pickup_address,
                'delivery_address' => $serviceRequest->delivery_address,
            ],
        ]);
    }

    /**
     * Worker rechaza una solicitud
     */
    public function reject(Request $request, $requestId)
    {
        $serviceRequest = ServiceRequest::find($requestId);

        if (!$serviceRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'Request not found'
            ], 404);
        }

        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json([
                'status' => 'error',
                'message' => 'Worker profile not found'
            ], 404);
        }

        // No cambiar el status a rejected, solo no aceptar
        // Otros workers pueden aceptarla

        return response()->json([
            'status' => 'success',
            'message' => 'Solicitud rechazada',
        ]);
    }

    /**
     * Tracking en tiempo real de una solicitud aceptada
     */
    public function track(Request $request, $requestId)
    {
        $serviceRequest = ServiceRequest::find($requestId);

        if (!$serviceRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'Request not found'
            ], 404);
        }

        // Verificar que el usuario sea el cliente o el worker
        $user = $request->user();
        if ($serviceRequest->client_id !== $user->id && $serviceRequest->worker->user_id !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 403);
        }

        $worker = $serviceRequest->worker;

        return response()->json([
            'status' => 'success',
            'data' => [
                'request' => $serviceRequest,
                'worker_location' => [
                    'lat' => DB::selectOne("SELECT ST_Y(location::geometry) as lat FROM workers WHERE id = ?", [$worker->id])->lat ?? null,
                    'lng' => DB::selectOne("SELECT ST_X(location::geometry) as lng FROM workers WHERE id = ?", [$worker->id])->lng ?? null,
                ],
                'worker_name' => $worker->user->name,
                'worker_avatar' => $worker->user->avatar,
                'estimated_arrival' => $worker->active_route['arrival_time'] ?? null,
            ],
        ]);
    }
}
