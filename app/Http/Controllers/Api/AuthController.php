<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|unique:users',
            'phone' => 'required|string|unique:users',
            'password' => 'required|string|min:8',
            'type' => 'required|in:worker,employer',
            'category_id' => 'required|exists:categories,id',
        ]);

        // Generar código de 6 dígitos
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(30);

        // Crear usuario pero NO verificado
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'type' => $validated['type'],
            'email_verification_code' => $code,
            'email_verification_expires_at' => $expiresAt,
        ]);

        if ($validated['type'] === 'worker') {
            $worker = Worker::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'category_id' => $validated['category_id'],
                    'hourly_rate' => 10000,
                    'availability_status' => 'intermediate',
                ]
            );
        }

        // Enviar email con código
        try {
            \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\EmailVerificationMail($user, $code));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("[Email] Verification mail failed: " . $e->getMessage());
        }

        return response()->json([
            'message' => 'Código de verificación enviado',
            'user_id' => $user->id,
            'email' => $user->email,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales incorrectas.'],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user->load('worker'),
            'token' => $token,
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $user->load('worker');
        
        $workerData = null;
        if ($user->worker) {
            $isActive = $user->worker->availability_status === 'active';
            
            // Determinar si el perfil está completo
            // Un perfil completo requiere: categoría, tarifa, y al menos avatar o bio
            $hasCategory = !empty($user->worker->category_id);
            $hasRate = !empty($user->worker->hourly_rate);
            $hasProfileData = !empty($user->worker->avatar) || !empty($user->worker->bio);
            $profileCompleted = $hasCategory && $hasRate && $hasProfileData;
            
            $workerData = [
                'id' => $user->worker->id,
                'is_active' => $isActive,
                'availability_status' => $user->worker->availability_status,
                'category_id' => $user->worker->category_id,
                'hourly_rate' => $user->worker->hourly_rate,
                'profile_completed' => $profileCompleted,
            ];
        }
        
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'worker' => $workerData,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada']);
    }

    public function verifyEmail(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'code' => 'required|string|size:6',
        ]);

        $user = User::findOrFail($request->user_id);

        // Verificar código
        if ($user->email_verification_code !== $request->code) {
            return response()->json(['message' => 'Código incorrecto'], 400);
        }

        // Verificar expiración
        if (now()->gt($user->email_verification_expires_at)) {
            return response()->json(['message' => 'Código expirado. Solicita uno nuevo.'], 400);
        }

        // Marcar como verificado
        $user->email_verified_at = now();
        $user->email_verification_code = null;
        $user->email_verification_expires_at = null;
        $user->save();

        // Crear token
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Email verificado correctamente',
            'user' => $user->load('worker'),
            'token' => $token,
        ]);
    }
}
