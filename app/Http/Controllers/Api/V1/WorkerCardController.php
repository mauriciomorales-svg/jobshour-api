<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkerCardController extends Controller
{
    public function getCardData(Request $request)
    {
        $worker = $request->user()->worker;
        
        if (!$worker) {
            return response()->json(['success' => false, 'message' => 'Worker not found'], 404);
        }

        $worker->load(['user', 'categories', 'experiences']);

        $location = DB::selectOne(
            "SELECT ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng 
             FROM workers WHERE id = ?",
            [$worker->id]
        );

        $city = $this->getCityFromLocation($location);

        $cardData = [
            'id' => $worker->id,
            'name' => $worker->user->name,
            'avatar' => $worker->user->avatar,
            'bio_tarjeta' => $worker->bio_tarjeta,
            'city' => $city,
            'categories' => $worker->categories->map(function ($cat) {
                return [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'icon' => $cat->icon,
                    'color' => $cat->color,
                ];
            }),
            'experiences' => $worker->experiences->map(function ($exp) {
                return [
                    'id' => $exp->id,
                    'title' => $exp->title,
                    'description' => $exp->description,
                    'years' => $exp->years,
                ];
            }),
            'total_jobs' => $worker->total_jobs_completed ?? 0,
            'rating' => $worker->rating ?? 0,
            'rating_count' => $worker->rating_count ?? 0,
            'is_verified' => $worker->is_verified ?? false,
            'profile_url' => config('app.frontend_url') . '/perfil/' . $worker->id,
        ];

        return response()->json([
            'success' => true,
            'data' => $cardData,
        ]);
    }

    private function getCityFromLocation($location)
    {
        if (!$location || !isset($location->lat, $location->lng)) {
            return 'Chile';
        }

        $cities = [
            ['name' => 'Renaico', 'lat' => -37.6667, 'lng' => -72.5833],
            ['name' => 'Angol', 'lat' => -37.8000, 'lng' => -72.7167],
            ['name' => 'Collipulli', 'lat' => -37.9500, 'lng' => -72.4333],
            ['name' => 'Victoria', 'lat' => -38.2333, 'lng' => -72.3333],
            ['name' => 'Temuco', 'lat' => -38.7333, 'lng' => -72.6000],
        ];

        $closestCity = 'Chile';
        $minDistance = PHP_FLOAT_MAX;

        foreach ($cities as $city) {
            $distance = $this->haversineDistance(
                $location->lat,
                $location->lng,
                $city['lat'],
                $city['lng']
            );

            if ($distance < $minDistance) {
                $minDistance = $distance;
                $closestCity = $city['name'];
            }
        }

        return $minDistance < 50 ? $closestCity : 'Chile';
    }

    private function haversineDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }
}
