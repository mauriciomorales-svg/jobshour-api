<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MapController extends Controller
{
    public function nearbyWorkers(Request $request)
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|numeric|min:0.1|max:50',
            'skills' => 'nullable|array',
        ]);

        $radius = $validated['radius'] ?? 5;

        $query = Worker::available()
            ->with(['user:id,name,avatar'])
            ->near($validated['lat'], $validated['lng'], $radius);

        if (!empty($validated['skills'])) {
            $query->whereRaw('skills ?| array[?]', [$validated['skills']]);
        }

        $workers = $query->get([
            'id', 'user_id', 'title', 'skills', 'hourly_rate', 
            'availability_status', 'location', 'rating'
        ]);

        return response()->json([
            'center' => [
                'lat' => $validated['lat'],
                'lng' => $validated['lng'],
            ],
            'radius_km' => $radius,
            'count' => $workers->count(),
            'workers' => $workers->map(fn($w) => [
                'id' => $w->id,
                'name' => $w->user->name,
                'avatar' => $w->user->avatar,
                'title' => $w->title,
                'skills' => $w->skills,
                'hourly_rate' => $w->hourly_rate,
                'rating' => $w->rating,
                'location' => [
                    'lat' => $w->latitude,
                    'lng' => $w->longitude,
                ],
                'status' => $w->availability_status,
            ]),
        ]);
    }

    public function clusters(Request $request)
    {
        $validated = $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'zoom' => 'required|integer|between:1,20',
        ]);

        $radius = $this->zoomToRadius($validated['zoom']);

        $workers = Worker::available()
            ->near($validated['lat'], $validated['lng'], $radius)
            ->select('id', DB::raw('ST_X(location::geometry) as lng'), DB::raw('ST_Y(location::geometry) as lat'))
            ->get();

        $clusters = $this->clusterPoints($workers, $validated['zoom']);

        return response()->json([
            'clusters' => $clusters,
            'total_workers' => $workers->count(),
        ]);
    }

    private function zoomToRadius(int $zoom): float
    {
        return match(true) {
            $zoom >= 18 => 0.5,
            $zoom >= 15 => 1,
            $zoom >= 12 => 5,
            $zoom >= 10 => 10,
            $zoom >= 8 => 25,
            default => 50,
        };
    }

    private function clusterPoints($points, int $zoom): array
    {
        if ($zoom >= 16) {
            return $points->map(fn($p) => [
                'type' => 'point',
                'id' => $p->id,
                'lat' => (float) $p->lat,
                'lng' => (float) $p->lng,
            ])->toArray();
        }

        $gridSize = match(true) {
            $zoom >= 14 => 0.001,
            $zoom >= 11 => 0.01,
            $zoom >= 8 => 0.1,
            default => 0.5,
        };

        $clusters = [];
        foreach ($points as $point) {
            $gridX = floor($point->lng / $gridSize);
            $gridY = floor($point->lat / $gridSize);
            $key = "{$gridX}:{$gridY}";
            
            if (!isset($clusters[$key])) {
                $clusters[$key] = [
                    'type' => 'cluster',
                    'count' => 0,
                    'lat' => 0,
                    'lng' => 0,
                    'ids' => [],
                ];
            }
            $clusters[$key]['count']++;
            $clusters[$key]['lat'] += $point->lat;
            $clusters[$key]['lng'] += $point->lng;
            $clusters[$key]['ids'][] = $point->id;
        }

        return array_values(array_map(fn($c) => [
            'type' => 'cluster',
            'count' => $c['count'],
            'lat' => $c['lat'] / $c['count'],
            'lng' => $c['lng'] / $c['count'],
            'ids' => $c['ids'],
        ], $clusters));
    }
}
