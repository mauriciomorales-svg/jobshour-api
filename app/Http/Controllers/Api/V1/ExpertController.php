<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Models\SearchLog;
use App\Models\ProfileView;
use App\Events\ProfileViewed;
use App\Services\GeocodingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpertController extends Controller
{
    private const RADIUS_STEPS = [5, 15, 50];
    private const INTERMEDIATE_MAX_RADIUS = 3; // km — amarillos solo cercanos

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
        $requestedRadius = isset($validated['radius']) ? (float) $validated['radius'] : null;
        $categoryIds = $validated['categories'] ?? [];
        $isFallback = false;
        $workers = collect();

        if ($requestedRadius) {
            $workers = $this->searchVisible($lat, $lng, $requestedRadius, $categoryIds);
            $finalRadius = $requestedRadius;
        } else {
            foreach (self::RADIUS_STEPS as $i => $step) {
                $workers = $this->searchVisible($lat, $lng, $step, $categoryIds);
                $finalRadius = $step;

                if ($workers->count() > 0) {
                    $isFallback = $i > 0;
                    break;
                }
            }
            $finalRadius = $finalRadius ?? self::RADIUS_STEPS[count(self::RADIUS_STEPS) - 1];
        }

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

        return response()->json([
            'status' => 'success',
            'meta' => [
                'center' => ['lat' => $lat, 'lng' => $lng],
                'city' => 'Renaico', // GeocodingService::getCityName((float) $lat, (float) $lng),
                'radius_searched' => "{$finalRadius}km",
                'total_found' => $workers->count(),
                'is_fallback' => $isFallback,
            ],
            'data' => $workers->map(fn($w) => [
                'id' => $w->id,
                'pos' => [
                    'lat' => $w->lat + (mt_rand(-10, 10) * 0.0001), // fuzzed
                    'lng' => $w->lng + (mt_rand(-10, 10) * 0.0001), // fuzzed
                ],
                'name' => $w->user->nickname ?? $this->shortName($w->user->name),
                'avatar' => $w->user->avatar,
                'price' => (int) $w->hourly_rate,
                'category_color' => $w->category?->color ?? '#2563eb',
                'category_slug' => $w->category?->slug,
                'category_name' => $w->category?->display_name,
                'fresh_score' => (float) $w->fresh_score,
                'status' => $w->availability_status,
                'microcopy' => $this->generateMicrocopy($w),
                'has_video' => $w->videos_count > 0,
            ])->values(),
        ]);
    }

    public function show(Request $request, Worker $expert)
    {
        $expert->load(['user:id,name,nickname,avatar,phone', 'category', 'videos', 'showcaseVideo', 'vcVideo']);

        // Shadow Interest: log profile view
        $city = GeocodingService::getCityName(
            (float) ($request->query('lat', -37.67)),
            (float) ($request->query('lng', -72.57))
        );
        ProfileView::create([
            'worker_id' => $expert->id,
            'viewer_id' => $request->user()?->id,
            'viewer_ip' => $request->ip(),
            'viewer_city' => $city,
        ]);

        // Notify intermediate worker: someone is looking at their profile
        if ($expert->isIntermediate()) {
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
                'nickname' => $expert->user->nickname,
                'name' => $expert->isActive() ? $expert->user->name : ($expert->user->nickname ?? $this->shortName($expert->user->name)),
                'avatar' => $expert->user->avatar,
                'phone' => $expert->isActive() ? $expert->user->phone : null,
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
            ],
        ]);
    }

    private function searchVisible(float $lat, float $lng, float $radiusKm, array $categoryIds)
    {
        $with = ['user:id,name,nickname,avatar', 'category:id,slug,display_name,color'];

        $select = ['workers.*'];
        $selectRaw = 'ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng';

        // 🟢 ACTIVE: radio completo
        $activeQ = Worker::active()->with($with)->withCount('videos')->near($lat, $lng, $radiusKm)
            ->select($select)->selectRaw($selectRaw);
        
        // 🟡 INTERMEDIATE: radio medio 5km (modo escucha)
        $intRadius = min($radiusKm, 5);
        $intQ = Worker::where('availability_status', 'intermediate')->with($with)->withCount('videos')->near($lat, $lng, $intRadius)
            ->select($select)->selectRaw($selectRaw);

        // ⚫ INACTIVE: No aparecen (invisible)

        if (!empty($categoryIds)) {
            $activeQ->whereIn('category_id', $categoryIds);
            $intQ->whereIn('category_id', $categoryIds);
        }

        return $activeQ->get()
            ->concat($intQ->get())
            ->unique('id');
    }

    private function shortName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        if (count($parts) <= 1) return $fullName;
        return $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '.';
    }

    private function generateMicrocopy(Worker $w): string
    {
        $name = $w->user->nickname ?? explode(' ', $w->user->name)[0];
        $cat = $w->category?->display_name ?? 'servicios';

        return match ($w->availability_status) {
            'active' => "{$name} está disponible ahora para {$cat}",
            'intermediate' => "{$name} anda cerca, quizás te hace la vuelta",
            'inactive' => "{$name} no está de turno, pero trabaja en {$cat}",
            default => '',
        };
    }
}
