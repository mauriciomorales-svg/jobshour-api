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
    public function redirectToGoogle(Request $request)
    {
        $isMobile = $request->has('mobile');

        if ($isMobile) {
            $callbackUrl = 'https://jobshour.dondemorales.cl/api/auth/google/callback/mobile';
            return Socialite::driver('google')
                ->stateless()
                ->redirectUrl($callbackUrl)
                ->with(['prompt' => 'select_account'])
                ->redirect();
        }

        return Socialite::driver('google')
            ->stateless()
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    /**
     * Handle Google callback
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            $socialUser = Socialite::driver('google')->stateless()->user();
            return $this->handleSocialLogin($socialUser, 'google', false);
        } catch (\Exception $e) {
            \Log::error('Google auth error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al autenticar con Google: ' . $e->getMessage()
            ], 400);
        }
    }

    public function handleGoogleCallbackMobile(Request $request)
    {
        try {
            $callbackUrl = 'https://jobshour.dondemorales.cl/api/auth/google/callback/mobile';
            $socialUser = Socialite::driver('google')
                ->stateless()
                ->redirectUrl($callbackUrl)
                ->user();
            return $this->handleSocialLogin($socialUser, 'google', true);
        } catch (\Exception $e) {
            \Log::error('Google mobile auth error: ' . $e->getMessage());
            $frontendUrl = 'https://jobshour.dondemorales.cl';
            return redirect($frontendUrl . '/auth/callback?error=' . urlencode($e->getMessage()));
        }
    }

    /**
     * Redirect to provider (Facebook)
     */
    public function redirectToFacebook(Request $request)
    {
        $state = $request->has('mobile') ? 'mobile_app' : 'web_app';
        
        return Socialite::driver('facebook')
            ->stateless()
            ->with(['state' => $state])
            ->redirect();
    }

    /**
     * Handle Facebook callback
     */
    public function handleFacebookCallback(Request $request)
    {
        try {
            $isMobile = $request->get('state') === 'mobile_app';
            $socialUser = Socialite::driver('facebook')->stateless()->user();
            return $this->handleSocialLogin($socialUser, 'facebook', $isMobile);
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
    private function handleSocialLogin($socialUser, $provider, $isMobile = false)
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
                    'avatar' => $socialUser->getAvatar() ?? $user->avatar,
                    'avatar_url' => $socialUser->getAvatar() ?? $user->avatar_url,
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
                'availability_status' => 'inactive',
            ]);
            \Log::info('Worker creado automáticamente para user: ' . $user->id);
        }

        // Generar token Sanctum
        $token = $user->createToken('social-login')->plainTextToken;

        if ($isMobile) {
            $userData = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'avatarUrl' => $user->avatar_url,
                'type' => $user->type,
            ];
            // Guardar token en caché por 5 minutos para que la APK lo recupere
            $authKey = 'mobile_auth_' . md5($token);
            \Cache::put($authKey, ['token' => $token, 'user' => $userData], 300);
            // Redirigir a página de éxito en el frontend - la APK detecta esto via browserFinished
            $frontendUrl = 'https://jobshour.dondemorales.cl';
            return redirect($frontendUrl . '/auth/mobile-success?key=' . $authKey);
        }

        // Redirigir al frontend web con el token
        $frontendUrl = config('app.frontend_url', 'https://jobshour.dondemorales.cl');
        return redirect($frontendUrl . '?token=' . $token . '&login=success&provider=' . $provider);
    }

    public function getMobileToken(\Illuminate\Http\Request $request)
    {
        $key = $request->get('key');
        if (!$key) return response()->json(['error' => 'No key'], 400);
        $data = \Cache::get($key);
        if (!$data) return response()->json(['error' => 'Token expired or not found'], 404);
        \Cache::forget($key);
        return response()->json(['token' => $data['token'], 'user' => $data['user']]);
    }
}
