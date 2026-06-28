<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\GeocodingService;
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

        $city = ($location && isset($location->lat, $location->lng))
            ? GeocodingService::getCityName((float) $location->lat, (float) $location->lng, 'Chile')
            : 'Chile';

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
}
