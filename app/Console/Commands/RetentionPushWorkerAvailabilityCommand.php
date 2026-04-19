<?php

namespace App\Console\Commands;

use App\Models\Worker;
use App\Services\FCMService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Mismo mensaje que WorkerAvailabilityBanner en la web.
 */
class RetentionPushWorkerAvailabilityCommand extends Command
{
    protected $signature = 'retention:push-worker-availability {--dry-run : Solo listar}';

    protected $description = 'FCM a trabajadores inactivos con al menos una categoría (cooldown por usuario)';

    public function handle(FCMService $fcm): int
    {
        $workers = Worker::query()
            ->where('availability_status', 'inactive')
            ->whereHas('categories')
            ->with('user')
            ->get();

        $title = '¿Listo para trabajar?';
        $body = 'Activa disponibilidad para salir en el mapa';
        $cooldownH = (int) config('services.retention.push_cooldown_hours', 24);
        $sent = 0;

        foreach ($workers as $worker) {
            $user = $worker->user;
            if (! $user || ! $user->fcm_token) {
                continue;
            }

            $cacheKey = 'retention_push_worker_avail:'.$user->id;
            if (Cache::has($cacheKey)) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("[dry-run] worker user {$user->id}");

                continue;
            }

            if ($fcm->sendToUser($user, $title, $body, ['type' => 'retention_worker_availability'])) {
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
