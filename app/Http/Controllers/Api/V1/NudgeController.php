<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Nudge;
use Illuminate\Support\Facades\Log;

class NudgeController extends Controller
{
    public function random()
    {
        try {
            Log::info('NudgeController::random called');
            
            $nudge = Nudge::random();

            if (!$nudge) {
                Log::info('No nudges found, returning default');
                // Retornar un nudge por defecto si no hay en BD
                return response()->json([
                    'message' => '¡Encuentra el experto perfecto cerca de ti!',
                    'id' => 0,
                    'category' => 'top',
                ]);
            }

            Log::info('Nudge found', ['id' => $nudge->id]);
            
            return response()->json([
                'message' => $nudge->message,
                'id' => $nudge->id,
                'category' => $nudge->category,
            ]);
        } catch (\Exception $e) {
            Log::error('NudgeController error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Retornar mensaje por defecto en caso de error
            return response()->json([
                'message' => '¡Encuentra el experto perfecto cerca de ti!',
                'id' => 0,
                'category' => 'top',
            ]);
        }
    }

    public function index()
    {
        $nudges = Nudge::active()
            ->orderByDesc('weight')
            ->get(['id', 'message', 'category', 'weight']);

        return response()->json([
            'status' => 'success',
            'data' => $nudges,
        ]);
    }
}
