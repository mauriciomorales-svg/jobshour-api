<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Models\SearchLog;
use App\Models\ProfileView;
use App\Events\ProfileViewed;
use App\Services\GeocodingService;
use App\Support\Geofence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpertController extends Controller
{
    private const RADIUS_STEPS = [5, 15, 50];
    private const INTERMEDIATE_MAX_RADIUS = 3; // km — amarillos solo cercanos

    public function nearby(Request $request)
    {
        try {
            Log::info('ExpertController::nearby called', [
                'request_params' => $request->all(),
            ]);
            
            $validated = $request->validate([
                'lat' => 'required|numeric|between:-90,90',
                'lng' => 'required|numeric|between:-180,180',
                'radius' => 'nullable|numeric|min:0.1|max:100',
                'categories' => 'nullable|array',
                'categories.*' => 'integer|exists:categories,id',
            ]);
            
            Log::info('ExpertController::nearby validation passed', [
                'validated' => $validated,
            ]);

        $lat = $validated['lat'];
        $lng = $validated['lng'];
        $requestedRadius = isset($validated['radius']) ? (float) $validated['radius'] : null;
        $categoryIds = $validated['categories'] ?? [];
        $isFallback = false;
        $workers = collect();

        // Cap duro: nunca buscar más allá del radio máximo configurado (protege el VPS)
        $maxRadius = Geofence::maxSearchRadiusKm();
        if ($requestedRadius) {
            $requestedRadius = min($requestedRadius, $maxRadius);
        }

        // Geofencing: si el punto está fuera de la zona piloto, devolver vacío con bandera
        if (Geofence::enabled() && ! Geofence::isInsideZone((float) $lat, (float) $lng)) {
            return response()->json([
                'status' => 'outside_zone',
                'data'   => [],
                'meta'   => [
                    'city'            => null,
                    'radius_searched' => '0km',
                    'total_found'     => 0,
                    'is_fallback'     => false,
                    'outside_zone'    => true,
                    'zone'            => Geofence::zoneInfo(),
                ],
            ]);
        }

        if ($requestedRadius) {
            $workers = $this->searchVisible($lat, $lng, $requestedRadius, $categoryIds);
            $finalRadius = $requestedRadius;
        } else {
            // Limitar los pasos de búsqueda al radio máximo permitido
            $steps = array_filter(self::RADIUS_STEPS, fn($s) => $s <= $maxRadius);
            if (empty($steps)) $steps = [$maxRadius];
            foreach ($steps as $i => $step) {
                $workers = $this->searchVisible($lat, $lng, $step, $categoryIds);
                $finalRadius = $step;

                if ($workers->count() > 0) {
                    $isFallback = $i > 0;
                    break;
                }
            }
            $finalRadius = $finalRadius ?? end($steps);
        }

        [$premiumPoints, $premiumWorkerIds] = $this->resolvePremiumStorePins((float) $lat, (float) $lng, (float) $finalRadius, $categoryIds);
        $workers = $workers->reject(fn ($w) => in_array((int) $w->id, $premiumWorkerIds, true));

        // Silent logging - TEMPORALMENTE DESHABILITADO
        /*
        SearchLog::log(
            lat: $lat,
            lng: $lng,
            resultsFound: $workers->count(),
            radiusUsed: (int) ($finalRadius * 1000),
            wasExpanded: $isFallback,
            categoryId: $categoryIds[0] ?? null,
            userId: $request->user()?->id,
            userAgent: $request->userAgent(),
            ip: $request->ip()
        );
        */

        $workerPoints = $workers->map(function($w) {
            try {
                return [
                    'id' => $w->id,
                    'user_id' => $w->user_id, // IMPORTANTE: Incluir user_id para identificar al usuario actual
                    'pos' => [
                        'lat' => ($w->lat ?? 0) + (mt_rand(-10, 10) * 0.0001), // fuzzed
                        'lng' => ($w->lng ?? 0) + (mt_rand(-10, 10) * 0.0001), // fuzzed
                    ],
                    'name' => $w->user?->nickname ?? $this->shortName($w->user?->name ?? 'Anónimo'),
                    'avatar' => $w->user?->avatar,
                    'price' => (int) ($w->hourly_rate ?? 0),
                    'category_color' => $w->category?->color ?? '#2563eb',
                    'category_slug' => $w->category?->slug,
                    'category_name' => $w->category?->display_name,
                    'fresh_score' => (float) ($w->rating ?? 0),
                    'status' => $w->availability_status ?? 'inactive',
                    'user_mode' => $w->user_mode ?? null, // Incluir user_mode para debugging
                    'active_route' => $w->active_route ?? null, // Incluir active_route si existe (modo viaje)
                    'microcopy' => $this->generateMicrocopy($w),
                    'has_video' => ($w->videos_count ?? 0) > 0,
                    'is_seller' => (bool) ($w->is_seller ?? false),
                    'store_name' => $w->store_name ?? null,
                ];
            } catch (\Exception $e) {
                Log::error('Error mapping worker', [
                    'worker_id' => $w->id ?? null,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        })->filter()->values();

        return response()->json([
            'status' => 'success',
            'meta' => [
                'center' => ['lat' => $lat, 'lng' => $lng],
                'city' => \App\Services\CityDetector::detect((float) $lat, (float) $lng),
                'radius_searched' => "{$finalRadius}km",
                'total_found' => $workerPoints->count() + $premiumPoints->count(),
                'is_fallback' => $isFallback,
            ],
            'data' => $workerPoints->concat($premiumPoints)->values(),
        ]);
        } catch (\Exception $e) {
            Log::error('ExpertController::nearby error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al buscar expertos',
                'data' => [],
                'meta' => [
                    'center' => ['lat' => $request->input('lat', 0), 'lng' => $request->input('lng', 0)],
                    'city' => \App\Services\CityDetector::detect((float) $request->input('lat', -37.67), (float) $request->input('lng', -72.58)),
                    'radius_searched' => '0km',
                    'total_found' => 0,
                    'is_fallback' => false,
                ],
            ], 500);
        }
    }

    public function show(Request $request, Worker $expert)
    {
        $expert->load(['user:id,name,nickname,avatar,phone', 'category', 'categories', 'videos', 'showcaseVideo', 'vcVideo']);

        // Shadow Interest: log profile view
        $city = GeocodingService::getCityName(
            (float) ($request->query('lat', -37.67)),
            (float) ($request->query('lng', -72.57))
        );

        $viewerId = $request->user()?->id;
        $viewerIp = $request->ip();

        // Debounce: only log 1 view per viewer-worker pair per 24h
        $recentView = ProfileView::where('worker_id', $expert->id)
            ->where(function ($q) use ($viewerId, $viewerIp) {
                if ($viewerId) {
                    $q->where('viewer_id', $viewerId);
                } else {
                    $q->where('viewer_ip', $viewerIp);
                }
            })
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();

        if (!$recentView) {
            ProfileView::create([
                'worker_id' => $expert->id,
                'viewer_id' => $viewerId,
                'viewer_ip' => $viewerIp,
                'viewer_city' => $city,
            ]);

            // Notify worker (debounced: max 1 per viewer per 24h)
            $viewsLastHour = ProfileView::where('worker_id', $expert->id)
                ->where('created_at', '>=', now()->subHour())
                ->count();
            try {
                broadcast(new ProfileViewed(
                    workerUserId: $expert->user_id,
                    viewerCity: $city,
                    viewCount: $viewsLastHour,
                ));
            } catch (\Throwable $e) {
                // Silent fail — don't break the response
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $expert->id,
                'user_id' => $expert->user_id,
                'nickname' => $expert->user->nickname,
                'name' => $expert->isActive() ? $expert->user->name : ($expert->user->nickname ?? $this->shortName($expert->user->name)),
                'avatar' => $expert->user->avatar,
                'phone' => $expert->user->phone ? $this->maskPhone($expert->user->phone) : null,
                'phone_revealed' => false,
                'title' => $expert->title,
                'bio' => $expert->bio,
                'skills' => $expert->skills,
                'hourly_rate' => (int) $expert->hourly_rate,
                'fresh_score' => (float) $expert->fresh_score,
                'fresh_score_count' => $expert->fresh_score_count,
                'rating_count' => $expert->rating_count,
                'total_jobs' => $expert->total_jobs_completed,
                'is_verified' => $expert->is_verified,
                'status' => $expert->availability_status,
                'category' => $expert->category ? [
                    'slug' => $expert->category->slug,
                    'name' => $expert->category->display_name,
                    'color' => $expert->category->color,
                    'icon' => $expert->category->icon,
                ] : null,
                'categories' => $expert->categories->map(fn($cat) => [
                    'id' => $cat->id,
                    'slug' => $cat->slug,
                    'name' => $cat->display_name,
                    'color' => $cat->color,
                    'icon' => $cat->icon,
                ])->toArray(),
                'videos_count' => $expert->videos->count(),
                'showcase_video' => $expert->showcaseVideo ? [
                    'url' => $expert->showcaseVideo->processed_path ?? $expert->showcaseVideo->original_path,
                    'thumbnail' => $expert->showcaseVideo->thumbnail_path,
                    'duration' => $expert->showcaseVideo->duration_seconds,
                ] : null,
                'vc_video' => $expert->vcVideo ? [
                    'url' => $expert->vcVideo->processed_path ?? $expert->vcVideo->original_path,
                    'thumbnail' => $expert->vcVideo->thumbnail_path,
                    'duration' => $expert->vcVideo->duration_seconds,
                ] : null,
                'pos' => [
                    'lat' => $expert->fuzzed_latitude,
                    'lng' => $expert->fuzzed_longitude,
                ],
                'microcopy' => $this->generateMicrocopy($expert),
                'last_seen' => $this->resolveLastSeen($expert),
                'is_seller' => (bool) ($expert->is_seller ?? false),
                'store_name' => $expert->store_name ?? null,
            ],
        ]);
    }

    public function count(Request $request)
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|numeric|min:1|max:50',
        ]);

        $lat    = $validated['lat'];
        $lng    = $validated['lng'];
        $radius = min((float) ($validated['radius'] ?? 10), Geofence::maxSearchRadiusKm());

        if (Geofence::enabled() && ! Geofence::isInsideZone((float) $lat, (float) $lng)) {
            return response()->json([
                'count'         => 0,
                'radius'        => $radius,
                'outside_zone'  => true,
                'label'         => 'Fuera de la zona piloto',
            ]);
        }

        $cacheKey = "workers_count_{$lat}_{$lng}_{$radius}";

        $count = Cache::remember($cacheKey, 45, function () use ($lat, $lng, $radius) {
            try {
                return DB::selectOne("
                    SELECT COUNT(*) as total
                    FROM workers
                    WHERE availability_status = 'active'
                      AND (user_mode = 'socio' OR user_mode IS NULL)
                      AND ST_DWithin(
                            location::geography,
                            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                            ?
                          )
                ", [$lng, $lat, $radius * 1000])->total ?? 0;
            } catch (\Throwable) {
                return 0;
            }
        });

        return response()->json([
            'count'  => (int) $count,
            'radius' => $radius,
            'label'  => $count === 0
                ? 'Nadie activo cerca'
                : ($count === 1 ? '1 worker verde cerca' : "{$count} workers verdes cerca"),
        ]);
    }

    private function searchVisible(float $lat, float $lng, float $radiusKm, array $categoryIds)
    {
        try {
            $with = ['user:id,name,nickname,avatar', 'category:id,slug,display_name,color'];

            $select = ['workers.*'];
            $selectRaw = 'ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng';

            // 🟢 ACTIVE: radio completo (solo modo socio)
            $activeQ = Worker::active()->with($with)->withCount('videos')->near($lat, $lng, $radiusKm)
                ->where(function($q) {
                    $q->where('user_mode', 'socio')->orWhereNull('user_mode');
                })
                ->select($select)->selectRaw($selectRaw);
            
            // 🟡 INTERMEDIATE: radio medio 5km (modo escucha, solo modo socio)
            $intRadius = min($radiusKm, 5);
            $intQ = Worker::where('availability_status', 'intermediate')->with($with)->withCount('videos')->near($lat, $lng, $intRadius)
                ->where(function($q) {
                    $q->where('user_mode', 'socio')->orWhereNull('user_mode');
                })
                ->select($select)->selectRaw($selectRaw);

            // ⚫ INACTIVE: No aparecen (invisible)
            // 🏢 EMPRESA: No aparecen en mapa público (solo referencia)

            if (!empty($categoryIds)) {
                $activeQ->whereIn('category_id', $categoryIds);
                $intQ->whereIn('category_id', $categoryIds);
            }

            return $activeQ->get()
                ->concat($intQ->get())
                ->unique('id');
        } catch (\Exception $e) {
            Log::error('ExpertController::searchVisible error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return collect(); // Retornar colección vacía en caso de error
        }
    }

    private function resolveLastSeen(Worker $worker): ?string
    {
        // Primero busca en cache (actualizado al cambiar estado)
        $cached = Cache::get("worker_last_seen_{$worker->id}");
        if ($cached) {
            return $cached;
        }

        // Fallback: campo last_seen_at de la BD
        if ($worker->last_seen_at) {
            return $worker->last_seen_at instanceof \Carbon\Carbon
                ? $worker->last_seen_at->toISOString()
                : $worker->last_seen_at;
        }

        return null;
    }

    private function shortName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        if (count($parts) <= 1) return $fullName;
        return $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '.';
    }

    /**
     * Distancia aproximada (km) para filtrar tienda premium por radio.
     */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadiusKm * $c;
    }

    /**
     * Pins premium desde BD (varios workers) o, si no hay ninguno, fallback por variables PREMIUM_STORE_*.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: array<int, int>} [puntos, worker_ids a excluir del listado normal]
     */
    private function resolvePremiumStorePins(float $lat, float $lng, float $radiusKm, array $categoryIds): array
    {
        try {
            [$fromDb, $idsFromDb] = $this->premiumPinsFromDatabase($lat, $lng, $radiusKm, $categoryIds);
            if ($fromDb->isNotEmpty()) {
                return [$fromDb, $idsFromDb];
            }

            return $this->premiumPinsFromEnv($lat, $lng, $radiusKm);
        } catch (\Throwable $e) {
            Log::warning('[ExpertController] resolvePremiumStorePins', ['error' => $e->getMessage()]);

            return [collect(), []];
        }
    }

    /**
     * @return array{0: \Illuminate\Support\Collection, 1: array<int, int>}
     */
    private function premiumPinsFromDatabase(float $lat, float $lng, float $radiusKm, array $categoryIds): array
    {
        $q = Worker::query()
            ->where('show_premium_pin_on_map', true)
            ->whereNotNull('premium_external_store_url')
            ->where('premium_external_store_url', '!=', '')
            ->whereNotNull('location')
            ->with(['user:id,name,nickname,avatar'])
            ->near($lat, $lng, $radiusKm)
            ->select(['workers.*'])
            ->selectRaw('ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng');

        if (! empty($categoryIds)) {
            $q->whereIn('category_id', $categoryIds);
        }

        $rows = $q->get();
        $points = collect();
        $ids = [];

        foreach ($rows as $pw) {
            if ($pw->lat === null || $pw->lng === null) {
                continue;
            }
            $ids[] = (int) $pw->id;
            $displayName = trim((string) ($pw->store_name ?: '')) !== ''
                ? (string) $pw->store_name
                : ($pw->user?->nickname ?? $this->shortName($pw->user?->name ?? 'Tienda'));
            $url = trim((string) $pw->premium_external_store_url);
            $mapId = 900000 + (int) $pw->id;

            $points->push([
                'id' => $mapId,
                'user_id' => null,
                'linked_worker_id' => (int) $pw->id,
                'pos' => [
                    'lat' => (float) $pw->lat + (mt_rand(-10, 10) * 0.0001),
                    'lng' => (float) $pw->lng + (mt_rand(-10, 10) * 0.0001),
                ],
                'name' => $displayName,
                'avatar' => $pw->user?->avatar,
                'price' => 0,
                'category_color' => '#a855f7',
                'category_slug' => null,
                'category_name' => 'Tienda Premium',
                'fresh_score' => 5,
                'status' => 'active',
                'pin_type' => 'premium_store',
                'microcopy' => 'Tienda Premium',
                'has_video' => false,
                'is_seller' => false,
                'store_name' => $displayName,
                'store_plan' => 'premium',
                'store_url' => $url,
            ]);
        }

        return [$points, $ids];
    }

    /**
     * @return array{0: \Illuminate\Support\Collection, 1: array<int, int>}
     */
    private function premiumPinsFromEnv(float $lat, float $lng, float $radiusKm): array
    {
        $premiumWorkerId = (int) env('PREMIUM_STORE_WORKER_ID', 2);
        $premiumUrl = (string) env('PREMIUM_STORE_URL', 'https://dondemorales.cl');
        $premiumName = (string) env('PREMIUM_STORE_NAME', 'DonDeMorales');
        $premiumAvatar = env('PREMIUM_STORE_AVATAR', null);

        $storeCoord = Worker::where('id', $premiumWorkerId)
            ->selectRaw('ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng')
            ->first();

        if (! $storeCoord || $storeCoord->lat === null || $storeCoord->lng === null) {
            return [collect(), []];
        }

        $distKm = $this->haversineKm($lat, $lng, (float) $storeCoord->lat, (float) $storeCoord->lng);
        if ($distKm > $radiusKm) {
            return [collect(), []];
        }

        $mapId = 900000 + $premiumWorkerId;

        return [
            collect([[
                'id' => $mapId,
                'user_id' => null,
                'linked_worker_id' => $premiumWorkerId,
                'pos' => [
                    'lat' => (float) $storeCoord->lat + (mt_rand(-10, 10) * 0.0001),
                    'lng' => (float) $storeCoord->lng + (mt_rand(-10, 10) * 0.0001),
                ],
                'name' => $premiumName,
                'avatar' => $premiumAvatar,
                'price' => 0,
                'category_color' => '#a855f7',
                'category_slug' => null,
                'category_name' => 'Tienda Premium',
                'fresh_score' => 5,
                'status' => 'active',
                'pin_type' => 'premium_store',
                'microcopy' => 'Tienda Premium',
                'has_video' => false,
                'is_seller' => false,
                'store_name' => $premiumName,
                'store_plan' => 'premium',
                'store_url' => $premiumUrl,
            ]]),
            [$premiumWorkerId],
        ];
    }

    private function maskPhone(string $phone): string
    {
        $len = mb_strlen($phone);
        if ($len <= 4) return str_repeat('*', $len);
        return mb_substr($phone, 0, 2) . str_repeat('*', $len - 4) . mb_substr($phone, -2);
    }

    private function generateMicrocopy(Worker $w): string
    {
        try {
            $name = $w->user?->nickname ?? (isset($w->user?->name) ? explode(' ', $w->user->name)[0] : 'Alguien');
            $cat = $w->category?->display_name ?? 'servicios';
            $status = $w->availability_status ?? 'inactive';

            return match ($status) {
                'active' => "{$name} está disponible ahora para {$cat}",
                'intermediate' => "{$name} anda cerca, quizás te hace la vuelta",
                'inactive' => "{$name} no está de turno, pero trabaja en {$cat}",
                default => '',
            };
        } catch (\Exception $e) {
            return '';
        }
    }
}
