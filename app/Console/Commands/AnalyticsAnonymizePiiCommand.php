<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyticsAnonymizePiiCommand extends Command
{
    protected $signature = 'analytics:anonymize-pii {--days= : Sobrescribe ANALYTICS_PII_ANONYMIZE_AFTER_DAYS}';

    protected $description = 'Pone en NULL ip_address y user_agent en eventos de analytics más antiguos que N días';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('services.pii.anonymize_after_days', 90));
        if ($days < 30) {
            $this->error('Refusing: days < 30 (pass --days explicitly if intended).');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $updated = DB::table('product_analytics_events')
            ->where('created_at', '<', $cutoff)
            ->where(function ($q) {
                $q->whereNotNull('ip_address')->orWhereNotNull('user_agent');
            })
            ->update([
                'ip_address' => null,
                'user_agent' => null,
            ]);

        $this->info("Anonymized PII on {$updated} rows (created before {$cutoff->toIso8601String()}).");

        return self::SUCCESS;
    }
}
