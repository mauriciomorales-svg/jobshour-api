<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class DemandMapController extends Controller
{
    /**
     * Obtener pins dorados (demanda) cercanos
     * Endpoint: GET /api/v1/demand/nearby
     */
    public function nearby(Request $request)
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|numeric|min:0.1|max:100',
            'categories' => 'nullable|array',
            'categories.*' => 'integer|exists:categories,id',
        ]);

        $lat = $validated['lat'];
        $lng = $validated['lng'];
        $radius = $validated['radius'] ?? 50;
        $categoryIds = $validated['categories'] ?? [];

        $query = ServiceRequest::visibleInMap()
            ->with(['client:id,name,avatar', 'worker.user:id,name,avatar', 'category:id,slug,display_name,color'])
            ->near($lat, $lng, $radius);

        if (!empty($categoryIds)) {
            $query->whereIn('category_id', $categoryIds);
        }

        $demands = $query->get();

        return response()->json([
            'status' => 'success',
            'meta' => [
                'center' => ['lat' => $lat, 'lng' => $lng],
                'radius_searched' => "{$radius}km",
                'total_found' => $demands->count(),
            ],
            'data' => $demands->map(fn($d) => [
                'id' => $d->id,
                'pos' => [
                    'lat' => $d->fuzzed_latitude,
                    'lng' => $d->fuzzed_longitude,
                ],
                'client_name' => $d->worker?->user?->name ?? $d->client?->name ?? 'Anónimo',
                'client_avatar' => $d->worker?->user?->avatar ?? $d->client?->avatar,
                'category_color' => $d->category?->color ?? '#f59e0b',
                'category_slug' => $d->category?->slug,
                'category_name' => $d->category?->display_name,
                'offered_price' => (int) $d->offered_price,
                'description' => $d->description,
                'urgency' => $d->urgency,
                'travel_role' => $d->travel_role,
                'payload' => $d->payload,
                'distance_km' => round($d->distance_km, 2),
                'created_at' => $d->created_at->diffForHumans(),
                'expires_in_minutes' => $d->pin_expires_at ? 
                    max(0, now()->diffInMinutes($d->pin_expires_at, false)) : null,
            ])->values(),
        ]);
    }

    /**
     * Crear publicación dorada (cliente emite demanda)
     * Endpoint: POST /api/v1/demand/publish
     */
    public function publish(Request $request)
    {
        try {
            \Log::info('DemandMapController::publish - Start', ['user_id' => $request->user()?->id]);
            
            $validated = $request->validate([
                'category_id' => 'required|exists:categories,id',
                'description' => 'required|string|max:500',
                'lat' => 'required|numeric|between:-90,90',
                'lng' => 'required|numeric|between:-180,180',
                'offered_price' => 'nullable|numeric|min:0',
                'urgency' => 'nullable|in:low,medium,high,normal,urgent',
                'ttl_minutes' => 'nullable|integer|min:5|max:120',
                'type' => 'nullable|in:fixed_job,ride_share,express_errand',
                'travel_role' => 'nullable|in:driver,passenger',
                'category_type' => 'nullable|in:fixed,travel,errand',
                'payload' => 'nullable|array',
                // Campos para ride_share
                'pickup_address' => 'nullable|string|max:255',
                'delivery_address' => 'nullable|string|max:255',
                'pickup_lat' => 'nullable|numeric|between:-90,90',
                'pickup_lng' => 'nullable|numeric|between:-180,180',
                'delivery_lat' => 'nullable|numeric|between:-90,90',
                'delivery_lng' => 'nullable|numeric|between:-180,180',
                'departure_time' => 'nullable|date|after:-5 minutes',
                'seats' => 'nullable|integer|min:1|max:8',
                'destination_name' => 'nullable|string|max:255',
                // Campos para express_errand
                'store_name' => 'nullable|string|max:255',
                'items_count' => 'nullable|integer|min:1',
                'load_type' => 'nullable|in:light,medium,heavy',
                'requires_vehicle' => 'nullable|boolean',
                'image' => 'nullable|image|max:5120', // Max 5MB
                // Programación y multi-worker
                'scheduled_at' => 'nullable|date|after:now',
                'workers_needed' => 'nullable|integer|min:1|max:20',
                'recurrence' => 'nullable|in:once,daily,weekly,custom',
                'recurrence_days' => 'nullable|array',
                'recurrence_days.*' => 'integer|min:1|max:7',
            ]);

            \Log::info('DemandMapController::publish - Validation passed', $validated);

            $user = $request->user();
            $ttl = $validated['ttl_minutes'] ?? 30;
            
            // Mapear urgencia del frontend a valores permitidos por DB
            $urgencyMap = [
                'low' => 'normal',
                'medium' => 'normal',
                'high' => 'urgent',
                'normal' => 'normal',
                'urgent' => 'urgent',
            ];
            $dbUrgency = $urgencyMap[$validated['urgency'] ?? 'medium'] ?? 'normal';

            // Handle image upload
            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('demand-images', 'public');
                \Log::info('DemandMapController::publish - Image uploaded', ['path' => $imagePath]);
            }

            // Construir payload según el tipo
            $payload = [];
            if ($validated['type'] === 'ride_share') {
                $role = $validated['travel_role'] ?? 'passenger';
                $payload = [
                    'travel_role' => $role,
                    'seats' => $validated['seats'] ?? 1,
                    'departure_time' => $validated['departure_time'] ?? null,
                    'destination_name' => $validated['destination_name'] ?? $validated['delivery_address'] ?? null,
                    'vehicle_type' => $validated['payload']['vehicle_type'] ?? null,
                    // Driver extras
                    'origin_address' => $validated['pickup_address'] ?? null,
                    'destination_address' => $validated['delivery_address'] ?? null,
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

            // Agregar imagen al payload si se subió
            if ($imagePath) {
                $payload['image'] = '/storage/' . $imagePath;
            }

            \Log::info('DemandMapController::publish - Creating ServiceRequest', [
                'client_id' => $user->id,
                'category_id' => $validated['category_id'],
            ]);

            $serviceRequest = ServiceRequest::create([
                'client_id' => $user->id,
                'category_id' => $validated['category_id'],
                'type' => $validated['type'] ?? 'fixed_job',
                'travel_role' => $validated['travel_role'] ?? null,
                'category_type' => $validated['category_type'] ?? 'fixed',
                'description' => $validated['description'],
                'offered_price' => $validated['offered_price'] ?? 0,
                'urgency' => $dbUrgency,
                'status' => 'pending',
                'pin_expires_at' => now()->addMinutes($ttl),
                'payload' => !empty($payload) ? $payload : null,
                'pickup_address' => $validated['pickup_address'] ?? null,
                'delivery_address' => $validated['delivery_address'] ?? null,
                'pickup_lat' => $validated['pickup_lat'] ?? null,
                'pickup_lng' => $validated['pickup_lng'] ?? null,
                'delivery_lat' => $validated['delivery_lat'] ?? null,
                'delivery_lng' => $validated['delivery_lng'] ?? null,
                'scheduled_at' => $validated['scheduled_at'] ?? null,
                'workers_needed' => $validated['workers_needed'] ?? 1,
                'recurrence' => $validated['recurrence'] ?? 'once',
                'recurrence_days' => !empty($validated['recurrence_days']) ? json_encode($validated['recurrence_days']) : null,
            ]);

            \Log::info('DemandMapController::publish - ServiceRequest created', ['id' => $serviceRequest->id]);

            // Usar lat/lng del request o pickup_lat/pickup_lng si están disponibles
            $locationLat = $validated['pickup_lat'] ?? $validated['lat'];
            $locationLng = $validated['pickup_lng'] ?? $validated['lng'];

            DB::update(
                "UPDATE service_requests SET client_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                [$locationLng, $locationLat, $serviceRequest->id]
            );

            $serviceRequest->refresh();

            \Log::info('DemandMapController::publish - Success', ['request_id' => $serviceRequest->id]);

            return response()->json([
                'status' => 'success',
                'message' => '🟡 Publicación Dorada creada. Visible en el mapa por ' . $ttl . ' minutos',
                'data' => [
                    'request_id' => $serviceRequest->id,
                    'pin_expires_at' => $serviceRequest->pin_expires_at,
                    'visible_until' => $serviceRequest->pin_expires_at->diffForHumans(),
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('DemandMapController::publish - Validation error', ['errors' => $e->errors()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('DemandMapController::publish - Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al publicar demanda',
            ], 500);
        }
    }

    /**
     * Obtener detalle de una publicación dorada
     * Endpoint: GET /api/v1/demand/{id}
     * Si el usuario está autenticado, devuelve coordenadas exactas
     */
    public function show(Request $request, ServiceRequest $serviceRequest)
    {
        $serviceRequest->load(['client:id,name,avatar,phone', 'category:id,slug,display_name,color']);

        if ($serviceRequest->status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Esta publicación ya no está disponible',
            ], 404);
        }

        if ($serviceRequest->pin_expires_at && $serviceRequest->pin_expires_at->isPast()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Esta publicación ha expirado',
            ], 410);
        }

        // Determinar si el usuario está autenticado
        // Intentar obtener el usuario de múltiples formas
        $user = null;
        $useExactCoordinates = false;
        
        // Método 1: Intentar obtener usuario directamente (si el middleware está aplicado)
        try {
            $user = $request->user();
            if ($user) {
                $useExactCoordinates = true;
            }
        } catch (\Exception $e) {
            // Continuar con otros métodos
        }
        
        // Método 2: Validar token manualmente si no se obtuvo usuario
        if (!$user) {
            $authHeader = $request->header('Authorization');
            if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                $token = str_replace('Bearer ', '', $authHeader);
                try {
                    $accessToken = PersonalAccessToken::findToken($token);
                    if ($accessToken && $accessToken->tokenable) {
                        $user = $accessToken->tokenable;
                        $useExactCoordinates = true;
                    }
                } catch (\Exception $e) {
                    // Token inválido o expirado, usar coordenadas fuzzeadas
                }
            }
        }

        // Obtener coordenadas (exactas si está autenticado, fuzzeadas si no)
        $lat = 0;
        $lng = 0;
        
        if ($serviceRequest->client_location) {
            if ($useExactCoordinates) {
                // Coordenadas exactas para usuarios autenticados
                $location = DB::selectOne(
                    "SELECT ST_Y(client_location::geometry) as lat, ST_X(client_location::geometry) as lng 
                     FROM service_requests WHERE id = ?",
                    [$serviceRequest->id]
                );
                if ($location) {
                    $lat = $location->lat;
                    $lng = $location->lng;
                }
            } else {
                // Coordenadas fuzzeadas para usuarios no autenticados
                $lat = $serviceRequest->fuzzed_latitude;
                $lng = $serviceRequest->fuzzed_longitude;
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $serviceRequest->id,
                'client' => $serviceRequest->client ? [
                    'name' => $serviceRequest->client->name,
                    'avatar' => $serviceRequest->client->avatar,
                ] : [
                    'name' => 'Anónimo',
                    'avatar' => null,
                ],
                'category' => $serviceRequest->category ? [
                    'name' => $serviceRequest->category->display_name ?? $serviceRequest->category->name ?? 'Sin categoría',
                    'color' => $serviceRequest->category->color ?? '#6b7280',
                ] : [
                    'name' => 'Sin categoría',
                    'color' => '#6b7280',
                ],
                'description' => $serviceRequest->description,
                'offered_price' => (int) $serviceRequest->offered_price,
                'urgency' => $serviceRequest->urgency,
                'created_at' => $serviceRequest->created_at->diffForHumans(),
                'expires_in_minutes' => $serviceRequest->pin_expires_at ? 
                    max(0, now()->diffInMinutes($serviceRequest->pin_expires_at, false)) : null,
                'pos' => [
                    'lat' => $lat,
                    'lng' => $lng,
                    'exact' => $useExactCoordinates, // Indicar si son coordenadas exactas
                ],
                'type' => $serviceRequest->type,
                'travel_role' => $serviceRequest->travel_role,
                'category_type' => $serviceRequest->category_type,
                'payload' => $serviceRequest->payload,
                'pickup_address' => $serviceRequest->pickup_address,
                'delivery_address' => $serviceRequest->delivery_address,
                'pickup_lat' => $serviceRequest->pickup_lat,
                'pickup_lng' => $serviceRequest->pickup_lng,
                'delivery_lat' => $serviceRequest->delivery_lat,
                'delivery_lng' => $serviceRequest->delivery_lng,
            ],
        ]);
    }

    /**
     * Take público con auth manual (bypass middleware issues)
     */
    public function takePublic(Request $request, ServiceRequest $publicDemand)
    {
        try {
            // Auth manual via Bearer token
            $tokenStr = str_replace('Bearer ', '', $request->header('Authorization', ''));
            $parts = explode('|', $tokenStr, 2);
            $tokenId = $parts[0] ?? null;
            $tokenValue = $parts[1] ?? null;

            $accessToken = PersonalAccessToken::find($tokenId);
            if (!$accessToken || !hash_equals($accessToken->token, hash('sha256', $tokenValue ?? ''))) {
                return response()->json(['status' => 'error', 'message' => 'Token inválido'], 401);
            }

            $user = $accessToken->tokenable;
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'Usuario no encontrado'], 401);
            }

            // Validaciones
            if ($publicDemand->status !== 'pending') {
                return response()->json(['status' => 'error', 'message' => 'Esta demanda ya no está disponible', '_debug' => ['status' => $publicDemand->status]], 422);
            }
            if ($publicDemand->worker_id) {
                return response()->json(['status' => 'error', 'message' => 'Ya fue tomada por otro trabajador'], 422);
            }

            $worker = Worker::where('user_id', $user->id)->first();
            if (!$worker) {
                return response()->json(['status' => 'error', 'message' => 'Debes activar tu perfil de trabajador'], 422);
            }
            if ($publicDemand->client_id === $user->id) {
                return response()->json(['status' => 'error', 'message' => 'No puedes tomar tu propia demanda'], 422);
            }
            if ($worker->availability_status === 'inactive') {
                return response()->json(['status' => 'error', 'message' => 'Activa tu disponibilidad primero'], 422);
            }

            // Crear solicitud
            $newRequest = null;
            DB::transaction(function() use ($publicDemand, $worker, &$newRequest) {
                $newRequest = ServiceRequest::create([
                    'client_id' => $publicDemand->client_id,
                    'worker_id' => $worker->id,
                    'category_id' => $publicDemand->category_id,
                    'type' => $publicDemand->type ?? 'fixed_job',
                    'category_type' => $publicDemand->category_type ?? 'fixed',
                    'description' => $publicDemand->description,
                    'urgency' => $publicDemand->urgency ?? 'normal',
                    'offered_price' => $publicDemand->offered_price,
                    'status' => 'pending',
                    'expires_at' => now()->addMinutes(5),
                    'payload' => $publicDemand->payload,
                ]);

                if ($publicDemand->client_location) {
                    $location = DB::selectOne(
                        "SELECT ST_X(client_location::geometry) as lng, ST_Y(client_location::geometry) as lat FROM service_requests WHERE id = ?",
                        [$publicDemand->id]
                    );
                    if ($location) {
                        DB::update(
                            "UPDATE service_requests SET client_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                            [$location->lng, $location->lat, $newRequest->id]
                        );
                    }
                }
            });

            return response()->json([
                'status' => 'success',
                'message' => '✅ Has tomado esta demanda. El cliente tiene 5 minutos para confirmar.',
                'data' => $newRequest->load(['client:id,name,avatar', 'category:id,display_name,color']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al tomar demanda',
            ], 500);
        }
    }

    /**
     * Worker toma una demanda pública (crea solicitud dirigida a él)
     * Endpoint: POST /api/v1/demand/{id}/take
     */
    public function take(Request $request, ServiceRequest $publicDemand)
    {
        try {
            \Log::info('TAKE DEBUG', [
                'demand_id' => $publicDemand->id,
                'status' => $publicDemand->status,
                'worker_id' => $publicDemand->worker_id,
                'user_id' => $request->user()?->id,
                'token' => substr($request->bearerToken() ?? 'none', 0, 10),
            ]);

            // Validar que la demanda esté disponible
            if ($publicDemand->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Esta demanda ya no está disponible',
                    '_debug' => [
                        'demand_id' => $publicDemand->id,
                        'actual_status' => $publicDemand->status,
                        'worker_id' => $publicDemand->worker_id,
                        'user_id' => $request->user()?->id,
                    ],
                ], 422);
            }

            if ($publicDemand->pin_expires_at && $publicDemand->pin_expires_at->isPast()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Esta demanda ha expirado',
                ], 422);
            }

            // Validar que tenga worker_id (no puede ser una demanda ya asignada)
            if ($publicDemand->worker_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Esta demanda ya fue tomada por otro trabajador',
                ], 422);
            }

            $user = $request->user();
            
            // Obtener o crear worker del usuario autenticado
            $worker = Worker::where('user_id', $user->id)->first();
            if (!$worker) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Debes activar tu perfil de trabajador primero',
                ], 422);
            }

            // Validar que el worker no sea el mismo cliente
            if ($publicDemand->client_id === $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No puedes tomar tu propia demanda',
                ], 422);
            }

            // Validar que el worker esté disponible
            if ($worker->availability_status === 'inactive') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Debes activar tu disponibilidad para tomar demandas',
                ], 422);
            }

            // Crear nueva solicitud dirigida a este worker (basada en la demanda pública)
            $newRequest = null;
            DB::transaction(function() use ($publicDemand, $worker, &$newRequest) {
                $newRequest = ServiceRequest::create([
                    'client_id' => $publicDemand->client_id,
                    'worker_id' => $worker->id,
                    'category_id' => $publicDemand->category_id,
                    'type' => $publicDemand->type ?? 'fixed_job',
                    'category_type' => $publicDemand->category_type ?? 'fixed',
                    'description' => $publicDemand->description,
                    'urgency' => $publicDemand->urgency ?? 'normal',
                    'offered_price' => $publicDemand->offered_price,
                    'status' => 'pending',
                    'expires_at' => now()->addMinutes(5),
                    'payload' => $publicDemand->payload,
                    'pickup_address' => $publicDemand->pickup_address,
                    'delivery_address' => $publicDemand->delivery_address,
                    'pickup_lat' => $publicDemand->pickup_lat,
                    'pickup_lng' => $publicDemand->pickup_lng,
                    'delivery_lat' => $publicDemand->delivery_lat,
                    'delivery_lng' => $publicDemand->delivery_lng,
                    'carga_tipo' => $publicDemand->carga_tipo,
                    'carga_peso' => $publicDemand->carga_peso,
                ]);

                // Copiar ubicación geográfica
                if ($publicDemand->client_location) {
                    $location = DB::selectOne(
                        "SELECT ST_X(client_location::geometry) as lng, ST_Y(client_location::geometry) as lat FROM service_requests WHERE id = ?",
                        [$publicDemand->id]
                    );
                    if ($location) {
                        DB::update(
                            "UPDATE service_requests SET client_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                            [$location->lng, $location->lat, $newRequest->id]
                        );
                    }
                }

                // Marcar la demanda pública como tomada (opcional: puede quedarse visible para otros workers)
                // Por ahora la dejamos como está, pero podríamos agregar un campo "taken_by_worker_id"
            });

            if (!$newRequest) {
                throw new \Exception('Error al crear solicitud');
            }

            // Broadcast evento de nueva solicitud
            try {
                $event = new \App\Events\ServiceRequestCreated($newRequest);
                broadcast($event);
                $event->handle();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('DemandMapController::take - Error en broadcast', [
                    'error' => $e->getMessage(),
                    'request_id' => $newRequest->id
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => '✅ Has tomado esta demanda. El cliente tiene 5 minutos para confirmar.',
                'data' => $newRequest->load(['client:id,name,avatar', 'category:id,display_name,color']),
            ], 201);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('DemandMapController::take - Error crítico', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'demand_id' => $publicDemand->id ?? null,
                'user_id' => $request->user()?->id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al tomar demanda. Por favor intenta nuevamente.'
            ], 500);
        }
    }

    /**
     * Mis demandas publicadas
     * Endpoint: GET /api/v1/demand/mine
     */
    public function mine(Request $request)
    {
        $user = $request->user();
        $demands = ServiceRequest::where('client_id', $user->id)
            ->whereIn('status', ['pending', 'accepted', 'in_progress', 'completed', 'cancelled'])
            ->with(['category:id,slug,display_name,color'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $demands->map(fn($d) => [
                'id' => $d->id,
                'status' => $d->status,
                'description' => $d->description,
                'offered_price' => (int) $d->offered_price,
                'urgency' => $d->urgency,
                'category_name' => $d->category?->display_name,
                'category_color' => $d->category?->color ?? '#f59e0b',
                'created_at' => $d->created_at->diffForHumans(),
            ])->values(),
        ]);
    }
}
