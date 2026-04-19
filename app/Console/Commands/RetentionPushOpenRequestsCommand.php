<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\FCMService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Mismo mensaje que el cintillo web OpenRequestsBanner (retención chat).
 */
class RetentionPushOpenRequestsCommand extends Command
{
    protected $signature = 'retention:push-open-requests {--dry-run : Solo listar destinatarios}';

    protected $description = 'Envía FCM a clientes con solicitudes pending/accepted/in_progress (cooldown por usuario)';

    public function handle(FCMService $fcm): int
    {
        $active = ['pending', 'accepted', 'in_progress'];

        $clientIds = ServiceRequest::query()
            ->whereIn('status', $active)
            ->distinct()
            ->pluck('client_id');

        $users = User::query()
            ->whereIn('id', $clientIds)
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '')
            ->get();

        $cooldownH = (int) config('services.retention.push_cooldown_hours', 24);
        $body = 'Revisa el chat para no perder el contacto';
        $sent = 0;

        foreach ($users as $user) {
            $count = ServiceRequest::query()
                ->where('client_id', $user->id)
                ->whereIn('status', $active)
                ->count();

            if ($count < 1) {
                continue;
            }

            $cacheKey = 'retention_push_open_req:'.$user->id;
            if (Cache::has($cacheKey)) {
                continue;
            }

            $title = $count === 1 ? 'Tienes 1 solicitud activa' : "Tienes {$count} solicitudes activas";

            if ($this->option('dry-run')) {
                $this->line("[dry-run] user {$user->id}: {$title}");

                continue;
            }

            if ($fcm->sendToUser($user, $title, $body, ['type' => 'retention_open_requests'])) {
                Cache::put($cacheKey, 1, now()->addHours($cooldownH));
                $sent++;
            }
        }

        $this->info($this->option('dry-run')
            ? 'Dry run complete.'
            : "FCM sent: {$sent} users.");

        return self::SUCCESS;
    }
}
