<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductAnalyticsEvent;
use App\Models\User;
use App\Models\Worker;
use App\Support\AdminGate;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Eventos de producto desde la web (Next) o clientes directos.
 * Body: { "name": string, "payload": object, "t": number }
 *
 * Si existe env ANALYTICS_INGEST_SECRET, cabecera obligatoria: X-Analytics-Secret
 *
 * Con Bearer Sanctum opcional se guarda `user_id` para cohortes.
 *
 * Lectura agregada y listado: GET bajo /api/v1/admin/analytics/* (Sanctum + admin).
 */
class ProductAnalyticsController extends Controller
{
    public function workerMarketingSummary(Request $request, int $workerId)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $ownsWorker = Worker::query()
            ->where('id', $workerId)
            ->where('user_id', $user->id)
            ->exists();
        if (! $ownsWorker) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $days = max(1, min((int) $request->query('days', 30), 90));
        $since = now()->subDays($days)->startOfDay();

        $base = DB::table('product_analytics_events')
            ->where('created_at', '>=', $since)
            ->whereRaw("COALESCE(payload->>'workerId', payload->>'worker_id') = ?", [(string) $workerId]);

        $totalsByName = (clone $base)
            ->select('name')
            ->selectRaw('COUNT(*)::int as total')
            ->groupBy('name')
            ->pluck('total', 'name');

        $views = (int) ($totalsByName['product_view_shared'] ?? 0);
        $shares = (int) ($totalsByName['share_click'] ?? 0);
        $whatsapp = (int) ($totalsByName['whatsapp_share'] ?? 0);
        $pdf = (int) ($totalsByName['pdf_download'] ?? 0);
        $checkouts = (int) ($totalsByName['checkout_from_share'] ?? 0);

