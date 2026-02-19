<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContactReveal;
use App\Models\Worker;
use Illuminate\Http\Request;

class ContactRevealController extends Controller
{
    /**
     * POST /api/v1/contact/reveal
     * Reveal a worker's phone number. Costs 1 credit (free for pioneers).
     */
    public function reveal(Request $request)
    {
        $validated = $request->validate([
            'worker_id' => 'required|integer|exists:workers,id',
        ]);

        $user = $request->user();
        $workerId = $validated['worker_id'];

        // Check if already revealed (idempotent)
        $existing = ContactReveal::where('user_id', $user->id)
            ->where('worker_id', $workerId)
            ->first();

        if ($existing) {
            return $this->returnPhone($workerId);
        }

        // Pioneer = free, otherwise check credits
        $wasFree = (bool) $user->is_pioneer;

        if (!$wasFree) {
            if ($user->credits_balance <= 0) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'NO_CREDITS',
                    'message' => 'No tienes créditos disponibles. Adquiere un plan para ver contactos.',
                ], 402);
            }
            $user->decrement('credits_balance');
        }

        ContactReveal::create([
            'user_id' => $user->id,
            'worker_id' => $workerId,
            'was_free' => $wasFree,
        ]);

        return $this->returnPhone($workerId);
    }

    /**
     * GET /api/v1/contact/check/{workerId}
     * Check if user has already revealed this worker's contact.
     */
    public function check(Request $request, int $workerId)
    {
        $revealed = ContactReveal::where('user_id', $request->user()->id)
            ->where('worker_id', $workerId)
            ->exists();

        return response()->json([
            'revealed' => $revealed,
        ]);
    }

    private function returnPhone(int $workerId)
    {
        $worker = Worker::with('user:id,phone')->findOrFail($workerId);

        return response()->json([
            'status' => 'success',
            'phone' => $worker->user->phone,
        ]);
    }
}
