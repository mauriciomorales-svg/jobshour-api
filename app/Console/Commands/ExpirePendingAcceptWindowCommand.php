<?php

namespace App\Console\Commands;

use App\Events\ServiceRequestUpdated;
use App\Models\ServiceRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cancela solicitudes pending cuyo expires_at venció (ventana de aceptación del trabajador).
 * Evita que queden "colgadas" sin pasar por respond() — alineado con SLA tipo Uber.
 */
class ExpirePendingAcceptWindowCommand extends Command
{
    protected $signature = 'jobshour:expire-pending-accept';

    protected $description = 'Auto-cancela solicitudes pending con expires_at vencido (ventana aceptar/rechazar)';

    public function handle(): int
    {
        $ids = ServiceRequest::query()
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->pluck('id');

        $n = 0;
        foreach ($ids as $id) {
            $sr = ServiceRequest::query()->find($id);
            if (!$sr || $sr->status !== 'pending') {
                continue;
            }
            if (!$sr->expires_at || $sr->expires_at->gte(now())) {
                continue;
            }

            $sr->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => null,
                'cancellation_reason' => 'auto_expired_worker_accept_window',
            ]);

            try {
                $event = new ServiceRequestUpdated($sr->fresh());
                broadcast($event);
                $event->handle();
            } catch (\Throwable $e) {
                Log::warning('ExpirePendingAcceptWindow: broadcast/handle failed', [
                    'request_id' => $sr->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $n++;
        }

        if ($n > 0) {
            Log::info('ExpirePendingAcceptWindow: cancelled', ['count' => $n]);
        }

        $this->info("Expired pending accept windows: {$n}");

        return self::SUCCESS;
    }
}
