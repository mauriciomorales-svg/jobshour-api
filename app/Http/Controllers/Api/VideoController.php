<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VideoController extends Controller
{
    public function index(Request $request, Worker $worker)
    {
        $videos = $worker->videos()->ready()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'videos' => $videos->map(fn($v) => [
                'id' => $v->id,
                'title' => $v->title,
                'thumbnail_url' => $v->thumbnail_url,
                'video_url' => $v->video_url,
                'duration' => $v->duration,
                'views' => $v->view_count,
                'type' => $v->type,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'video' => 'required|file|mimetypes:video/mp4,video/quicktime|max:102400',
            'type' => 'nullable|in:profile,portfolio,review',
        ]);

        $worker = auth()->user()->worker;
        
        if (!$worker) {
            return response()->json(['error' => 'Not a worker'], 403);
        }

        $file = $request->file('video');
        $originalPath = Storage::disk('videos')->putFile('original', $file);

        $video = Video::create([
            'worker_id' => $worker->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'original_path' => $originalPath,
            'status' => 'pending',
            'type' => $validated['type'] ?? 'portfolio',
            'file_size' => $file->getSize(),
        ]);

        return response()->json([
            'id' => $video->id,
            'title' => $video->title,
            'status' => $video->status,
            'message' => 'Video uploaded successfully, processing started',
        ], 201);
    }

    public function show(Video $video)
    {
        if ($video->status !== 'ready') {
            return response()->json(['error' => 'Video not ready'], 404);
        }

        $video->increment('view_count');

        return response()->json([
            'id' => $video->id,
            'title' => $video->title,
            'description' => $video->description,
            'video_url' => $video->video_url,
            'thumbnail_url' => $video->thumbnail_url,
            'duration' => $video->duration,
            'views' => $video->view_count,
        ]);
    }

    public function destroy(Video $video)
    {
        if ($video->worker_id !== auth()->id() && $video->worker->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($video->original_path) {
            Storage::disk('videos')->delete($video->original_path);
        }
        if ($video->processed_path) {
            Storage::disk('videos')->delete($video->processed_path);
        }
        if ($video->thumbnail_path) {
            Storage::disk('videos')->delete($video->thumbnail_path);
        }

        $video->delete();

        return response()->json(['message' => 'Video deleted']);
    }

    public function myVideos(Request $request)
    {
        $worker = auth()->user()->worker;
        
        if (!$worker) {
            return response()->json(['error' => 'Not a worker'], 403);
        }

        $videos = $worker->videos()->latest()->get();

        return response()->json([
            'videos' => $videos->map(fn($v) => [
                'id' => $v->id,
                'title' => $v->title,
                'status' => $v->status,
                'thumbnail_url' => $v->thumbnail_url,
                'duration' => $v->duration,
                'views' => $v->view_count,
                'created_at' => $v->created_at,
            ]),
        ]);
    }
}
