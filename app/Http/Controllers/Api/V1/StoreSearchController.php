<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use Illuminate\Http\Request;

class StoreSearchController extends Controller
{
    private const RADIUS_STEPS_KM = [10, 25, 50];

    public function search(Request $request)
    {
        $validated = $request->validate([
            'q' => 'nullable|string|max:120',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|numeric|min:1|max:50',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $q = trim((string) ($validated['q'] ?? ''));
        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];
        $limit = (int) ($validated['limit'] ?? 20);

        $radii = isset($validated['radius'])
            ? [(float) $validated['radius']]
            : self::RADIUS_STEPS_KM;

        $results = collect();
        $radiusUsed = (float) end($radii);
        $isFallback = false;

        foreach ($radii as $index => $radiusKm) {
            $radiusUsed = (float) $radiusKm;
            $candidates = $this->searchInRadius($lat, $lng, $radiusKm, $q, $limit);
            if ($candidates->isNotEmpty()) {
                $results = $candidates;
                $isFallback = $index > 0;
                break;
            }
        }

        return response()->json([
            'status' => 'success',
            'meta' => [
                'radius_used_km' => $radiusUsed,
                'is_fallback' => $isFallback,
                'query' => $q,
                'total_found' => $results->count(),
            ],
            'data' => $results->values(),
        ]);
    }

    private function searchInRadius(float $lat, float $lng, float $radiusKm, string $query, int $limit)
    {
        $workers = Worker::query()
            ->with(['user:id,name,nickname,avatar'])
            ->where('is_seller', true)
            ->whereIn('availability_status', ['active', 'intermediate', 'inactive'])
            ->where(function ($q) {
                $q->where('user_mode', 'socio')->orWhereNull('user_mode');
            })
            ->near($lat, $lng, $radiusKm)
            ->select('workers.*')
            ->selectRaw('ST_Y(location::geometry) as lat')
            ->selectRaw('ST_X(location::geometry) as lng')
            ->selectRaw('ST_DistanceSphere(location::geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326)) / 1000 as distance_km', [$lng, $lat])
            ->limit(200)
            ->get();

        $normalizedQuery = $this->normalize($query);

        $scored = $workers
            ->map(function (Worker $worker) use ($normalizedQuery, $radiusKm) {
                $textScore = $this->scoreText($worker, $normalizedQuery);
                if ($normalizedQuery !== '' && $textScore <= 0) {
                    return null;
                }

                $distanceKm = round((float) ($worker->distance_km ?? 0), 2);
                $distanceScore = max(0, 1 - ($distanceKm / max($radiusKm, 1)));
                $score = ($textScore * 0.7) + ($distanceScore * 0.3);

                return [
                    'id' => $worker->id,
                    'store_name' => $worker->store_name ?: ($worker->user?->nickname ?? $worker->user?->name ?? 'Tienda'),
                    'name' => $worker->user?->name ?? 'Sin nombre',
                    'nickname' => $worker->user?->nickname,
                    'avatar' => $worker->user?->avatar,
                    'fresh_score' => (float) ($worker->rating ?? 0),
                    'distance_km' => $distanceKm,
                    'text_score' => round($textScore, 4),
                    'distance_score' => round($distanceScore, 4),
                    'score' => round($score, 4),
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->values();

        return $scored->take($limit);
    }

    private function scoreText(Worker $worker, string $normalizedQuery): float
    {
        if ($normalizedQuery === '') {
            return 1.0;
        }

        $parts = array_values(array_filter(explode(' ', $normalizedQuery)));
        if (empty($parts)) {
            return 1.0;
        }

        $haystack = $this->normalize(implode(' ', [
            (string) ($worker->store_name ?? ''),
            (string) ($worker->user?->name ?? ''),
            (string) ($worker->user?->nickname ?? ''),
            (string) ($worker->title ?? ''),
            (string) ($worker->bio ?? ''),
            is_array($worker->skills) ? implode(' ', $worker->skills) : (string) ($worker->skills ?? ''),
        ]));

        if ($haystack === '') {
            return 0;
        }

        $hayTokens = array_values(array_filter(explode(' ', $haystack)));
        $matched = 0;
        $bonus = 0.0;

        foreach ($parts as $token) {
            if (str_contains($haystack, $token)) {
                $matched++;
                $bonus += 0.2;
                continue;
            }

            if ($this->hasApproximateToken($token, $hayTokens)) {
                $matched++;
                $bonus += 0.08;
            }
        }

        if ($matched === 0) {
            return 0;
        }

        $ratio = $matched / count($parts);
        return min(1.0, $ratio + $bonus);
    }

    private function hasApproximateToken(string $token, array $hayTokens): bool
    {
        foreach ($hayTokens as $candidate) {
            if ($candidate === $token) {
                return true;
            }
            if (abs(strlen($candidate) - strlen($token)) > 1) {
                continue;
            }
            if (strlen($token) >= 5 && levenshtein($token, $candidate) <= 1) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($ascii !== false) {
            $text = $ascii;
        }

        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text) ?? '';
        $text = preg_replace('/\s+/', ' ', $text) ?? '';

        $tokens = array_filter(explode(' ', trim($text)));
        $tokens = array_map(function (string $token) {
            if (strlen($token) > 4 && str_ends_with($token, 'es')) {
                return substr($token, 0, -2);
            }
            if (strlen($token) > 3 && str_ends_with($token, 's')) {
                return substr($token, 0, -1);
            }
            return $token;
        }, $tokens);

        return trim(implode(' ', $tokens));
    }
}
