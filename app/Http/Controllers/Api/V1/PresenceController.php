<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\WorkerPresenceUpdated;
use App\Events\DemandAlert;
use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Models\SearchLog;
use App\Services\GeocodingService;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    public function heartbeat(Request $request)
    {
        $validated = $request->validate([
            'worker_id' => 'required|exists:workers,id',
            'status' => 'required|in:active,intermediate,inactive',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ]);

        $worker = Worker::findOrFail($validated['worker_id']);
        $worker->update([
            'availability_status' => $validated['status'],
            'last_seen_at' => now(),
        ]);

        if (isset($validated['lat'], $validated['lng'])) {
            \Illuminate\Support\Facades\DB::statement(
                "UPDATE workers SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?",
                [$validated['lng'], $validated['lat'], $worker->id]
            );
        }

        broadcast(new WorkerPresenceUpdated(
            workerId: $worker->id,
            status: $validated['status'],
            lat: $worker->fuzzed_latitude,
            lng: $worker->fuzzed_longitude,
        ))->toOthers();

        return response()->json(['status' => 'ok']);
    }

    public function goOffline(Request $request)
    {
        $validated = $request->validate([
            'worker_id' => 'required|exists:workers,id',
        ]);

        $worker = Worker::findOrFail($validated['worker_id']);
        $worker->update([
            'availability_status' => 'inactive',
            'last_seen_at' => now(),
        ]);

        broadcast(new WorkerPresenceUpdated(
            workerId: $worker->id,
            status: 'inactive',
        ))->toOthers();

        return response()->json(['status' => 'inactive']);
    }

    public function checkStale()
    {
        // Active > 30min sin heartbeat → intermediate (semi-vivo)
        $staleActive = Worker::where('availability_status', 'active')
            ->where('last_seen_at', '<', now()->subMinutes(30))
            ->get();

        foreach ($staleActive as $worker) {
            $worker->update(['availability_status' => 'intermediate']);
            broadcast(new WorkerPresenceUpdated(
                workerId: $worker->id,
                status: 'intermediate',
            ));
        }

        // Intermediate > 60min sin heartbeat → inactive
        $staleIntermediate = Worker::where('availability_status', 'intermediate')
            ->where('last_seen_at', '<', now()->subMinutes(60))
            ->get();

        foreach ($staleIntermediate as $worker) {
            $worker->update(['availability_status' => 'inactive']);
            broadcast(new WorkerPresenceUpdated(
                workerId: $worker->id,
                status: 'inactive',
            ));
        }

        return response()->json([
            'status' => 'ok',
            'demoted_to_intermediate' => $staleActive->count(),
            'demoted_to_inactive' => $staleIntermediate->count(),
        ]);
    }

    public function demandAlert()
    {
        // Find recent searches with no results (last 30 min)
        $recentFailed = \Illuminate\Support\Facades\DB::select("
            SELECT category_requested, ST_Y(coords::geometry) as lat, ST_X(coords::geometry) as lng, COUNT(*) as cnt
            FROM search_logs
            WHERE created_at >= ? AND results_found = 0 AND coords IS NOT NULL
            GROUP BY category_requested, ST_Y(coords::geometry), ST_X(coords::geometry)
        ", [now()->subMinutes(30)]);

        if (empty($recentFailed)) {
            return response()->json(['status' => 'ok', 'alerts_sent' => 0]);
        }

        $alertsSent = 0;
        foreach ($recentFailed as $row) {
            $city = GeocodingService::getCityName((float) $row->lat, (float) $row->lng);
            $category = $row->category_requested
                ? \App\Models\Category::find($row->category_requested)?->display_name ?? 'servicios'
                : 'servicios';

            try {
                broadcast(new DemandAlert(
                    searchCount: (int) $row->cnt,
                    city: $city,
                    categoryName: $category,
                ));
                $alertsSent++;
            } catch (\Throwable $e) {
                // Silent fail
            }
        }

        return response()->json([
            'status' => 'ok',
            'alerts_sent' => $alertsSent,
        ]);
    }
}
