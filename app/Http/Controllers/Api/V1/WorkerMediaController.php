<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WorkerMediaController extends Controller
{
    /**
     * Upload CV (PDF)
     */
    public function uploadCV(Request $request)
    {
        $request->validate([
            'cv' => 'required|file|mimes:pdf|max:5120', // 5MB max
        ]);

        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json([
                'status' => 'error',
                'message' => 'Debes activar tu perfil de trabajador primero',
            ], 404);
        }

        // Eliminar CV anterior si existe
        if ($worker->cv_path && Storage::disk('public')->exists($worker->cv_path)) {
            Storage::disk('public')->delete($worker->cv_path);
        }

        // Guardar nuevo CV
        $path = $request->file('cv')->store('cvs', 'public');
        $worker->cv_path = $path;
        $worker->save();

        return response()->json([
            'status' => 'success',
            'message' => 'CV subido exitosamente',
            'data' => [
                'cv_path' => $path,
                'cv_url' => Storage::disk('public')->url($path),
            ],
        ]);
    }

    /**
     * Upload Video Currículum (30 segundos)
     */
    public function uploadVideo(Request $request)
    {
        $request->validate([
            'video' => 'required|file|mimes:mp4,mov,webm|max:30720', // 30MB max
        ]);

        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json([
                'status' => 'error',
                'message' => 'Debes activar tu perfil de trabajador primero',
            ], 404);
        }

        // Eliminar video anterior si existe
        if ($worker->video_cv_path && Storage::disk('public')->exists($worker->video_cv_path)) {
            Storage::disk('public')->delete($worker->video_cv_path);
        }

        // Guardar nuevo video
        $path = $request->file('video')->store('videos/cv', 'public');
        
        // Obtener duración del video (opcional, requiere FFmpeg)
        $duration = null;
        try {
            $fullPath = Storage::disk('public')->path($path);
            if (function_exists('shell_exec')) {
                $output = shell_exec("ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"$fullPath\" 2>&1");
                $duration = $output ? (int) floatval(trim($output)) : null;
            }
        } catch (\Exception $e) {
            \Log::warning('Could not get video duration: ' . $e->getMessage());
        }

        $worker->video_cv_path = $path;
        $worker->video_cv_duration = $duration;
        $worker->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Video subido exitosamente',
            'data' => [
                'video_path' => $path,
                'video_url' => Storage::disk('public')->url($path),
                'duration' => $duration,
            ],
        ]);
    }

    /**
     * Delete CV
     */
    public function deleteCV(Request $request)
    {
        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker || !$worker->cv_path) {
            return response()->json([
                'status' => 'error',
                'message' => 'No hay CV para eliminar',
            ], 404);
        }

        if (Storage::disk('public')->exists($worker->cv_path)) {
            Storage::disk('public')->delete($worker->cv_path);
        }

        $worker->cv_path = null;
        $worker->save();

        return response()->json([
            'status' => 'success',
            'message' => 'CV eliminado exitosamente',
        ]);
    }

    /**
     * Delete Video
     */
    public function deleteVideo(Request $request)
    {
        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker || !$worker->video_cv_path) {
            return response()->json([
                'status' => 'error',
                'message' => 'No hay video para eliminar',
            ], 404);
        }

        if (Storage::disk('public')->exists($worker->video_cv_path)) {
            Storage::disk('public')->delete($worker->video_cv_path);
        }

        $worker->video_cv_path = null;
        $worker->video_cv_duration = null;
        $worker->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Video eliminado exitosamente',
        ]);
    }

    /**
     * Update worker categories
     */
    public function updateCategories(Request $request)
    {
        $request->validate([
            'categories' => 'required|array',
            'categories.*' => 'exists:categories,id',
        ]);

        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json([
                'status' => 'error',
                'message' => 'Debes activar tu perfil de trabajador primero',
            ], 404);
        }

        // Sincronizar categorías
        $worker->categories()->sync($request->categories);

        // Actualizar categoría principal (primera del array)
        if (!empty($request->categories)) {
            $worker->category_id = $request->categories[0];
            $worker->save();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Habilidades actualizadas exitosamente',
            'data' => [
                'categories' => $request->categories,
            ],
        ]);
    }

    /**
     * Get current worker data
     */
    public function getWorkerData(Request $request)
    {
        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)
            ->with(['categories', 'category'])
            ->first();

        if (!$worker) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes perfil de trabajador',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $worker->id,
                'cv_path' => $worker->cv_path,
                'video_cv_path' => $worker->video_cv_path,
                'video_cv_duration' => $worker->video_cv_duration,
                'categories' => $worker->categories->map(fn($cat) => [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'display_name' => $cat->display_name,
                    'icon' => $cat->icon,
                    'color' => $cat->color,
                ]),
            ],
        ]);
    }
}
