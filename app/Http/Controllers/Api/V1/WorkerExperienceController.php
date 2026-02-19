<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WorkerExperience;
use App\Models\ExperienceSuggestion;
use Illuminate\Http\Request;

class WorkerExperienceController extends Controller
{
    public function searchSuggestions(Request $request)
    {
        $query = $request->input('q', '');
        
        $suggestions = ExperienceSuggestion::where('title', 'ILIKE', "%{$query}%")
            ->orWhere('category', 'ILIKE', "%{$query}%")
            ->orderBy('title')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $suggestions,
        ]);
    }

    public function index(Request $request)
    {
        $worker = $request->user()->worker;
        
        if (!$worker) {
            return response()->json(['success' => false, 'message' => 'Worker not found'], 404);
        }

        $experiences = $worker->experiences()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $experiences,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'years' => 'nullable|integer|min:0|max:50',
        ]);

        $worker = $request->user()->worker;
        
        if (!$worker) {
            return response()->json(['success' => false, 'message' => 'Worker not found'], 404);
        }

        $experience = $worker->experiences()->create($validated);

        return response()->json([
            'success' => true,
            'data' => $experience,
        ], 201);
    }

    public function update(Request $request, WorkerExperience $experience)
    {
        $worker = $request->user()->worker;
        
        if (!$worker || $experience->worker_id !== $worker->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:500',
            'years' => 'nullable|integer|min:0|max:50',
        ]);

        $experience->update($validated);

        return response()->json([
            'success' => true,
            'data' => $experience,
        ]);
    }

    public function destroy(Request $request, WorkerExperience $experience)
    {
        $worker = $request->user()->worker;
        
        if (!$worker || $experience->worker_id !== $worker->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $experience->delete();

        return response()->json([
            'success' => true,
            'message' => 'Experience deleted',
        ]);
    }

    public function updateBioTarjeta(Request $request)
    {
        $validated = $request->validate([
            'bio_tarjeta' => 'nullable|string|max:150',
        ]);

        $worker = $request->user()->worker;
        
        if (!$worker) {
            return response()->json(['success' => false, 'message' => 'Worker not found'], 404);
        }

        $worker->update(['bio_tarjeta' => $validated['bio_tarjeta']]);

        return response()->json([
            'success' => true,
            'data' => $worker,
        ]);
    }
}
