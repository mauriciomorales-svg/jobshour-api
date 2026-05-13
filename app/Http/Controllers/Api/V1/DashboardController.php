<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Models\StoreOrder;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    /**
     * Dashboard de 36 Nodos + Infinite Scroll
     * Algoritmo de Feed Emocional: Mix de active, urgent, completed
     * Paginación cursor-based por distancia
     */
    public function feed(Request $request)
    {
        $validated = $request->validate([
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'cursor' => 'nullable|integer|min:0',
            'radius' => 'nullable|numeric|min:1|max:200',
        ]);

        // Valores por defecto si no se proporcionan
        $lat = $validated['lat'] ?? -37.6672;
        $lng = $validated['lng'] ?? -72.5730;

        $cursor = $validated['cursor'] ?? 0;
        $baseRadius = $validated['radius'] ?? 50;
        
        // Expansión radial: cada página aumenta el radio +10km
        $page = floor($cursor / 36);
        $radius = $baseRadius + ($page * 10);

        $perPage = 36;
        $offset = $cursor;

        // ── BLOQUE INICIAL (36 registros) ──
        if ($cursor === 0) {
            $feed = $this->buildInitialFeed($lat, $lng, $radius);
        } else {
            // ── PAGINACIÓN INFINITA (36+ registros) ──
            $feed = $this->buildPaginatedFeed($lat, $lng, $radius, $offset, $perPage);
        }

        return response()->json([
            'status' => 'success',
            'meta' => [
                'cursor' => $cursor,
                'next_cursor' => $cursor + count($feed),
                'radius_km' => $radius,
                'total_returned' => count($feed),
                'has_more' => count($feed) === $perPage,
            ],
            'data' => $feed,
        ]);
    }

    /**
     * Bloque Inicial: 36 registros con Mix Emocional
     * - Slots 1-3: Top Premium (mayor pago + urgencia)
     * - Slots 4-24: Feed Estándar (active + urgent mezclados)
     * - Slots 25-36: Validación (completed recientes)
     */
    private function buildInitialFeed(float $lat, float $lng, float $radius)
    {
        try {
            $feed = [];
            $userId = auth('sanctum')->id();
            $excludedIds = [];

        // ── SLOTS 1-3: TOP PREMIUM (Mayor pago + Urgencia) ──
        $topPremium = ServiceRequest::visibleInMap()
            ->with(['client:id,name,avatar', 'category:id,slug,display_name,color'])
            ->near($lat, $lng, $radius)
            ->where('urgency', 'urgent')
            ->whereNull('worker_id')
            ->when($userId, fn($q) => $q->where('client_id', '!=', $userId))
            ->orderByDesc('offered_price')
            ->limit(3)
            ->get();

        foreach ($topPremium as $sr) {
            $feed[] = $this->formatCard($sr, 'premium');
            $excludedIds[] = $sr->id;
        }

        // ── SLOTS 4-15: ACTIVE (Cercanía) ──
        $active = ServiceRequest::visibleInMap()
            ->with(['client:id,name,avatar', 'category:id,slug,display_name,color'])
            ->near($lat, $lng, $radius)
            ->where('status', 'pending')
            ->whereNull('worker_id')
            ->when($userId, fn($q) => $q->where('client_id', '!=', $userId))
            ->when(!empty($excludedIds), fn($q) => $q->whereNotIn('id', $excludedIds))
            ->limit(12)
            ->get();

        foreach ($active as $sr) {
            $feed[] = $this->formatCard($sr, 'standard');
            $excludedIds[] = $sr->id;
        }

        // ── SLOTS 16-24: MIX URGENT (Pares: Travel, Impares: Errand) ──
        $urgent = ServiceRequest::visibleInMap()
            ->with(['client:id,name,avatar', 'category:id,slug,display_name,color'])
            ->near($lat, $lng, $radius)
            ->where('urgency', 'urgent')
            ->whereNull('worker_id')
            ->when($userId, fn($q) => $q->where('client_id', '!=', $userId))
            ->when(!empty($excludedIds), fn($q) => $q->whereNotIn('id', $excludedIds))
            ->limit(9)
            ->get();

        foreach ($urgent as $index => $sr) {
            $feed[] = $this->formatCard($sr, 'standard');
            $excludedIds[] = $sr->id;
        }

        // ── SLOTS 25-36: HISTÓRICOS (Validación Social) ──
        $completed = ServiceRequest::where('status', 'completed')
            ->with(['client:id,name,avatar', 'category:id,slug,display_name,color'])
            ->near($lat, $lng, $radius)
            ->when($userId, fn($q) => $q->where('client_id', '!=', $userId))
            ->whereNotNull('client_location')
            ->orderByDesc('completed_at')
            ->limit(12)
            ->get();

        foreach ($completed as $sr) {
            $feed[] = $this->formatCard($sr, 'historical');
        }

        return $feed;
        } catch (\Exception $e) {
            Log::error('DashboardController::buildInitialFeed error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return []; // Retornar array vacío en caso de error
        }
    }

    /**
     * Paginación Infinita: Slots 37+
     * Modo minimalista (solo título, precio, distancia)
     */
    private function buildPaginatedFeed(float $lat, float $lng, float $radius, int $offset, int $perPage)
    {
        try {
            $requests = ServiceRequest::visibleInMap()
                ->with(['client:id,name,avatar', 'category:id,slug,display_name,color'])
                ->near($lat, $lng, $radius)
                ->offset($offset)
                ->limit($perPage)
                ->get();

            return $requests->map(fn($sr) => $this->formatCard($sr, 'minimal'))->toArray();
        } catch (\Exception $e) {
            Log::error('DashboardController::buildPaginatedFeed error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return []; // Retornar array vacío en caso de error
        }
    }

    /**
     * Formatear tarjeta según tipo y template
     */
    private function formatCard(ServiceRequest $sr, string $template)
    {
        $base = [
            'id' => $sr->id,
            'worker_id' => $sr->worker_id,
            'type' => $sr->type ?? 'fixed_job',
            'category_type' => $sr->category_type,
            'status' => $sr->status,
            'template' => $template,
            'description' => $sr->description,
            'pos' => [
                'lat' => $sr->fuzzed_latitude,
                'lng' => $sr->fuzzed_longitude,
            ],
            'client' => [
                'id' => $sr->client->id ?? null,
                'name' => $sr->client->name ?? 'Anónimo',
                'avatar' => $sr->client->avatar ?? null,
            ],
            'category' => [
                'name' => $sr->category->display_name ?? 'General',
                'color' => $sr->category->color ?? '#f59e0b',
            ],
            'offered_price' => (int) $sr->offered_price,
            'urgency' => $sr->urgency,
            'distance_km' => round($sr->distance_km ?? 0, 2),
            'pickup_address' => $sr->pickup_address,
            'delivery_address' => $sr->delivery_address,
            'created_at' => $sr->created_at->diffForHumans(),
            'scheduled_at' => $sr->scheduled_at?->toIso8601String(),
            'workers_needed' => (int) ($sr->workers_needed ?? 1),
            'workers_accepted' => (int) ($sr->workers_accepted ?? 0),
            'recurrence' => $sr->recurrence ?? 'once',
            'recurrence_days' => $sr->recurrence_days,
            'payload' => $sr->payload ?? [],
            'boosted_until' => $sr->boosted_until?->toIso8601String(),
        ];

        // El payload ya está incluido completo en $base, no necesitamos procesarlo

        // Templates específicos
        if ($template === 'minimal') {
            return [
                'id' => $base['id'],
                'type' => $base['type'],
                'category_type' => $base['category_type'],
                'description' => $base['description'],
                'offered_price' => $base['offered_price'],
                'distance_km' => $base['distance_km'],
                'pickup_address' => $base['pickup_address'],
                'delivery_address' => $base['delivery_address'],
                'urgency' => $base['urgency'],
                'payload' => $base['payload'],
                'template' => 'minimal',
                'pos' => $base['pos'],
                'client' => $base['client'],
                'category' => $base['category'],
                'status' => $base['status'],
                'created_at' => $base['created_at'],
            ];
        }

        if ($template === 'historical') {
            $base['completed_at'] = $sr->completed_at?->diffForHumans();
            $base['client']['name'] = 'Alguien'; // Anonimizar
        }

        return $base;
    }

    /**
     * Contador de "Pueblo Vivo"
     * Cuántos socios activos hay en el radio
     */
    public function liveStats(Request $request)
    {
        try {
            $validated = $request->validate([
                'lat' => 'nullable|numeric|between:-90,90',
                'lng' => 'nullable|numeric|between:-180,180',
                'radius' => 'nullable|numeric|min:1|max:100',
            ]);

            $lat = $validated['lat'] ?? -37.6672;
            $lng = $validated['lng'] ?? -72.5730;
            $radius = $validated['radius'] ?? 50;

            Log::info('LiveStats: Iniciando consulta', ['lat' => $lat, 'lng' => $lng, 'radius' => $radius]);

            // Intentar contar workers activos con consulta geográfica
            $activeWorkersCount = 0;
            try {
                $activeWorkers = DB::selectOne("
                    SELECT COUNT(*) as count
                    FROM workers
                    WHERE availability_status = 'active'
                      AND user_mode = 'socio'
                      AND location IS NOT NULL
                      AND ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
                ", [$lng, $lat, $radius * 1000]);
                
                $activeWorkersCount = $activeWorkers->count ?? 0;
                Log::info('LiveStats: Workers encontrados', ['count' => $activeWorkersCount]);
            } catch (\Exception $e) {
                Log::error('LiveStats: Error en consulta de workers', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Fallback: contar sin consulta geográfica
                try {
                    $activeWorkersCount = DB::table('workers')
                        ->where('availability_status', 'active')
                        ->where('user_mode', 'socio')
                        ->count();
                    Log::info('LiveStats: Usando fallback (sin geografía)', ['count' => $activeWorkersCount]);
                } catch (\Exception $e2) {
                    Log::error('LiveStats: Error en fallback', ['error' => $e2->getMessage()]);
                    $activeWorkersCount = 0;
                }
            }

            // Contar demandas activas
            $activeDemands = 0;
            try {
                $activeDemands = ServiceRequest::visibleInMap()
                    ->near($lat, $lng, $radius)
                    ->count();
                Log::info('LiveStats: Demandas encontradas', ['count' => $activeDemands]);
            } catch (\Exception $e) {
                Log::error('LiveStats: Error en consulta de demandas', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $activeDemands = 0;
            }

            $message = $activeWorkersCount > 0 
                ? "Hay {$activeWorkersCount} socios activos en tu radio ahora mismo"
                : "No hay socios activos en tu radio en este momento";

            return response()->json([
                'status' => 'success',
                'data' => [
                    'active_workers' => $activeWorkersCount,
                    'active_demands' => $activeDemands,
                    'message' => $message,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('LiveStats: Error de validación', ['errors' => $e->errors()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación: ' . json_encode($e->errors()),
            ], 422);
        } catch (\Exception $e) {
            Log::error('LiveStats: Error general', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage(),
                'data' => [
                    'active_workers' => 0,
                    'active_demands' => 0,
                    'message' => 'Error al cargar datos',
                ],
            ], 500);
        }
    }

    /**
     * Resumen motivador para el panel "Demandas": tienda + servicios + reputación (usuario autenticado con perfil worker).
     */
    public function workerPulse(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        }

        /** @var Worker|null $worker */
        $worker = $user->worker;
        if (! $worker) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'has_worker' => false,
                    'worker_id' => null,
                    'tagline' => '',
                    'store' => [
                        'is_seller' => false,
                        'store_name' => null,
                        'orders_pending' => 0,
                        'orders_paid_30d' => 0,
                        'revenue_paid_30d_clp' => 0,
                    ],
                    'services' => [
                        'active_jobs' => 0,
                        'completed_30d' => 0,
                    ],
                    'reputation' => [
                        'rating' => null,
                        'rating_count' => 0,
                        'total_jobs_completed' => 0,
                    ],
                ],
            ]);
        }

        $wid = $worker->id;
        $since = now()->subDays(30);

        $ordersPending = StoreOrder::where('worker_id', $wid)->where('status', 'pending')->count();
        $ordersPaid30d = StoreOrder::where('worker_id', $wid)
            ->where('status', 'paid')
            ->where('updated_at', '>=', $since)
            ->count();
        $revenuePaid30d = (int) StoreOrder::where('worker_id', $wid)
            ->where('status', 'paid')
            ->where('updated_at', '>=', $since)
            ->sum('total');

        $activeJobs = ServiceRequest::where('worker_id', $wid)
            ->whereIn('status', ['accepted', 'in_progress'])
            ->count();

        $completed30d = ServiceRequest::where('worker_id', $wid)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $since)
            ->count();

        $taglines = [
            'Sumá ingresos con servicios a domicilio y ventas en tu tienda.',
            'Cada demanda resuelta suma reputación; la tienda suma pedidos recurrentes.',
            'Activá disponibilidad y revisá pedidos: el dinero extra está en lo que ya sabés hacer.',
            'Clientes cerca pagan por urgencia: revisá el feed y tu tienda en el mismo lugar.',
        ];
        $tagline = $taglines[(int) (now()->dayOfYear % count($taglines))];

        return response()->json([
            'status' => 'success',
            'data' => [
                'has_worker' => true,
                'worker_id' => $wid,
                'tagline' => $tagline,
                'store' => [
                    'is_seller' => (bool) $worker->is_seller,
                    'store_name' => $worker->store_name,
                    'orders_pending' => $ordersPending,
                    'orders_paid_30d' => $ordersPaid30d,
                    'revenue_paid_30d_clp' => $revenuePaid30d,
                ],
                'services' => [
                    'active_jobs' => $activeJobs,
                    'completed_30d' => $completed30d,
                ],
                'reputation' => [
                    'rating' => $worker->rating !== null ? (float) $worker->rating : null,
                    'rating_count' => (int) $worker->rating_count,
                    'total_jobs_completed' => (int) ($worker->total_jobs_completed ?? 0),
                ],
            ],
        ]);
    }
}
