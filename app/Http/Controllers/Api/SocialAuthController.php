<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Auth;

class SocialAuthController extends Controller
{
    /**
     * Redirect to provider (Google)
     */
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    /**
     * Handle Google callback
     */
    public function handleGoogleCallback()
    {
        try {
            \Log::info('Google callback received');
            $socialUser = Socialite::driver('google')->stateless()->user();
            \Log::info('Google user data: ' . json_encode($socialUser));
            return $this->handleSocialLogin($socialUser, 'google');
        } catch (\Exception $e) {
            \Log::error('Google auth error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al autenticar con Google: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Redirect to provider (Facebook)
     */
    public function redirectToFacebook()
    {
        return Socialite::driver('facebook')->stateless()->redirect();
    }

    /**
     * Handle Facebook callback
     */
    public function handleFacebookCallback()
    {
        try {
            $socialUser = Socialite::driver('facebook')->stateless()->user();
            return $this->handleSocialLogin($socialUser, 'facebook');
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al autenticar con Facebook: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Handle mobile social login (token-based)
     */
    public function mobileLogin(Request $request, $provider)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        try {
            $socialUser = Socialite::driver($provider)->userFromToken($request->token);
            return $this->handleSocialLogin($socialUser, $provider);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token inválido: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Common logic to handle social login/create user
     */
    private function handleSocialLogin($socialUser, $provider)
    {
        // Buscar usuario por provider_id
        $user = User::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        // Si no existe, buscar por email
        if (!$user && $socialUser->getEmail()) {
            $user = User::where('email', $socialUser->getEmail())->first();
            
            // Si existe por email, actualizar provider info
            if ($user) {
                $user->update([
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                ]);
            }
        }

        // Crear nuevo usuario si no existe
        if (!$user) {
            $name = $socialUser->getName() ?? $socialUser->getNickname() ?? 'Usuario ' . ucfirst($provider);
            $email = $socialUser->getEmail();
            
            // Si no hay email, generar uno único
            if (!$email) {
                $email = $provider . '_' . $socialUser->getId() . '@jobshour.local';
            }

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'phone' => null, // Permitir nulo para login social
                'password' => Hash::make(Str::random(32)),
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'avatar' => $socialUser->getAvatar(),
                'avatar_url' => $socialUser->getAvatar(),
                'type' => 'employer', // Default type
                'is_active' => true,
            ]);
        }

        // Asegurar que el usuario tenga un Worker (para poder tomar demandas)
        if (!$user->worker) {
            Worker::create([
                'user_id' => $user->id,
                'availability_status' => 'intermediate',
            ]);
            \Log::info('Worker creado automáticamente para user: ' . $user->id);
        }

        // Generar token Sanctum
        $token = $user->createToken('social-login')->plainTextToken;

        // Redirigir al frontend con el token
        $frontendUrl = config('app.frontend_url', 'https://jobshour.dondemorales.cl');
        return redirect($frontendUrl . '?token=' . $token . '&login=success&provider=' . $provider);
    }
}
