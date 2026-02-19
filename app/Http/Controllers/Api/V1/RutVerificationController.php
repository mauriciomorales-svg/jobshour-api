<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class RutVerificationController extends Controller
{
    /**
     * Verificar y guardar RUT del usuario
     */
    public function verify(Request $request)
    {
        $request->validate([
            'rut' => 'required|string|max:12',
        ]);

        $rut = $this->cleanRut($request->input('rut'));

        if (!$this->validateRut($rut)) {
            return response()->json([
                'status' => 'error',
                'message' => 'RUT inválido. Verifica el número e intenta nuevamente.',
            ], 422);
        }

        $formatted = $this->formatRut($rut);
        $user = $request->user();

        // Verificar que no esté registrado por otro usuario
        $existing = \App\Models\User::where('rut', $formatted)->where('id', '!=', $user->id)->first();
        if ($existing) {
            return response()->json([
                'status' => 'error',
                'message' => 'Este RUT ya está registrado en otra cuenta.',
            ], 409);
        }

        $user->update([
            'rut' => $formatted,
            'rut_verified' => true,
            'rut_verified_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'RUT verificado exitosamente.',
            'rut' => $formatted,
        ]);
    }

    /**
     * Obtener estado de verificación
     */
    public function status(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'rut' => $user->rut,
            'rut_verified' => (bool) $user->rut_verified,
            'rut_verified_at' => $user->rut_verified_at,
        ]);
    }

    /**
     * Limpiar RUT (quitar puntos y guión, dejar solo números y K)
     */
    private function cleanRut(string $rut): string
    {
        return strtoupper(preg_replace('/[^0-9kK]/', '', $rut));
    }

    /**
     * Formatear RUT: 12345678-9
     */
    private function formatRut(string $rut): string
    {
        $dv = substr($rut, -1);
        $body = substr($rut, 0, -1);
        return $body . '-' . $dv;
    }

    /**
     * Validar RUT chileno con módulo 11
     */
    private function validateRut(string $rut): bool
    {
        if (strlen($rut) < 2) return false;

        $dv = strtoupper(substr($rut, -1));
        $body = substr($rut, 0, -1);

        if (!ctype_digit($body)) return false;
        if ((int) $body < 1000000) return false; // RUT mínimo razonable

        $sum = 0;
        $multiplier = 2;

        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += (int) $body[$i] * $multiplier;
            $multiplier = $multiplier === 7 ? 2 : $multiplier + 1;
        }

        $remainder = 11 - ($sum % 11);
        $expected = match ($remainder) {
            11 => '0',
            10 => 'K',
            default => (string) $remainder,
        };

        return $dv === $expected;
    }
}
