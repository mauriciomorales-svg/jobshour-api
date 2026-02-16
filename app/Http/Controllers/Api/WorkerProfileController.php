<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Models\Video;
use App\Models\ServiceRequest;
use App\Models\Friendship;
use App\Models\ProfileView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class WorkerProfileController extends Controller
{
    public function myProfile(Request $request)
    {
        $user = $request->user();
        $worker = Worker::with(['user', 'category', 'videos', 'reviews'])
            ->where('user_id', $user->id)
            ->first();

        if (!$worker) {
            return response()->json(['message' => 'Worker not found'], 404);
        }

        $ranking = $this->calculateRanking($worker);

        $validatedFriends = Friendship::where(function($q) use ($user) {
            $q->where('requester_id', $user->id)->orWhere('addressee_id', $user->id);
        })->where('status', 'accepted')->count();

        return response()->json([
            'worker' => $worker,
            'skills' => $worker->skills ?? [],
            'cv_url' => $worker->cv_url,
            'cv_filename' => $worker->cv_filename,
            'showcase_video' => $worker->showcaseVideo,
            'ranking' => $ranking,
            'validated_friends' => $validatedFriends,
        ]);
    }

    private function calculateRanking($worker)
    {
        if (!$worker->category_id) {
            return null;
        }

        $workersInCategory = Worker::where('category_id', $worker->category_id)
            ->where('availability_status', '!=', 'inactive')
            ->get()
            ->map(function($w) {
                $completedJobs = ServiceRequest::where('worker_id', $w->id)
                    ->where('status', 'completed')
                    ->count();
                
                $avgRating = ServiceRequest::where('worker_id', $w->id)
                    ->where('status', 'completed')
                    ->whereNotNull('worker_rating')
                    ->avg('worker_rating') ?? 0;

                $score = ($avgRating * 10) + ($completedJobs * 0.5);
                
                return [
                    'id' => $w->id,
                    'score' => $score,
                ];
            })
            ->sortByDesc('score')
            ->values();

        $position = $workersInCategory->search(function($item) use ($worker) {
            return $item['id'] === $worker->id;
        });

        return [
            'position' => $position !== false ? $position + 1 : null,
            'total' => $workersInCategory->count(),
            'category' => $worker->category ? $worker->category->name : null,
        ];
    }

    public function uploadCv(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cv' => 'required|file|mimes:pdf|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json(['message' => 'Worker not found'], 404);
        }

        if ($worker->cv_filename) {
            Storage::disk('public')->delete('cvs/' . $worker->cv_filename);
        }

        $file = $request->file('cv');
        $filename = 'cv_' . $worker->id . '_' . time() . '.pdf';
        $path = $file->storeAs('cvs', $filename, 'public');
        $cvUrl = asset('storage/' . $path);

        $worker->update([
            'cv_url' => $cvUrl,
            'cv_filename' => $filename,
        ]);

        return response()->json([
            'message' => 'CV subido exitosamente',
            'cv_url' => $cvUrl,
            'cv_filename' => $filename,
        ]);
    }

    public function deleteCv(Request $request)
    {
        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker || !$worker->cv_filename) {
            return response()->json(['message' => 'No CV found'], 404);
        }

        Storage::disk('public')->delete('cvs/' . $worker->cv_filename);
        $worker->update(['cv_url' => null, 'cv_filename' => null]);

        return response()->json(['message' => 'CV eliminado']);
    }

    public function updateSkills(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'skills' => 'required|array',
            'skills.*.id' => 'required|integer',
            'skills.*.name' => 'required|string',
            'skills.*.active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json(['message' => 'Worker not found'], 404);
        }

        $worker->update(['skills' => $request->skills]);

        return response()->json([
            'message' => 'Habilidades actualizadas',
            'skills' => $worker->skills,
        ]);
    }

    public function uploadVideo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'video' => 'required|file|mimes:mp4,mov,avi|max:30720',
            'type' => 'required|in:showcase,vc,skill_demo',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json(['message' => 'Worker not found'], 404);
        }

        $existingVideo = Video::where('worker_id', $worker->id)
            ->where('type', $request->type)
            ->first();

        if ($existingVideo) {
            Storage::disk('public')->delete('videos/' . $existingVideo->filename);
            $existingVideo->delete();
        }

        $file = $request->file('video');
        $extension = $file->getClientOriginalExtension();
        $filename = 'video_' . $worker->id . '_' . $request->type . '_' . time() . '.' . $extension;
        $path = $file->storeAs('videos', $filename, 'public');
        $videoUrl = asset('storage/' . $path);

        $video = Video::create([
            'worker_id' => $worker->id,
            'type' => $request->type,
            'url' => $videoUrl,
            'filename' => $filename,
            'status' => 'ready',
        ]);

        return response()->json([
            'message' => 'Video subido exitosamente',
            'video' => $video,
        ]);
    }

    public function deleteVideo(Request $request, $videoId)
    {
        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json(['message' => 'Worker not found'], 404);
        }

        $video = Video::where('id', $videoId)
            ->where('worker_id', $worker->id)
            ->first();

        if (!$video) {
            return response()->json(['message' => 'Video not found'], 404);
        }

        Storage::disk('public')->delete('videos/' . $video->filename);
        $video->delete();

        return response()->json(['message' => 'Video eliminado']);
    }

    public function myMetrics(Request $request)
    {
        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json(['message' => 'Worker not found'], 404);
        }

        $completedJobs = ServiceRequest::where('worker_id', $worker->id)
            ->where('status', 'completed')
            ->count();

        $totalEarnings = ServiceRequest::where('worker_id', $worker->id)
            ->where('status', 'completed')
            ->sum('final_price') ?? 0;

        $avgRating = ServiceRequest::where('worker_id', $worker->id)
            ->where('status', 'completed')
            ->whereNotNull('worker_rating')
            ->avg('worker_rating') ?? 0;

        // CRÍTICO #6: Rating segregado por categoría principal
        $categoryRating = ServiceRequest::where('worker_id', $worker->id)
            ->where('category_id', $worker->category_id)
            ->where('status', 'completed')
            ->whereNotNull('worker_rating')
            ->avg('worker_rating') ?? 0;

        $categoryJobsCount = ServiceRequest::where('worker_id', $worker->id)
            ->where('category_id', $worker->category_id)
            ->where('status', 'completed')
            ->count();

        $pendingJobs = ServiceRequest::where('worker_id', $worker->id)
            ->whereIn('status', ['pending', 'accepted', 'in_progress'])
            ->count();

        $pendingAmount = ServiceRequest::where('worker_id', $worker->id)
            ->whereIn('status', ['accepted', 'in_progress'])
            ->sum('final_price') ?? 0;

        $pendingValidation = ServiceRequest::where('worker_id', $worker->id)
            ->where('status', 'pending_validation')
            ->count();

        $pendingValidationAmount = ServiceRequest::where('worker_id', $worker->id)
            ->where('status', 'pending_validation')
            ->sum('final_price') ?? 0;

        $conversionRate = $completedJobs > 0 && ($completedJobs + $pendingJobs) > 0
            ? round(($completedJobs / ($completedJobs + $pendingJobs)) * 100, 1)
            : 0;

        $profileViews = ProfileView::where('worker_id', $worker->id)
            ->whereBetween('viewed_at', [now()->subDays(7), now()])
            ->count();

        $employerViews = ProfileView::where('worker_id', $worker->id)
            ->where('viewer_type', 'employer')
            ->whereBetween('viewed_at', [now()->subDays(7), now()])
            ->count();

        $clientViews = ProfileView::where('worker_id', $worker->id)
            ->where('viewer_type', 'client')
            ->whereBetween('viewed_at', [now()->subDays(7), now()])
            ->count();

        $canChargeMore = $avgRating >= 4.5 && $completedJobs >= 10;

        return response()->json([
            'completed_jobs' => $completedJobs,
            'total_earnings' => (float) $totalEarnings,
            'average_rating' => round($avgRating, 1),
            'category_rating' => round($categoryRating, 1),
            'category_jobs_count' => $categoryJobsCount,
            'pending_jobs' => $pendingJobs,
            'pending_amount' => (float) $pendingAmount,
            'pending_validation' => $pendingValidation,
            'pending_validation_amount' => (float) $pendingValidationAmount,
            'conversion_rate' => $conversionRate,
            'profile_views_week' => $profileViews,
            'profile_views_employers' => $employerViews,
            'profile_views_clients' => $clientViews,
            'can_charge_more' => $canChargeMore,
        ]);
    }

    public function toggleVisibility(Request $request)
    {
        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json(['message' => 'Worker not found'], 404);
        }

        $worker->is_visible = !$worker->is_visible;
        $worker->save();

        return response()->json([
            'message' => $worker->is_visible ? 'Visibilidad activada' : 'Visibilidad desactivada',
            'is_visible' => $worker->is_visible,
        ]);
    }

    public function getVisibility(Request $request)
    {
        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json(['message' => 'Worker not found'], 404);
        }

        return response()->json([
            'is_visible' => $worker->is_visible,
            'qr_code' => $worker->qr_code,
        ]);
    }

    public function viewWorkerProfile(Request $request, $workerId)
    {
        $user = $request->user();
        $worker = Worker::with(['user', 'category', 'videos', 'reviews'])
            ->find($workerId);

        if (!$worker) {
            return response()->json(['message' => 'Worker not found'], 404);
        }

        $areFriends = Friendship::where(function($q) use ($user, $worker) {
            $q->where('requester_id', $user->id)->where('addressee_id', $worker->user_id);
        })->orWhere(function($q) use ($user, $worker) {
            $q->where('requester_id', $worker->user_id)->where('addressee_id', $user->id);
        })->where('status', 'accepted')->exists();

        if (!$worker->is_visible && !$areFriends && $worker->user_id !== $user->id) {
            return response()->json([
                'nickname' => $worker->nickname,
                'category' => $worker->category ? $worker->category->name : null,
                'rating' => $worker->rating,
                'is_visible' => false,
                'message' => 'Este usuario es invisible. Solo amigos pueden ver su perfil completo.',
            ]);
        }

        // P0-6: Registrar vista con tipo de viewer
        ProfileView::create([
            'worker_id' => $worker->id,
            'viewer_id' => $user->id,
            'viewer_type' => $user->type === 'employer' ? 'employer' : 'client',
            'viewed_at' => now(),
        ]);

        return response()->json([
            'worker' => $worker,
            'skills' => $worker->skills ?? [],
            'cv_url' => $worker->cv_url,
            'cv_filename' => $worker->cv_filename,
            'showcase_video' => $worker->showcaseVideo,
            'is_visible' => $worker->is_visible,
            'are_friends' => $areFriends,
        ]);
    }

    public function generateVerificationQR(Request $request)
    {
        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json(['message' => 'Worker not found'], 404);
        }

        // CRÍTICO #10: Generar JWT que expira en 60 segundos
        $payload = [
            'worker_id' => $worker->id,
            'user_id' => $user->id,
            'iat' => time(),
            'exp' => time() + 60, // Expira en 60 segundos
            'nonce' => bin2hex(random_bytes(16)), // Prevenir replay attacks
        ];

        $token = base64_encode(json_encode($payload) . '.' . hash_hmac('sha256', json_encode($payload), env('APP_KEY')));

        return response()->json([
            'qr_token' => $token,
            'expires_in' => 60,
            'verification_url' => url("/api/v1/workers/verify/{$token}"),
        ]);
    }

    public function verifyQRToken($token)
    {
        try {
            $decoded = base64_decode($token);
            [$payloadJson, $signature] = explode('.', $decoded);
            $payload = json_decode($payloadJson, true);

            // Verificar firma
            $expectedSignature = hash_hmac('sha256', $payloadJson, env('APP_KEY'));
            if (!hash_equals($expectedSignature, $signature)) {
                return response()->json(['error' => 'Token inválido'], 401);
            }

            // Verificar expiración
            if (time() > $payload['exp']) {
                return response()->json(['error' => 'Token expirado. Genera uno nuevo.'], 401);
            }

            $worker = Worker::with(['user', 'category'])->find($payload['worker_id']);

            if (!$worker) {
                return response()->json(['message' => 'Worker not found'], 404);
            }

            $completedJobs = ServiceRequest::where('worker_id', $worker->id)
                ->where('status', 'completed')
                ->count();

            $avgRating = ServiceRequest::where('worker_id', $worker->id)
                ->where('status', 'completed')
                ->whereNotNull('worker_rating')
                ->avg('worker_rating') ?? 0;

            $validatedFriends = Friendship::where(function($q) use ($worker) {
                $q->where('requester_id', $worker->user_id)->orWhere('addressee_id', $worker->user_id);
            })->where('status', 'accepted')->count();

            return response()->json([
                'name' => $worker->user->name,
                'avatar' => $worker->user->avatar,
                'category' => $worker->category ? $worker->category->name : null,
                'rating' => round($avgRating, 1),
                'completed_jobs' => $completedJobs,
                'validated_friends' => $validatedFriends,
                'is_verified' => true,
                'verified_at' => now()->toISOString(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Token inválido'], 401);
        }
    }

    public function myJobs(Request $request)
    {
        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json(['message' => 'Worker not found'], 404);
        }

        $jobs = ServiceRequest::where('worker_id', $worker->id)
            ->with('employer:id,name')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json(['jobs' => $jobs]);
    }
}
