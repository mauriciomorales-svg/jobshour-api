<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function testWorkers()
    {
        try {
            $workers = Worker::with('user:id,name,avatar')
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->limit(10)
                ->get(['id', 'user_id', 'title', 'hourly_rate', 'latitude', 'longitude', 'availability_status']);
            
            return response()->json([
                'status' => 'success',
                'count' => $workers->count(),
                'data' => $workers->map(fn($w) => [
                    'id' => $w->id,
                    'name' => $w->user?->name ?? 'Unknown',
                    'title' => $w->title,
                    'rate' => $w->hourly_rate,
                    'lat' => $w->latitude,
                    'lng' => $w->longitude,
                    'status' => $w->availability_status,
                ])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ], 500);
        }
    }
}
