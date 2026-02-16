<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function index(Request $request)
    {
        $query = Job::with(['employer', 'worker'])->open();

        if ($request->has('lat') && $request->has('lng')) {
            $query->near(
                $request->input('lat'),
                $request->input('lng'),
                $request->input('radius', 10)
            );
        }

        if ($request->has('skills')) {
            $skills = $request->input('skills');
            $query->whereRaw('skills_required ?| array[?]', [$skills]);
        }

        return response()->json($query->byUrgency()->paginate(20));
    }

    public function show(Job $job)
    {
        return response()->json($job->load(['employer', 'worker']));
    }

    public function store(Request $request)
    {
        if (!$request->user()->isEmployer()) {
            return response()->json(['error' => 'Solo empleadores pueden crear trabajos'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:2000',
            'skills_required' => 'nullable|array',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'address' => 'nullable|string|max:500',
            'budget' => 'nullable|numeric|min:0',
            'payment_type' => 'required|in:hourly,fixed,negotiable',
            'urgency' => 'required|in:low,medium,high,urgent',
            'scheduled_at' => 'nullable|date',
            'estimated_duration_minutes' => 'nullable|integer|min:15',
        ]);

        $job = Job::create([
            'employer_id' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'skills_required' => $validated['skills_required'] ?? [],
            'location' => DB::raw("ST_GeogFromText('SRID=4326;POINT({$validated['longitude']} {$validated['latitude']})')"),
            'address' => $validated['address'] ?? null,
            'budget' => $validated['budget'] ?? null,
            'payment_type' => $validated['payment_type'],
            'urgency' => $validated['urgency'],
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'estimated_duration_minutes' => $validated['estimated_duration_minutes'] ?? null,
        ]);

        return response()->json($job->load(['employer']), 201);
    }

    public function update(Request $request, Job $job)
    {
        if ($request->user()->id !== $job->employer_id) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'skills_required' => 'nullable|array',
            'budget' => 'nullable|numeric|min:0',
            'urgency' => 'nullable|in:low,medium,high,urgent',
            'status' => 'nullable|in:open,assigned,in_progress,completed,cancelled',
        ]);

        $job->update($validated);

        return response()->json($job->load(['employer', 'worker']));
    }

    public function apply(Request $request, Job $job)
    {
        if (!$request->user()->isWorker()) {
            return response()->json(['error' => 'Solo workers pueden aplicar'], 403);
        }

        if ($job->status !== 'open') {
            return response()->json(['error' => 'Este trabajo ya no está disponible'], 400);
        }

        $worker = $request->user()->worker;

        $job->update([
            'worker_id' => $worker->id,
            'status' => 'assigned',
        ]);

        return response()->json($job->load(['employer', 'worker']));
    }

    public function cancel(Request $request, Job $job)
    {
        if ($request->user()->id !== $job->employer_id && 
            $request->user()->id !== ($job->worker->user_id ?? null)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $job->update([
            'status' => 'cancelled',
            'worker_id' => null,
        ]);

        return response()->json($job->load(['employer', 'worker']));
    }

    public function destroy(Job $job)
    {
        return response()->json(['error' => 'Los trabajos no se eliminan, se cancelan'], 400);
    }
}
