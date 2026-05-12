<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function check()
    {
        $checks = [];
        $status = 'ok';

        // 1. Base de datos
        try {
            DB::select('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'fail';
            $status = 'degraded';
        }

        // 2. PostGIS
        try {
            DB::select("SELECT ST_MakePoint(0,0)");
            $checks['postgis'] = 'ok';
        } catch (\Throwable $e) {
            $checks['postgis'] = 'fail';
            $status = 'degraded';
        }

        // 3. Cache
        try {
            Cache::put('health_check', true, 5);
            $checks['cache'] = Cache::get('health_check') ? 'ok' : 'fail';
        } catch (\Throwable $e) {
            $checks['cache'] = 'fail';
            $status = 'degraded';
        }

        // 4. Workers activos (métrica rápida)
        try {
            $activeWorkers = DB::table('workers')
                ->where('availability_status', 'active')
                ->count();
            $checks['active_workers'] = $activeWorkers;
        } catch (\Throwable $e) {
            $checks['active_workers'] = 0;
        }

        // 5. Queue — trabajos pendientes
        try {
            $pendingJobs = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();
            $checks['queue_pending'] = $pendingJobs;
            $checks['queue_failed'] = $failedJobs;
            if ($failedJobs > 10) {
                $status = 'degraded';
            }
        } catch (\Throwable $e) {
            $checks['queue'] = 'unknown';
        }

        // 6. Redis
        try {
            Redis::ping();
            $checks['redis'] = 'ok';
        } catch (\Throwable $e) {
            $checks['redis'] = 'fail';
            $status = 'degraded';
        }

        // 7. Horizon — proceso vivo
        try {
            $horizonStatus = \Artisan::call('horizon:status') === 0
                ? 'running'
                : 'stopped';
            $checks['horizon'] = $horizonStatus;
        } catch (\Throwable $e) {
            $checks['horizon'] = 'unknown';
        }

        // 8. Reverb — TCP check rápido
        try {
            $host = config('reverb.servers.reverb.host', '127.0.0.1');
            $port = (int) config('reverb.servers.reverb.port', 8080);
            $fp = @fsockopen($host, $port, $errno, $errstr, 1);
            if ($fp) {
                fclose($fp);
                $checks['reverb'] = 'ok';
            } else {
                $checks['reverb'] = 'fail';
                $status = 'degraded';
            }
        } catch (\Throwable $e) {
            $checks['reverb'] = 'unknown';
        }

        $httpStatus = $status === 'ok' ? 200 : 503;

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toISOString(),
            'app' => config('app.name'),
            'env' => config('app.env'),
            'checks' => $checks,
        ], $httpStatus);
    }
}
