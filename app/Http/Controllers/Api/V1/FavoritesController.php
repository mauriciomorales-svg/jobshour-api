<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployerFavorite;
use App\Models\Worker;
use Illuminate\Http\Request;

class FavoritesController extends Controller
{
    public function addFavorite(Request $request)
    {
        $validated = $request->validate([
            'worker_id' => 'required|exists:workers,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $favorite = EmployerFavorite::updateOrCreate(
            [
                'employer_id' => $request->user()->id,
                'worker_id' => $validated['worker_id'],
            ],
            [
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Trabajador agregado a favoritos',
            'favorite' => $favorite,
        ]);
    }

    public function removeFavorite(Request $request, $workerId)
    {
        $deleted = EmployerFavorite::where('employer_id', $request->user()->id)
            ->where('worker_id', $workerId)
            ->delete();

        if (!$deleted) {
            return response()->json(['error' => 'Favorito no encontrado'], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Trabajador removido de favoritos',
        ]);
    }

    public function myFavorites(Request $request)
    {
        $favorites = EmployerFavorite::where('employer_id', $request->user()->id)
            ->with(['worker.user', 'worker.category'])
            ->get();

        return response()->json([
            'status' => 'success',
            'favorites' => $favorites,
        ]);
    }

    public function notifyFavorites($employerId, $serviceRequestId)
    {
        // Este método será llamado al crear un nuevo job
        // Envía notificación push 5 minutos antes que aparezca en el mapa
        
        $favorites = EmployerFavorite::where('employer_id', $employerId)
            ->with('worker.user')
            ->get();

        foreach ($favorites as $favorite) {
            // Aquí iría la lógica de push notification
            // Por ahora solo retornamos los IDs
        }

        return $favorites->pluck('worker_id')->toArray();
    }
}
