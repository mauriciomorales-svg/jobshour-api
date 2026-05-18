<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use App\Services\FCMService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Recordatorios escalonados antes de que venza expires_at (ventana Aceptar/Rechazar).
 * Equivalente Uber: avisos a T-30s y T-10s.
 */
class SlaAcceptReminderCommand extends Command
{
    protected $signature = 'jobshour:sla-accept-reminders';

    protected $description = 'FCM al worker cuando faltan ~30s y ~10s para aceptar una solicitud pending';

    public function handle(FCMService $fcm): int
    {
        $pending = ServiceRequest::query()
            ->where('status', 'pending')
            ->whereNotNull('worker_id')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->with(['worker.user', 'client:id,name', 'category:id,display_name'])
            ->get();

        $sent = 0;

        foreach ($pending as $sr) {
            $workerUser = $sr->worker?->user;
            if (! $workerUser?->fcm_token) {
                continue;
            }

            $secondsLeft = (int) now()->diffInSeconds($sr->expires_at, false);
            if ($secondsLeft <= 0) {
                continue;
            }

            // Ventanas amplias: el cron corre cada minuto (no cada 15 s).
            if ($secondsLeft <= 55 && $secondsLeft > 15) {
                $sent += $this->maybeRemind($fcm, $workerUser, $sr, '30', $secondsLeft, 'Quedan pocos segundos', 'Pulsa Aceptar en Mis solicitudes o perderás este trabajo.');
            }

            if ($secondsLeft <= 15 && $secondsLeft > 0) {
                $sent += $this->maybeRemind($fcm, $workerUser, $sr, '10', $secondsLeft, 'Últimos segundos', 'Confirma ahora o la solicitud se cerrará sola.');
            }
        }

        if ($sent > 0) {
            Log::info('SlaAcceptReminder: sent', ['count' => $sent]);
        }

        return self::SUCCESS;
    }

    private function maybeRemind(
        FCMService $fcm,
        $workerUser,
        ServiceRequest $sr,
        string $tier,
        int $secondsLeft,
        string $title,
        string $body
    ): int {
        $cacheKey = "sla_accept_remind_{$tier}:{$sr->id}";
        if (Cache::has($cacheKey)) {
            return 0;
        }

        if (! $fcm->sendToUser($workerUser, $title, $body, [
            'type' => 'sla_accept_reminder',
            'tier' => $tier,
            'request_id' => (string) $sr->id,
            'seconds_left' => (string) $secondsLeft,
        ])) {
            return 0;
        }

        Cache::put($cacheKey, 1, now()->addMinutes(10));

        return 1;
    }
}
