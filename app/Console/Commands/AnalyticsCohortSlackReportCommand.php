<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Envía un resumen corto a Slack (Incoming Webhook) si ANALYTICS_SLACK_WEBHOOK_URL está definida.
 */
class AnalyticsCohortSlackReportCommand extends Command
{
    protected $signature = 'analytics:report-cohort-slack';

    protected $description = 'POST resumen cohorte analytics a Slack (opcional)';

    public function handle(): int
    {
        $url = config('services.slack.analytics_webhook_url');
        if (! is_string($url) || $url === '') {
            $this->warn('ANALYTICS_SLACK_WEBHOOK_URL no configurada; omitiendo.');

            return self::SUCCESS;
        }

        $now = now();
        $cutoffD1 = $now->copy()->subDay();
        $cutoffD7 = $now->copy()->subDays(7);
        $prevStart = $now->copy()->subDays(14);
        $prevEnd = $cutoffD7;

        $totals = DB::table('product_analytics_events')
            ->where('created_at', '>=', $cutoffD7)
            ->selectRaw('COUNT(*) as d7')
            ->selectRaw('COUNT(*) FILTER (WHERE created_at >= ?) as d1', [$cutoffD1])
            ->first();

        $usersDistinct = DB::table('product_analytics_events')
            ->where('created_at', '>=', $cutoffD7)
            ->whereNotNull('user_id')
            ->selectRaw('COUNT(DISTINCT user_id) FILTER (WHERE created_at >= ?) as u1', [$cutoffD1])
            ->selectRaw('COUNT(DISTINCT user_id) as u7')
            ->first();

        $cohortRow = DB::selectOne(
            '
            SELECT COUNT(*)::int AS c FROM (
                SELECT DISTINCT user_id FROM product_analytics_events
                WHERE user_id IS NOT NULL AND created_at >= ? AND created_at < ?
            ) p
            INNER JOIN (
                SELECT DISTINCT user_id FROM product_analytics_events
                WHERE user_id IS NOT NULL AND created_at >= ?
            ) c USING (user_id)
            ',
            [$prevStart, $prevEnd, $cutoffD7]
        );

        $text = sprintf(
            "*JobsHours analytics* · %s\n".
            "Eventos D1: %d · D7: %d\n".
            "Usuarios (eventos con user_id) D1: %d · D7: %d\n".
            "Cohorte WoW (7–14d ∩ últimos 7d): %d\n",
            $now->toIso8601String(),
            (int) ($totals->d1 ?? 0),
            (int) ($totals->d7 ?? 0),
            (int) ($usersDistinct->u1 ?? 0),
            (int) ($usersDistinct->u7 ?? 0),
            (int) ($cohortRow->c ?? 0)
        );

        try {
            Http::timeout(15)->post($url, ['text' => $text]);
        } catch (\Throwable $e) {
            $this->error('Slack POST failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Report sent to Slack.');

        return self::SUCCESS;
    }
}
