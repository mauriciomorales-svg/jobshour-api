<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use Illuminate\Http\Request;

/**
 * Resolución pública hostname → tienda (solo hosts verificados y con is_seller).
 */
class StoreHostController extends Controller
{
    public function resolve(Request $request)
    {
        $raw = strtolower(trim((string) $request->query('host', '')));
        $host = preg_replace('/:\d+$/', '', $raw);

        if ($host === '' || strlen($host) > 253 || ! preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host)) {
            return response()->json([
                'status' => 'success',
                'data' => null,
            ]);
        }

        $worker = Worker::query()
            ->where('public_store_host', $host)
            ->whereNotNull('public_store_host_verified_at')
            ->where('is_seller', true)
            ->first(['id']);

        if (! $worker) {
            return response()->json([
                'status' => 'success',
                'data' => null,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'worker_id' => $worker->id,
            ],
        ]);
    }
}
