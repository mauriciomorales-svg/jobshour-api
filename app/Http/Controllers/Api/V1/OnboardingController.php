<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OnboardingController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'hourly_rate' => 'required|numeric|min:3000|max:200000',
            'category_id' => 'required|exists:categories,id',
            'skills' => 'nullable|array',
            'skills.*' => 'string|max:100',
            'bio' => 'nullable|string|max:500',
        ]);

        $user = $request->user();

        try {
            $worker = DB::transaction(function () use ($user, $validated) {
                $worker = Worker::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'category_id' => $validated['category_id'],
                        'hourly_rate' => $validated['hourly_rate'],
                        'location' => DB::raw("ST_SetSRID(ST_MakePoint({$validated['longitude']}, {$validated['latitude']}), 4326)"),
                        'skills' => $validated['skills'] ?? [],
                        'bio' => $validated['bio'] ?? null,
                        'availability_status' => 'intermediate',
                    ]
                );

                // Sync category in pivot table
                if (!empty($validated['category_id'])) {
                    $worker->categories()->syncWithoutDetaching([$validated['category_id']]);
                }

                return $worker;
            });

            Log::info("Onboarding completed for user {$user->id}, worker {$worker->id}");

            return response()->json([
                'status' => 'success',
                'message' => '¡Perfil creado! Ya puedes ofrecer tus servicios.',
                'worker' => $worker->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error("Onboarding error for user {$user->id}: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear perfil. Intenta nuevamente.',
            ], 500);
        }
    }
}
