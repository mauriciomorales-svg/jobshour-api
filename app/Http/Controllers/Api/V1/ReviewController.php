<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\ServiceRequest;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReviewController extends Controller
{
    /**
     * Crear una reseña después de completar un servicio
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'service_request_id' => 'required|exists:service_requests,id',
                'stars' => 'required|integer|min:1|max:5',
                'comment' => 'nullable|string|max:500',
            ]);

            $user = $request->user();
            $serviceRequest = ServiceRequest::with('worker')->findOrFail($validated['service_request_id']);

            // Validar que el servicio esté completado
            if ($serviceRequest->status !== 'completed') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solo puedes calificar servicios completados'
                ], 422);
            }

            // Validar que el usuario sea el cliente
            if ($serviceRequest->client_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solo el cliente puede calificar este servicio'
                ], 403);
            }

            // Validar que no haya calificado antes
            $existingReview = Review::where('service_request_id', $serviceRequest->id)
                ->where('reviewer_id', $user->id)
                ->first();

            if ($existingReview) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ya calificaste este servicio'
                ], 422);
            }

            // Validar que el worker existe
            if (!$serviceRequest->worker) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Trabajador no encontrado'
                ], 404);
            }

            $review = null;
            DB::transaction(function() use ($validated, $serviceRequest, $user, &$review) {
                // Crear la reseña
                $review = Review::create([
                    'worker_id' => $serviceRequest->worker_id,
                    'reviewer_id' => $user->id,
                    'service_request_id' => $serviceRequest->id,
                    'stars' => $validated['stars'],
                    'comment' => $validated['comment'] ?? null,
                ]);

                // Actualizar rating del worker (Fresh Score: promedio de últimas 10 reseñas)
                $recentReviews = Review::where('worker_id', $serviceRequest->worker_id)
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get();

                $avgRating = $recentReviews->avg('stars');
                $ratingCount = $recentReviews->count();

                $worker = Worker::find($serviceRequest->worker_id);
                if ($worker) {
                    $worker->update([
                        'rating' => round($avgRating, 1),
                        'rating_count' => $ratingCount,
                    ]);
                }
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Reseña creada exitosamente',
                'data' => $review->load(['reviewer:id,name,avatar']),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('ReviewController::store - Error crítico', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al crear reseña. Por favor intenta nuevamente.'
            ], 500);
        }
    }

    /**
     * Obtener reseñas de un worker
     */
    public function index(Request $request, Worker $worker)
    {
        try {
            $reviews = Review::where('worker_id', $worker->id)
                ->with(['reviewer:id,name,avatar'])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(function($review) {
                    return [
                        'id' => $review->id,
                        'stars' => $review->stars,
                        'comment' => $review->comment,
                        'reviewer' => [
                            'name' => $review->reviewer->name ?? 'Anónimo',
                            'avatar' => $review->reviewer->avatar ?? null,
                        ],
                        'created_at' => $review->created_at->diffForHumans(),
                        'response' => $review->response,
                        'responded_at' => $review->responded_at ? $review->responded_at->diffForHumans() : null,
                    ];
                });

            return response()->json([
                'status' => 'success',
                'data' => $reviews,
            ]);
        } catch (\Exception $e) {
            Log::error('ReviewController::index - Error', [
                'error' => $e->getMessage(),
                'worker_id' => $worker->id ?? null
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cargar reseñas',
                'data' => []
            ], 500);
        }
    }

    /**
     * Responder a una reseña (solo el worker)
     */
    public function respond(Request $request, Review $review)
    {
        try {
            $validated = $request->validate([
                'response' => 'required|string|min:10|max:500',
            ]);

            $user = $request->user();
            $worker = Worker::where('user_id', $user->id)->first();

            if (!$worker) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Debes ser un trabajador para responder reseñas'
                ], 403);
            }

            // Validar que la reseña sea para este worker
            if ($review->worker_id !== $worker->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No puedes responder esta reseña'
                ], 403);
            }

            // Validar que no haya respondido antes
            if ($review->response) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ya respondiste esta reseña'
                ], 422);
            }

            $review->update([
                'response' => $validated['response'],
                'responded_at' => now(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Respuesta publicada exitosamente',
                'data' => $review->load(['reviewer:id,name,avatar']),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('ReviewController::respond - Error crítico', [
                'error' => $e->getMessage(),
                'review_id' => $review->id ?? null,
                'user_id' => $request->user()?->id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al responder reseña. Por favor intenta nuevamente.'
            ], 500);
        }
    }
}
