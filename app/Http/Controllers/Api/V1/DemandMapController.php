<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Models\Worker;
use App\Services\DemandPublishService;
use App\Support\Geofence;
use App\Support\JobshourSla;
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
        $radius = min((float) ($validated['radius'] ?? 50), Geofence::maxSearchRadiusKm());
        $categoryIds = $validated['categories'] ?? [];

        if (Geofence::enabled() && ! Geofence::isInsideZone((float) $lat, (float) $lng)) {
            return response()->json([
                'status' => 'outside_zone',
                'meta' => [
                    'center' => ['lat' => $lat, 'lng' => $lng],
                    'radius_searched' => '0km',
                    'total_found' => 0,
                    'outside_zone' => true,
                    'zone' => Geofence::zoneInfo(),
                ],
                'data' => [],
            ]);
        }

        $query = ServiceRequest::visibleInMap()
            ->with(['client:id,name,avatar', 'worker.user:id,name,avatar', 'category:id,slug,display_name,color'])
            ->near($lat, $lng, $radius);

        if (!empty($categoryIds)) {
            $query->whereIn('category_id', $categoryIds);
        }

        $demands = $query->get()->sort(function ($a, $b) {
            $ab = ($a->boosted_until && $a->boosted_until->isFuture()) ? 0 : 1;
            $bb = ($b->boosted_until && $b->boosted_until->isFuture()) ? 0 : 1;
            if ($ab !== $bb) {
                return $ab <=> $bb;
            }

            return ($a->distance_km ?? 0) <=> ($b->distance_km ?? 0);
        })->values();

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

            // Handle image upload
            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('demand-images', 'public');
                \Log::info('DemandMapController::publish - Image uploaded', ['path' => $imagePath]);
            }

            try {
                $serviceRequest = app(DemandPublishService::class)->publish($user, $validated, $imagePath);
            } catch (\InvalidArgumentException $e) {
                if ($e->getMessage() === 'outside_zone') {
                    return response()->json([
                        'message' => 'La ubicación está fuera de la zona piloto activa.',
                        'zone' => Geofence::zoneInfo(),
                    ], 422);
                }
                throw $e;
            }

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
     * Take público con auth manual (bypass middleware issues).
     * Usa el mismo mutex atómico que take().
     */
    public function takePublic(Request $request, ServiceRequest $publicDemand)
    {
        try {
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

            $worker = Worker::where('user_id', $user->id)->first();
            if (!$worker) {
                return response()->json(['status' => 'error', 'message' => 'Debes activar tu perfil de trabajador'], 422);
            }

            $precheck = $this->precheckTake($publicDemand, $user->id, $worker);
            if ($precheck !== null) {
                return $precheck;
            }

            return $this->atomicTake($publicDemand, $worker, 'takePublic');
        } catch (\App\Exceptions\DemandAlreadyTakenException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al tomar demanda',
            ], 500);
        }
    }

    /**
     * Worker toma una demanda pública (crea solicitud dirigida a él).
     * Endpoint: POST /api/v1/demand/{id}/take
     */
    public function take(Request $request, ServiceRequest $publicDemand)
    {
        try {
            $user = $request->user();

            $worker = Worker::where('user_id', $user->id)->first();
            if (!$worker) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Debes activar tu perfil de trabajador primero',
                ], 422);
            }

            $precheck = $this->precheckTake($publicDemand, $user->id, $worker);
            if ($precheck !== null) {
                return $precheck;
            }

            return $this->atomicTake($publicDemand, $worker, 'take');
        } catch (\App\Exceptions\DemandAlreadyTakenException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('DemandMapController::take - Error crítico', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'demand_id' => $publicDemand->id ?? null,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al tomar demanda. Por favor intenta nuevamente.',
            ], 500);
        }
    }

    /**
     * Validaciones previas compartidas entre take() y takePublic().
     * Devuelve JsonResponse si hay error, null si todo está bien.
     */
    private function precheckTake(ServiceRequest $demand, int $userId, Worker $worker): ?\Illuminate\Http\JsonResponse
    {
        if ($demand->status !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'Esta demanda ya no está disponible'], 422);
        }

        if ($demand->pin_expires_at && $demand->pin_expires_at->isPast()) {
            return response()->json(['status' => 'error', 'message' => 'Esta demanda ha expirado'], 422);
        }

        if ($demand->taken_by_worker_id !== null) {
            return response()->json(['status' => 'error', 'message' => 'Esta demanda ya fue tomada por otro trabajador'], 409);
        }

        if ($demand->client_id === $userId) {
            return response()->json(['status' => 'error', 'message' => 'No puedes tomar tu propia demanda'], 422);
        }

        if ($worker->availability_status === 'inactive') {
            return response()->json(['status' => 'error', 'message' => 'Debes activar tu disponibilidad para tomar demandas'], 422);
        }

        return null;
    }

    /**
     * Cierre atómico de la demanda + creación de la solicitud derivada.
     *
     * Usa un UPDATE condicional (WHERE taken_by_worker_id IS NULL) como mutex de BD,
     * garantizando que aunque dos workers lleguen al mismo tiempo, solo uno crea la solicitud.
     */
    private function atomicTake(ServiceRequest $demand, Worker $worker, string $caller): \Illuminate\Http\JsonResponse
    {
        $newRequest = null;

        DB::transaction(function () use ($demand, $worker, &$newRequest) {
            // 1. Intentar reservar la demanda de forma atómica.
            $locked = DB::update(
                "UPDATE service_requests
                 SET taken_by_worker_id = ?, taken_at = NOW()
                 WHERE id = ? AND status = 'pending' AND taken_by_worker_id IS NULL",
                [$worker->id, $demand->id]
            );

            if ($locked === 0) {
                // Otro worker llegó primero — abortar la transacción con una excepción controlada.
                throw new \App\Exceptions\DemandAlreadyTakenException();
            }

            // 2. Crear la solicitud dirigida derivada.
            $newRequest = ServiceRequest::create([
                'client_id'             => $demand->client_id,
                'worker_id'             => $worker->id,
                'category_id'           => $demand->category_id,
                'type'                  => $demand->type ?? 'fixed_job',
                'category_type'         => $demand->category_type ?? 'fixed',
                'description'           => $demand->description,
                'urgency'               => $demand->urgency ?? 'normal',
                'offered_price'         => $demand->offered_price,
                'status'                => 'pending',
                'expires_at'            => JobshourSla::mapTakeExpiresAt(),
                'payload'               => $demand->payload,
                'pickup_address'        => $demand->pickup_address,
                'delivery_address'      => $demand->delivery_address,
                'pickup_lat'            => $demand->pickup_lat,
                'pickup_lng'            => $demand->pickup_lng,
                'delivery_lat'          => $demand->delivery_lat,
                'delivery_lng'          => $demand->delivery_lng,
                'carga_tipo'            => $demand->carga_tipo,
                'carga_peso'            => $demand->carga_peso,
                'derived_from_demand_id' => $demand->id,
            ]);

            // 3. Copiar ubicación geográfica.
            if ($demand->client_location) {
                $loc = DB::selectOne(
                    "SELECT ST_X(client_location::geometry) as lng, ST_Y(client_location::geometry) as lat
                     FROM service_requests WHERE id = ?",
                    [$demand->id]
                );
                if ($loc) {
                    DB::update(
                        "UPDATE service_requests SET client_location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                        [$loc->lng, $loc->lat, $newRequest->id]
                    );
                }
            }

            // 4. Sacar el pin del mapa (demanda ya tomada, no debe aparecer a otros workers).
            DB::update(
                "UPDATE service_requests SET pin_expires_at = NOW() WHERE id = ?",
                [$demand->id]
            );
        });

        // Broadcast nueva solicitud.
        try {
            $event = new \App\Events\ServiceRequestCreated($newRequest);
            broadcast($event);
            $event->handle();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("DemandMapController::{$caller} - Error broadcast", [
                'error' => $e->getMessage(),
                'request_id' => $newRequest?->id,
            ]);
        }

        $newRequest->load(['client:id,name,avatar', 'category:id,display_name,color']);
        $acceptHuman = JobshourSla::describeSeconds(JobshourSla::mapTakeAcceptSeconds());

        return response()->json([
            'status'  => 'success',
            'message' => "✅ Has tomado esta demanda. Tienes {$acceptHuman} para pulsar Aceptar en Mis solicitudes.",
            'data'    => $newRequest,
        ], 201);
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
