<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * MODO VIAJE - Prioridad al Recurso Móvil
 * 
 * El worker que tiene el vehículo tiene el control. Él decide cuándo activar
 * su ruta y el sistema hace el match proactivo de necesidades que le quedan
 * "de camino". No es un módulo de transporte cerrado, es absorción dinámica.
 */
class TravelModeController extends Controller
{
    /**
     * Activar Modo Viaje
     * 
     * El worker publica su intención de movimiento. El sistema lo convierte
     * en un "nodo móvil" que puede absorber necesidades en ruta.
     */
    public function activate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'origin_lat' => 'required|numeric|between:-90,90',
            'origin_lng' => 'required|numeric|between:-180,180',
            'origin_address' => 'required|string|max:255',
            'destination_lat' => 'required|numeric|between:-90,90',
            'destination_lng' => 'required|numeric|between:-180,180',
            'destination_address' => 'required|string|max:255',
            'departure_time' => 'required|date|after:now',
            'available_seats' => 'nullable|integer|min:0|max:8',
            'cargo_space' => 'nullable|in:sobre,paquete,bulto',
            'route_type' => 'nullable|in:personal,comercial,mixto',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json([
                'status' => 'error',
                'message' => 'Worker profile not found'
            ], 404);
        }

        // Calcular tiempo estimado de llegada (30 km/h promedio en zona rural)
        $distance = $this->calculateDistance(
            $request->origin_lat,
            $request->origin_lng,
            $request->destination_lat,
            $request->destination_lng
        );
        
        $estimatedDurationMinutes = ($distance / 30) * 60; // 30 km/h
        $arrivalTime = now()->parse($request->departure_time)->addMinutes($estimatedDurationMinutes);

        // Estructura elástica del active_route
        $activeRoute = [
            'status' => 'active',
            'origin' => [
                'lat' => (float) $request->origin_lat,
                'lng' => (float) $request->origin_lng,
                'address' => $request->origin_address,
            ],
            'destination' => [
                'lat' => (float) $request->destination_lat,
                'lng' => (float) $request->destination_lng,
                'address' => $request->destination_address,
            ],
            'departure_time' => $request->departure_time,
            'arrival_time' => $arrivalTime->toISOString(),
            'available_seats' => $request->available_seats ?? 0,
            'cargo_space' => $request->cargo_space,
            'route_type' => $request->route_type ?? 'personal',
            'distance_km' => round($distance, 1),
            'activated_at' => now()->toISOString(),
        ];

        $worker->active_route = $activeRoute;
        $worker->save();

        // Buscar matches proactivos (necesidades que le quedan de camino)
        $matches = $this->findProactiveMatches($worker);

        return response()->json([
            'status' => 'success',
            'message' => '🚗 Modo Viaje activado. El sistema buscará necesidades en tu ruta.',
            'data' => [
                'active_route' => $activeRoute,
                'potential_matches' => $matches->count(),
                'matches' => $matches->take(5), // Top 5 matches
            ],
        ]);
    }

    /**
     * Desactivar Modo Viaje
     */
    public function deactivate(Request $request)
    {
        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker || !$worker->active_route) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active route found'
            ], 404);
        }

        // Marcar ruta como completada en lugar de eliminarla (histórico)
        $route = $worker->active_route;
        $route['status'] = 'completed';
        $route['completed_at'] = now()->toISOString();
        
        $worker->active_route = null; // Limpiar ruta activa
        $worker->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Modo Viaje desactivado',
        ]);
    }

    /**
     * Obtener rutas activas cercanas (para clientes)
     * 
     * Muestra workers que están en tránsito y pueden absorber necesidades
     */
    public function getActiveRoutes(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'max_radius_km' => 'nullable|numeric|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $lat = $request->lat;
        $lng = $request->lng;
        $maxRadius = $request->max_radius_km ?? 50; // Default 50km para rutas

        // Query quirúrgico: encontrar workers con rutas activas cercanas
        $activeRoutes = DB::select("
            SELECT 
                w.id as worker_id,
                u.name as worker_name,
                u.avatar,
                w.active_route,
                w.hourly_rate,
                -- Distancia del punto del usuario a la línea de ruta
                ST_Distance(
                    ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                    ST_MakeLine(
                        ST_SetSRID(ST_MakePoint(
                            (w.active_route->>'origin_lng')::float,
                            (w.active_route->>'origin_lat')::float
                        ), 4326),
                        ST_SetSRID(ST_MakePoint(
                            (w.active_route->>'destination_lng')::float,
                            (w.active_route->>'destination_lat')::float
                        ), 4326)
                    )::geography
                ) / 1000 as distance_to_route_km
            FROM workers w
            JOIN users u ON u.id = w.user_id
            WHERE 
                w.active_route IS NOT NULL
                AND (w.active_route->>'status') = 'active'
                AND (w.active_route->>'departure_time')::timestamp > NOW()
                -- Filtro: ruta pasa cerca del usuario (dentro del radio)
                AND ST_DWithin(
                    ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                    ST_MakeLine(
                        ST_SetSRID(ST_MakePoint(
                            (w.active_route->>'origin_lng')::float,
                            (w.active_route->>'origin_lat')::float
                        ), 4326),
                        ST_SetSRID(ST_MakePoint(
                            (w.active_route->>'destination_lng')::float,
                            (w.active_route->>'destination_lat')::float
                        ), 4326)
                    )::geography,
                    ? * 1000
                )
            ORDER BY distance_to_route_km ASC
            LIMIT 20
        ", [$lng, $lat, $lng, $lat, $maxRadius]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'active_routes' => $activeRoutes,
                'search_center' => ['lat' => $lat, 'lng' => $lng],
                'radius_km' => $maxRadius,
            ],
        ]);
    }

    /**
     * Buscar matches proactivos
     * 
     * Encuentra necesidades (ride/delivery) que le quedan de camino al worker
     */
    private function findProactiveMatches(Worker $worker)
    {
        if (!$worker->active_route) {
            return collect();
        }

        $route = $worker->active_route;
        $originLng = $route['origin']['lng'];
        $originLat = $route['origin']['lat'];
        $destLng = $route['destination']['lng'];
        $destLat = $route['destination']['lat'];

        // Query quirúrgico: encontrar solicitudes que le quedan de camino
        $matches = DB::select("
            WITH route_line AS (
                SELECT ST_MakeLine(
                    ST_SetSRID(ST_MakePoint(?, ?), 4326),
                    ST_SetSRID(ST_MakePoint(?, ?), 4326)
                ) as line
            )
            SELECT 
                sr.id,
                sr.client_id,
                sr.request_type,
                sr.pickup_address,
                sr.delivery_address,
                sr.offered_price,
                sr.passenger_count,
                u.name as client_name,
                -- Desvío del pickup a la ruta
                ST_Distance(
                    ST_SetSRID(ST_MakePoint(sr.pickup_lng, sr.pickup_lat), 4326)::geography,
                    (SELECT line FROM route_line)::geography
                ) / 1000 as pickup_detour_km,
                -- Desvío del delivery a la ruta
                ST_Distance(
                    ST_SetSRID(ST_MakePoint(sr.delivery_lng, sr.delivery_lat), 4326)::geography,
                    (SELECT line FROM route_line)::geography
                ) / 1000 as delivery_detour_km
            FROM service_requests sr
            JOIN users u ON u.id = sr.client_id
            WHERE 
                sr.status = 'pending'
                AND sr.request_type IN ('ride', 'delivery')
                -- Filtro quirúrgico: máximo 2km de desvío por punto
                AND ST_Distance(
                    ST_SetSRID(ST_MakePoint(sr.pickup_lng, sr.pickup_lat), 4326)::geography,
                    (SELECT line FROM route_line)::geography
                ) < 2000
                AND ST_Distance(
                    ST_SetSRID(ST_MakePoint(sr.delivery_lng, sr.delivery_lat), 4326)::geography,
                    (SELECT line FROM route_line)::geography
                ) < 2000
            ORDER BY (pickup_detour_km + delivery_detour_km) ASC
        ", [$originLng, $originLat, $destLng, $destLat]);

        return collect($matches);
    }

    /**
     * Calcular distancia entre dos puntos (Haversine)
     */
    private function calculateDistance($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
