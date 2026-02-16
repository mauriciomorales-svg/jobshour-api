<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Friendship;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FriendsController extends Controller
{
    /**
     * Generar código QR único para el usuario
     */
    public function generateQrCode(Request $request)
    {
        $user = $request->user();
        $worker = Worker::where('user_id', $user->id)->first();

        if (!$worker) {
            return response()->json(['message' => 'Worker not found'], 404);
        }

        // Generar QR único si no existe
        if (!$worker->qr_code) {
            $worker->qr_code = 'JH' . strtoupper(Str::random(8));
            $worker->save();
        }

        return response()->json([
            'qr_code' => $worker->qr_code,
            'qr_url' => url('/friend/qr/' . $worker->qr_code),
        ]);
    }

    /**
     * Buscar usuario por QR code y enviar solicitud
     */
    public function scanQrCode(Request $request, $qrCode)
    {
        $user = $request->user();
        
        $targetWorker = Worker::where('qr_code', $qrCode)->first();
        
        if (!$targetWorker) {
            return response()->json(['message' => 'QR no válido'], 404);
        }

        if ($targetWorker->user_id === $user->id) {
            return response()->json(['message' => 'No puedes agregarte a ti mismo'], 400);
        }

        // Verificar si ya existe relación
        $existing = Friendship::where(function($q) use ($user, $targetWorker) {
            $q->where('requester_id', $user->id)->where('addressee_id', $targetWorker->user_id);
        })->orWhere(function($q) use ($user, $targetWorker) {
            $q->where('requester_id', $targetWorker->user_id)->where('addressee_id', $user->id);
        })->first();

        if ($existing) {
            if ($existing->status === 'accepted') {
                return response()->json(['message' => 'Ya son amigos'], 200);
            }
            if ($existing->status === 'pending') {
                return response()->json(['message' => 'Solicitud pendiente'], 200);
            }
        }

        // Crear solicitud
        $friendship = Friendship::create([
            'requester_id' => $user->id,
            'addressee_id' => $targetWorker->user_id,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Solicitud enviada',
            'friendship' => $friendship,
        ]);
    }

    /**
     * Buscar por nickname
     */
    public function searchByNickname(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nickname' => 'required|string|min:3',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $workers = Worker::where('nickname', 'ILIKE', '%' . $request->nickname . '%')
            ->where('is_visible', true)
            ->with('user:id,name,email')
            ->limit(10)
            ->get();

        return response()->json(['workers' => $workers]);
    }

    /**
     * Enviar solicitud de amistad por ID
     */
    public function sendRequest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $targetUserId = $request->user_id;

        if ($targetUserId === $user->id) {
            return response()->json(['message' => 'No puedes agregarte a ti mismo'], 400);
        }

        // Verificar si el target es visible
        $targetWorker = Worker::where('user_id', $targetUserId)->first();
        if (!$targetWorker || !$targetWorker->is_visible) {
            return response()->json(['message' => 'Usuario no disponible'], 404);
        }

        // Verificar si ya existe
        $existing = Friendship::where(function($q) use ($user, $targetUserId) {
            $q->where('requester_id', $user->id)->where('addressee_id', $targetUserId);
        })->orWhere(function($q) use ($user, $targetUserId) {
            $q->where('requester_id', $targetUserId)->where('addressee_id', $user->id);
        })->first();

        if ($existing) {
            if ($existing->status === 'accepted') {
                return response()->json(['message' => 'Ya son amigos'], 200);
            }
            if ($existing->status === 'pending') {
                return response()->json(['message' => 'Solicitud pendiente'], 200);
            }
            if ($existing->status === 'blocked') {
                return response()->json(['message' => 'No se puede enviar solicitud'], 403);
            }
        }

        $friendship = Friendship::create([
            'requester_id' => $user->id,
            'addressee_id' => $targetUserId,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Solicitud enviada',
            'friendship' => $friendship,
        ]);
    }

    /**
     * Aceptar solicitud de amistad
     */
    public function acceptRequest(Request $request, $friendshipId)
    {
        $user = $request->user();
        
        $friendship = Friendship::where('id', $friendshipId)
            ->where('addressee_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if (!$friendship) {
            return response()->json(['message' => 'Solicitud no encontrada'], 404);
        }

        $friendship->status = 'accepted';
        $friendship->accepted_at = now();
        $friendship->save();

        return response()->json([
            'message' => 'Solicitud aceptada',
            'friendship' => $friendship,
        ]);
    }

    /**
     * Rechazar solicitud de amistad
     */
    public function rejectRequest(Request $request, $friendshipId)
    {
        $user = $request->user();
        
        $friendship = Friendship::where('id', $friendshipId)
            ->where('addressee_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if (!$friendship) {
            return response()->json(['message' => 'Solicitud no encontrada'], 404);
        }

        $friendship->delete();

        return response()->json(['message' => 'Solicitud rechazada']);
    }

    /**
     * Bloquear usuario
     */
    public function blockUser(Request $request, $friendshipId)
    {
        $user = $request->user();
        
        $friendship = Friendship::where('id', $friendshipId)
            ->where(function($q) use ($user) {
                $q->where('requester_id', $user->id)
                  ->orWhere('addressee_id', $user->id);
            })
            ->first();

        if (!$friendship) {
            return response()->json(['message' => 'Relación no encontrada'], 404);
        }

        $friendship->status = 'blocked';
        $friendship->save();

        return response()->json(['message' => 'Usuario bloqueado']);
    }

    /**
     * Listar amigos del usuario
     */
    public function listFriends(Request $request)
    {
        $user = $request->user();
        
        $friendships = Friendship::where(function($q) use ($user) {
            $q->where('requester_id', $user->id)
              ->orWhere('addressee_id', $user->id);
        })
        ->where('status', 'accepted')
        ->with(['requester.worker', 'addressee.worker'])
        ->get();

        $friends = $friendships->map(function($f) use ($user) {
            $friend = $f->requester_id === $user->id ? $f->addressee : $f->requester;
            return [
                'friendship_id' => $f->id,
                'user_id' => $friend->id,
                'name' => $friend->name,
                'nickname' => $friend->worker->nickname ?? null,
                'avatar_url' => $friend->worker->avatar_url ?? null,
                'skills' => $friend->worker->skills ?? [],
                'accepted_at' => $f->accepted_at,
            ];
        });

        return response()->json(['friends' => $friends]);
    }

    /**
     * Obtener solicitudes pendientes
     */
    public function pendingRequests(Request $request)
    {
        $user = $request->user();
        
        $requests = Friendship::where('addressee_id', $user->id)
            ->where('status', 'pending')
            ->with('requester:id,name')
            ->get();

        return response()->json(['requests' => $requests]);
    }

    /**
     * Sincronización de agenda de contactos
     */
    public function syncContacts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'contacts' => 'required|array',
            'contacts.*' => 'string', // teléfonos o emails
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $contacts = $request->contacts;
        
        $matches = [];
        $newFriends = [];

        foreach ($contacts as $contact) {
            // Buscar por email o teléfono
            $matchedUser = User::where('email', $contact)
                ->orWhereHas('worker', function($q) use ($contact) {
                    $q->where('phone', $contact);
                })
                ->first();

            if ($matchedUser && $matchedUser->id !== $user->id) {
                // Verificar si el matched user tiene is_visible = true
                $worker = Worker::where('user_id', $matchedUser->id)->first();
                if ($worker && $worker->is_visible) {
                    // Verificar match mutuo (si el otro también tiene al usuario en sus contactos)
                    $mutual = $this->checkMutualContact($user, $matchedUser);
                    
                    $matches[] = [
                        'user_id' => $matchedUser->id,
                        'name' => $matchedUser->name,
                        'nickname' => $worker->nickname ?? null,
                        'mutual' => $mutual,
                    ];

                    // Si hay match mutuo, crear amistad automáticamente
                    if ($mutual) {
                        $existing = Friendship::where(function($q) use ($user, $matchedUser) {
                            $q->where('requester_id', $user->id)
                              ->where('addressee_id', $matchedUser->id);
                        })->orWhere(function($q) use ($user, $matchedUser) {
                            $q->where('requester_id', $matchedUser->id)
                              ->where('addressee_id', $user->id);
                        })->first();

                        if (!$existing) {
                            $friendship = Friendship::create([
                                'requester_id' => $user->id,
                                'addressee_id' => $matchedUser->id,
                                'status' => 'accepted',
                                'accepted_at' => now(),
                            ]);
                            $newFriends[] = $matchedUser->name;
                        }
                    }
                }
            }
        }

        return response()->json([
            'matches_found' => count($matches),
            'matches' => $matches,
            'new_friends' => $newFriends,
        ]);
    }

    /**
     * Verificar si hay contacto mutuo
     */
    private function checkMutualContact($user, $targetUser)
    {
        // En una implementación real, esto verificaría si ambos usuarios
        // tienen registrados los contactos del otro en sus agendas
        // Por ahora, simplificamos: si ambos son visibles, es match
        $userWorker = Worker::where('user_id', $user->id)->first();
        $targetWorker = Worker::where('user_id', $targetUser->id)->first();
        
        return $userWorker && $targetWorker && 
               $userWorker->is_visible && $targetWorker->is_visible;
    }

    /**
     * Verificar si dos usuarios son amigos
     */
    public function checkFriendship(Request $request, $userId)
    {
        $user = $request->user();
        
        $friendship = Friendship::where(function($q) use ($user, $userId) {
            $q->where('requester_id', $user->id)->where('addressee_id', $userId);
        })->orWhere(function($q) use ($user, $userId) {
            $q->where('requester_id', $userId)->where('addressee_id', $user->id);
        })->where('status', 'accepted')->first();

        return response()->json([
            'are_friends' => !!$friendship,
            'friendship' => $friendship,
        ]);
    }
}
