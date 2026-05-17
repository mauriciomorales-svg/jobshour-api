<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StoreDemandIntegration;
use App\Models\StoreDemandPartnerPublish;
use App\Services\DemandPublishService;
use App\Support\Geofence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Publicar demanda desde el servidor de una tienda (p. ej. tras pago aprobado).
 *
 * POST /api/v1/integrations/store-demand
 * Authorization: Bearer &lt;token_plano_generado_una_vez&gt;
 */
class StorePartnerDemandController extends Controller
{
    public function store(Request $request, DemandPublishService $publisher)
    {
        $plain = $request->bearerToken();
        if (! is_string($plain) || strlen($plain) < 24) {
            return response()->json(['status' => 'error', 'message' => 'Token inválido'], 401);
        }

        $hash = hash('sha256', $plain);
        $integration = StoreDemandIntegration::query()
            ->where('token_hash', $hash)
            ->where('active', true)
            ->with('user')
            ->first();

        if (! $integration || ! $integration->user) {
            return response()->json(['status' => 'error', 'message' => 'Token inválido'], 401);
        }

        $clientIp = $request->ip() ?? '';
        if (! $integration->clientIpIsAllowed($clientIp)) {
            Log::warning('store_demand_integration.ip_blocked', [
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
                'client_ip' => $clientIp,
            ]);

            return response()->json(['status' => 'error', 'message' => 'Acceso denegado'], 403);
        }

        $validated = $request->validate([
            'external_order_id' => 'required|string|max:120',
            'description' => 'required|string|max:500',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'category_id' => 'nullable|exists:categories,id',
            'offered_price' => 'nullable|numeric|min:0',
            'urgency' => 'nullable|in:low,medium,high,normal,urgent',
            'ttl_minutes' => 'nullable|integer|min:5|max:120',
            'type' => 'nullable|in:fixed_job,ride_share,express_errand',
            'travel_role' => 'nullable|in:driver,passenger',
            'category_type' => 'nullable|in:fixed,travel,errand',
            'store_name' => 'nullable|string|max:255',
            'pickup_address' => 'nullable|string|max:255',
            'delivery_address' => 'nullable|string|max:255',
            'pickup_lat' => 'nullable|numeric|between:-90,90',
            'pickup_lng' => 'nullable|numeric|between:-180,180',
            'delivery_lat' => 'nullable|numeric|between:-90,90',
            'delivery_lng' => 'nullable|numeric|between:-180,180',
            'items_count' => 'nullable|integer|min:1',
            'load_type' => 'nullable|in:light,medium,heavy',
            'requires_vehicle' => 'nullable|boolean',
            'scheduled_at' => 'nullable|date|after:now',
            'workers_needed' => 'nullable|integer|min:1|max:20',
            'recurrence' => 'nullable|in:once,daily,weekly,custom',
            'recurrence_days' => 'nullable|array',
            'recurrence_days.*' => 'integer|min:1|max:7',
            'idempotency_key' => 'nullable|string|max:120',
            'seats' => 'nullable|integer|min:1|max:8',
            'destination_name' => 'nullable|string|max:255',
            'departure_time' => 'nullable|date|after:-5 minutes',
        ]);

        $categoryId = $validated['category_id'] ?? $integration->default_category_id;
        if (! $categoryId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Indicá category_id en el cuerpo o configurá default_category_id en la integración.',
            ], 422);
        }

        $dedupeKey = isset($validated['idempotency_key']) && $validated['idempotency_key'] !== ''
            ? (string) $validated['idempotency_key']
            : (string) $validated['external_order_id'];

        $type = $validated['type'] ?? 'express_errand';
        $categoryType = $validated['category_type'] ?? match ($type) {
            'ride_share' => 'travel',
            'express_errand' => 'errand',
            default => 'fixed',
        };

        $extraPayload = [
            'external_order_id' => $validated['external_order_id'],
            'integration_name' => $integration->name,
        ];

        $publishInput = array_merge($validated, [
            'category_id' => $categoryId,
            'type' => $type,
            'category_type' => $categoryType,
            'payload' => $extraPayload,
        ]);

        try {
            $responsePayload = DB::transaction(function () use (
                $integration,
                $dedupeKey,
                $publisher,
                $publishInput
            ) {
                $existing = StoreDemandPartnerPublish::query()
                    ->where('store_demand_integration_id', $integration->id)
                    ->where('dedupe_key', $dedupeKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return [
                        'http' => 200,
                        'body' => [
                            'status' => 'success',
                            'message' => 'Demanda ya registrada (idempotencia).',
                            'data' => [
                                'request_id' => $existing->service_request_id,
                                'idempotent' => true,
                            ],
                        ],
                    ];
                }

                $serviceRequest = $publisher->publish($integration->user, $publishInput, null);

                try {
                    StoreDemandPartnerPublish::create([
                        'store_demand_integration_id' => $integration->id,
                        'dedupe_key' => $dedupeKey,
                        'service_request_id' => $serviceRequest->id,
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    // Dos webhooks concurrentes con el mismo dedupe_key: el otro insertó la fila idempotente primero.
                    $winner = StoreDemandPartnerPublish::query()
                        ->where('store_demand_integration_id', $integration->id)
                        ->where('dedupe_key', $dedupeKey)
                        ->first();
                    if ($winner) {
                        $serviceRequest->delete();

                        return [
                            'http' => 200,
                            'body' => [
                                'status' => 'success',
                                'message' => 'Demanda ya registrada (idempotencia).',
                                'data' => [
                                    'request_id' => $winner->service_request_id,
                                    'idempotent' => true,
                                ],
                            ],
                        ];
                    }
                    throw $e;
                }

                $ttl = $publishInput['ttl_minutes'] ?? 30;

                return [
                    'http' => 201,
                    'body' => [
                        'status' => 'success',
                        'message' => 'Publicación creada. Visible en el mapa por '.$ttl.' minutos',
                        'data' => [
                            'request_id' => $serviceRequest->id,
                            'pin_expires_at' => $serviceRequest->pin_expires_at,
                            'idempotent' => false,
                        ],
                    ],
                ];
            });
        } catch (\InvalidArgumentException $e) {
            if ($e->getMessage() === 'outside_zone') {
                Log::warning('store_demand_integration.outside_zone', [
                    'integration_id' => $integration->id,
                    'integration_name' => $integration->name,
                    'external_order_id' => $validated['external_order_id'] ?? null,
                    'lat' => $validated['lat'] ?? null,
                    'lng' => $validated['lng'] ?? null,
                    'client_ip' => $request->ip(),
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'La ubicación está fuera de la zona piloto activa.',
                    'zone' => Geofence::zoneInfo(),
                ], 422);
            }
            throw $e;
        }

        $this->logStoreDemandAudit($request, $integration, $validated, $dedupeKey, $responsePayload);

        return response()->json($responsePayload['body'], $responsePayload['http']);
    }

    /**
     * @param  array<string,mixed>  $validated
     * @param  array{http:int,body:array<string,mixed>}  $responsePayload
     */
    private function logStoreDemandAudit(
        Request $request,
        StoreDemandIntegration $integration,
        array $validated,
        string $dedupeKey,
        array $responsePayload
    ): void {
        $data = $responsePayload['body']['data'] ?? [];
        Log::info('store_demand_integration.publish', [
            'integration_id' => $integration->id,
            'integration_name' => $integration->name,
            'client_ip' => $request->ip(),
            'external_order_id' => $validated['external_order_id'] ?? null,
            'dedupe_key' => $dedupeKey,
            'http_status' => $responsePayload['http'],
            'service_request_id' => $data['request_id'] ?? null,
            'idempotent' => $data['idempotent'] ?? null,
        ]);
    }
}
