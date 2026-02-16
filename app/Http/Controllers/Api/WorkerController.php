<?php

namespace App\Http\Controllers\Api;

use App\Events\WorkerAvailabilityChanged;
use App\Events\WorkerLocationUpdated;
use App\Http\Controllers\Controller;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkerController extends Controller
{
    public function index(Request $request)
    {
        $query = Worker::with(['user', 'videos'])
            ->where('is_verified', true);

        if ($request->has('skills')) {
            $skills = $request->input('skills');
            $query->whereRaw('skills ?| array[?]', [$skills]);
        }

        if ($request->has('available')) {
            $query->available();
        }

        return response()->json($query->paginate(20));
    }

    public function show(Worker $worker)
    {
        return response()->json($worker->load(['user', 'videos' => function ($q) {
            $q->where('status', 'ready')->orderBy('created_at', 'desc');
        }]));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:1000',
            'skills' => 'nullable|array',
            'skills.*' => 'string',
            'hourly_rate' => 'nullable|numeric|min:0',
            'service_area' => 'nullable|array',
        ]);

        $worker = $request->user()->worker;
        $worker->update($validated);

        return response()->json($worker->load('user'));
    }

    public function update(Request $request, Worker $worker)
    {
        if ($request->user()->id !== $worker->user_id) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:1000',
            'skills' => 'nullable|array',
            'skills.*' => 'string',
            'hourly_rate' => 'nullable|numeric|min:0',
            'service_area' => 'nullable|array',
        ]);

        $worker->update($validated);

        return response()->json($worker->load('user'));
    }

    public function updateAvailability(Request $request, Worker $worker)
    {
        if ($request->user()->id !== $worker->user_id) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:offline,available,busy',
        ]);

        $worker->update([
            'availability_status' => $validated['status'],
            'last_seen_at' => now(),
        ]);

        broadcast(new WorkerAvailabilityChanged($worker))->toOthers();

        return response()->json($worker);
    }

    public function updateLocation(Request $request, Worker $worker)
    {
        if ($request->user()->id !== $worker->user_id) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric',
        ]);

        $point = DB::raw("ST_GeogFromText('SRID=4326;POINT({$validated['longitude']} {$validated['latitude']})')");

        $worker->update([
            'location' => $point,
            'location_accuracy' => $validated['accuracy'] ?? null,
            'last_seen_at' => now(),
        ]);

        broadcast(new WorkerLocationUpdated($worker))->toOthers();

        return response()->json($worker);
    }

    public function videos(Worker $worker)
    {
        return response()->json(
            $worker->videos()
                ->where('status', 'ready')
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    public function destroy(Worker $worker)
    {
        return response()->json(['error' => 'No se puede eliminar un perfil de worker'], 400);
    }
}
