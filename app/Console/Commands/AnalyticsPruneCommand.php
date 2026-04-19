<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyticsPruneCommand extends Command
{
    protected $signature = 'analytics:prune {--days= : Sobrescribe ANALYTICS_RETENTION_DAYS}';

    protected $description = 'Elimina filas antiguas de product_analytics_events según ANALYTICS_RETENTION_DAYS';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('services.analytics.retention_days', 365));
        if ($days < 7) {
            $this->error('Refusing to prune with days < 7 (use explicit --days if intended).');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = DB::table('product_analytics_events')->where('created_at', '<', $cutoff)->delete();
        $this->info("Deleted {$deleted} analytics rows older than {$days} days (before {$cutoff->toIso8601String()}).");

        return self::SUCCESS;
    }
}
