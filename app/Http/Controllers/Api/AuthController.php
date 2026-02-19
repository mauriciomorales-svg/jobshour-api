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

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'type' => $validated['type'],
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

        $token = $user->createToken('auth-token')->plainTextToken;

        // Email de bienvenida
        try {
            \Illuminate\Support\Facades\Mail::to($user->email)->queue(new \App\Mail\WelcomeMail($user));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("[Email] Welcome mail failed: " . $e->getMessage());
        }

        return response()->json([
            'user' => $user->load('worker'),
            'token' => $token,
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
}
