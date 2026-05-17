<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FCMService
{
    public function __construct(
        private FirebaseService $firebase,
    ) {}

    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        if (!$user->fcm_token) {
            Log::debug("[FCM] User {$user->id} has no FCM token");

            return false;
        }

        return $this->firebase->sendToDevice($user->fcm_token, $title, $body, $data);
    }

    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): int
    {
        $tokens = User::whereIn('id', $userIds)
            ->whereNotNull('fcm_token')
            ->pluck('fcm_token')
            ->toArray();

        $sent = 0;
        foreach ($tokens as $token) {
            if ($this->firebase->sendToDevice($token, $title, $body, $data)) {
                $sent++;
            }
        }

        Log::info('[FCM] Sent to '.$sent.'/'.count($tokens).' users');

        return $sent;
    }

    public function sendToNearbyWorkers(float $lat, float $lng, float $radiusKm, string $title, string $body, array $data = [], ?int $excludeUserId = null): int
    {
        $query = DB::select("
            SELECT DISTINCT u.fcm_token
            FROM workers w
            JOIN users u ON u.id = w.user_id
            WHERE w.availability_status IN ('active', 'intermediate')
              AND u.fcm_token IS NOT NULL
              AND u.fcm_token != ''
              AND w.location IS NOT NULL
              AND ST_DWithin(
                  w.location::geography,
                  ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                  ?
              )
              ".($excludeUserId ? 'AND u.id != ?' : '').'
        ', $excludeUserId
            ? [$lng, $lat, $radiusKm * 1000, $excludeUserId]
            : [$lng, $lat, $radiusKm * 1000]
        );

        $sent = 0;
        foreach ($query as $row) {
            if ($this->firebase->sendToDevice($row->fcm_token, $title, $body, $data)) {
                $sent++;
            }
        }

        Log::info("[FCM] Sent to {$sent} nearby workers within {$radiusKm}km");

        return $sent;
    }
}