        $dailyRows = (clone $base)
            ->whereIn('name', ['product_view_shared', 'share_click', 'whatsapp_share', 'pdf_download', 'checkout_from_share'])
            ->selectRaw("DATE(created_at) as day")
            ->selectRaw("COUNT(*) FILTER (WHERE name = 'product_view_shared')::int as views")
            ->selectRaw("COUNT(*) FILTER (WHERE name = 'share_click')::int as shares")
            ->selectRaw("COUNT(*) FILTER (WHERE name = 'whatsapp_share')::int as whatsapp")
            ->selectRaw("COUNT(*) FILTER (WHERE name = 'pdf_download')::int as pdf")
            ->selectRaw("COUNT(*) FILTER (WHERE name = 'checkout_from_share')::int as checkouts")
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $topProducts = (clone $base)
            ->whereIn('name', ['product_view_shared', 'share_click', 'whatsapp_share', 'pdf_download', 'checkout_from_share'])
            ->selectRaw("COALESCE(payload->>'productId', payload->>'product_id') as product_id_raw")
            ->selectRaw('COUNT(*)::int as touches')
            ->whereRaw("COALESCE(payload->>'productId', payload->>'product_id') IS NOT NULL")
            ->groupBy('product_id_raw')
            ->orderByDesc('touches')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id_raw,
                'touches' => (int) $row->touches,
            ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'worker_id' => $workerId,
                'window_days' => $days,
                'since' => $since->toIso8601String(),
                'totals' => [
                    'views' => $views,
                    'shares' => $shares,
                    'whatsapp' => $whatsapp,
                    'pdf' => $pdf,
                    'checkouts' => $checkouts,
                ],
                'conversion' => [
                    'share_to_checkout_rate' => $shares > 0 ? round($checkouts / $shares, 4) : 0,
                    'view_to_checkout_rate' => $views > 0 ? round($checkouts / $views, 4) : 0,
                ],
                'daily' => $dailyRows->map(fn ($row) => [
                    'day' => (string) $row->day,
                    'views' => (int) $row->views,
                    'shares' => (int) $row->shares,
                    'whatsapp' => (int) $row->whatsapp,
                    'pdf' => (int) $row->pdf,
                    'checkouts' => (int) $row->checkouts,
                ]),
                'top_products' => $topProducts,
            ],
        ]);
    }

    public function adminSummary(Request $request)
    {
        AdminGate::assert($request);

        $now = now();
        $cutoffD1 = $now->copy()->subDay();
        $cutoffD7 = $now->copy()->subDays(7);
        $prevStart = $now->copy()->subDays(14);
        $prevEnd = $cutoffD7;

        $totals = DB::table('product_analytics_events')
            ->where('created_at', '>=', $cutoffD7)
            ->selectRaw('COUNT(*) as events_d7')
            ->selectRaw('COUNT(*) FILTER (WHERE created_at >= ?) as events_d1', [$cutoffD1])
            ->first();

        $uniqueIps = DB::table('product_analytics_events')
            ->where('created_at', '>=', $cutoffD7)
            ->whereNotNull('ip_address')
            ->where('ip_address', '!=', '')
            ->selectRaw('COUNT(DISTINCT ip_address) FILTER (WHERE created_at >= ?) as ips_d1', [$cutoffD1])
            ->selectRaw('COUNT(DISTINCT ip_address) as ips_d7')
            ->first();

        $usersDistinct = DB::table('product_analytics_events')
            ->where('created_at', '>=', $cutoffD7)
            ->whereNotNull('user_id')
            ->selectRaw('COUNT(DISTINCT user_id) FILTER (WHERE created_at >= ?) as u_d1', [$cutoffD1])
            ->selectRaw('COUNT(DISTINCT user_id) as u_d7')
            ->first();

        $prevWindowUsers = (int) DB::table('product_analytics_events')
            ->whereNotNull('user_id')
            ->where('created_at', '>=', $prevStart)
            ->where('created_at', '<', $prevEnd)
            ->selectRaw('COUNT(DISTINCT user_id) as c')
            ->value('c');

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
        $cohortReturning = (int) ($cohortRow->c ?? 0);

        $byName = DB::table('product_analytics_events')
            ->where('created_at', '>=', $cutoffD7)
            ->groupBy('name')
            ->select('name')
            ->selectRaw('COUNT(*) as events_d7')
            ->selectRaw('COUNT(*) FILTER (WHERE created_at >= ?) as events_d1', [$cutoffD1])
            ->orderByDesc('events_d7')
            ->get();

        return response()->json([
            'generated_at' => $now->toIso8601String(),
            'windows' => [
                'd1' => [
                    'since' => $cutoffD1->toIso8601String(),
                    'until' => $now->toIso8601String(),
                ],
                'd7' => [
                    'since' => $cutoffD7->toIso8601String(),
                    'until' => $now->toIso8601String(),
                ],
            ],
            'totals' => [
                'events_d1' => (int) ($totals->events_d1 ?? 0),
                'events_d7' => (int) ($totals->events_d7 ?? 0),
            ],
            'unique_ips' => [
                'd1' => (int) ($uniqueIps->ips_d1 ?? 0),
                'd7' => (int) ($uniqueIps->ips_d7 ?? 0),
            ],
            'users_with_events' => [
                'distinct_d1' => (int) ($usersDistinct->u_d1 ?? 0),
                'distinct_d7' => (int) ($usersDistinct->u_d7 ?? 0),
            ],
            'cohort' => [
                'label' => 'Usuarios con evento en 7–14 días atrás y también en los últimos 7 días (mismo user_id)',
                'week_over_week_returning' => $cohortReturning,
                'users_in_previous_window_only' => $prevWindowUsers,
                'return_rate_vs_previous_window' => $prevWindowUsers > 0
                    ? round($cohortReturning / $prevWindowUsers, 4)
                    : null,
                'windows' => [
                    'previous' => [
                        'since' => $prevStart->toIso8601String(),
                        'until' => $prevEnd->toIso8601String(),
                    ],
                    'current' => [
                        'since' => $cutoffD7->toIso8601String(),
                        'until' => $now->toIso8601String(),
                    ],
                ],
            ],
            'by_name' => $byName->map(fn ($row) => [
                'name' => $row->name,
                'events_d1' => (int) $row->events_d1,
                'events_d7' => (int) $row->events_d7,
            ]),
        ]);
    }

    /**
     * Listado paginado (más reciente primero).
     *
     * Query: name (coincidencia parcial ilike), from, to (ISO date o datetime), per_page (1–100).
     */
    public function adminIndex(Request $request)
    {
        AdminGate::assert($request);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = $validated['per_page'] ?? 30;

        $q = ProductAnalyticsEvent::query()->orderByDesc('created_at');

        if (! empty($validated['name'])) {
            $term = '%'.$validated['name'].'%';
            $q->where('name', 'ilike', $term);
        }

        if (! empty($validated['from'])) {
            $from = $this->parseBoundaryDate($validated['from'], startOfDay: true);
            $q->where('created_at', '>=', $from);
        }

        if (! empty($validated['to'])) {
            $to = $this->parseBoundaryDate($validated['to'], startOfDay: false);
            $q->where('created_at', '<=', $to);
        }

        $page = $q->paginate($perPage)->through(function (ProductAnalyticsEvent $ev) {
            return [
                'id' => $ev->id,
                'name' => $ev->name,
                'payload' => $ev->payload,
                'user_id' => $ev->user_id,
                'client_ts' => $ev->client_ts,
                'ip_address' => $ev->ip_address,
                'user_agent' => $ev->user_agent,
                'created_at' => $ev->created_at?->toIso8601String(),
            ];
        });

        return response()->json($page);
    }

    private function parseBoundaryDate(string $value, bool $startOfDay): CarbonInterface
    {
        $c = \Carbon\Carbon::parse($value);

        return $startOfDay ? $c->startOfDay() : $c->endOfDay();
    }

    public function store(Request $request)
    {
        $secret = config('services.analytics.ingest_secret');
        if (is_string($secret) && $secret !== '') {
            $sent = $request->header('X-Analytics-Secret', '');
            if (! hash_equals($secret, $sent)) {
                return response()->json(['error' => 'forbidden'], 403);
            }
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'payload' => 'nullable|array',
            't' => 'required|integer',
        ]);

        $userId = null;
        if ($token = $request->bearerToken()) {
            $pat = PersonalAccessToken::findToken($token);
            if ($pat && $pat->tokenable instanceof User) {
                $userId = $pat->tokenable->id;
            }
        }

        try {
            ProductAnalyticsEvent::create([
                'name' => $validated['name'],
                'payload' => $validated['payload'] ?? [],
                'user_id' => $userId,
                'client_ts' => $validated['t'],
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('product_analytics.insert_failed', ['e' => $e->getMessage()]);

            return response()->json(['error' => 'server_error'], 500);
        }

        return response()->noContent();
    }
}
