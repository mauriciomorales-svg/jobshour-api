<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WaitlistEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WaitlistController extends Controller
{
    /**
     * POST /api/v1/waitlist
     *
     * Registra o actualiza un email en la lista de espera.
     * Si el email ya existe, actualiza lat/lng y teléfono (upsert).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:191',
            'phone' => 'nullable|string|max:32',
            'lat'   => 'nullable|numeric|between:-90,90',
            'lng'   => 'nullable|numeric|between:-180,180',
        ]);

        // Upsert: si el email ya está, actualizamos coordenadas y teléfono
        $entry = WaitlistEntry::updateOrCreate(
            ['email' => $data['email']],
            [
                'phone' => $data['phone'] ?? null,
                'lat'   => $data['lat'] ?? null,
                'lng'   => $data['lng'] ?? null,
            ]
        );

        Log::info('[Waitlist] Nuevo registro', [
            'email' => $entry->email,
            'lat'   => $entry->lat,
            'lng'   => $entry->lng,
            'wasRecentlyCreated' => $entry->wasRecentlyCreated,
        ]);

        return response()->json([
            'status'  => 'ok',
            'message' => $entry->wasRecentlyCreated
                ? 'Te agregamos a la lista de espera. Te avisaremos cuando lleguemos a tu zona.'
                : 'Ya estás en nuestra lista. Actualizamos tu ubicación.',
        ]);
    }
}
