<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Nudge;

class NudgeController extends Controller
{
    public function random()
    {
        $nudge = Nudge::random();

        return response()->json([
            'status' => 'success',
            'data' => $nudge ? [
                'id' => $nudge->id,
                'message' => $nudge->message,
                'category' => $nudge->category,
            ] : null,
        ]);
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
