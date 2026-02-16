<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use App\Models\Worker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckInactiveJobs extends Command
{
    protected $signature = 'jobs:check-inactive';
    protected $description = 'Auto-cancel jobs with 30+ minutes of inactivity';

    public function handle()
    {
        $threshold = now()->subMinutes(30);

        // Buscar trabajos aceptados sin actividad reciente
        $inactiveJobs = ServiceRequest::whereIn('status', ['accepted', 'in_progress'])
            ->where(function($query) use ($threshold) {
                $query->where('last_activity_at', '<', $threshold)
                      ->orWhere(function($q) use ($threshold) {
                          $q->whereNull('last_activity_at')
                            ->where('accepted_at', '<', $threshold);
                      });
            })
            ->with('worker')
            ->get();

        $cancelledCount = 0;

        foreach ($inactiveJobs as $job) {
            // Auto-cancelar por timeout
            $job->update([
                'status' => 'cancelled',
                'pause_reason' => 'Auto-cancelado por inactividad (30min sin movimiento GPS ni actividad en chat)',
            ]);

            // Restaurar disponibilidad del worker
            if ($job->worker) {
                $worker = Worker::find($job->worker_id);
                if ($worker && $worker->availability_status === 'intermediate') {
                    // Verificar que no tenga otros trabajos activos
                    $otherActiveJobs = ServiceRequest::where('worker_id', $worker->id)
                        ->whereIn('status', ['accepted', 'in_progress'])
                        ->where('id', '!=', $job->id)
                        ->count();

                    if ($otherActiveJobs === 0) {
                        $worker->update(['availability_status' => 'active']);
                    }
                }
            }

            $cancelledCount++;
            
            Log::info("Job #{$job->id} auto-cancelled due to 30min inactivity");
        }

        $this->info("Checked inactive jobs. Cancelled: {$cancelledCount}");

        return 0;
    }
}
