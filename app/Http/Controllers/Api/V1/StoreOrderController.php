<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStoreMercadoPagoWebhook;
use App\Models\IntegratedQuote;
use App\Models\StoreOrder;
use App\Models\ServiceRequest;
use App\Models\Worker;
use App\Services\FCMService;
use App\Services\StoreOrderPaidMailer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StoreOrderController extends Controller
{
    private string $mpBase = 'https://api.mercadopago.com';
    private string $inventarioApi = 'http://127.0.0.1:8003/api';

    private function mpToken(): string
    {
        return (string) config('services.mercadopago.access_token', '');
    }

    private function isWebhookSignatureValid(Request $request): bool
    {
        $secret = trim((string) config('services.mercadopago.webhook_secret', ''));
        if ($secret === '') {
            // Compatibilidad: si no hay secret configurado, no bloquear webhook.
            return true;
        }

        $xSignature = (string) $request->header('x-signature', '');
        $xRequestId = (string) $request->header('x-request-id', '');
        $dataId = (string) ($request->input('data.id') ?? $request->input('id') ?? '');
        if ($xSignature === '' || $xRequestId === '' || $dataId === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $xSignature) as $segment) {
            $tuple = explode('=', trim($segment), 2);
            if (count($tuple) === 2) {
                $parts[$tuple[0]] = $tuple[1];
            }
        }
        $ts = $parts['ts'] ?? null;
        $v1 = $parts['v1'] ?? null;
        if (! $ts || ! $v1) {
            return false;
        }

        $manifest = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";
        $expected = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expected, $v1);
    }

    /**
     * POST /api/v1/store/orders
     * Cliente crea pedido y obtiene link de pago.
     */
    public function create(Request $request)
    {
        $traceId = (string) Str::uuid();
        $validated = $request->validate([
            'worker_id'          => 'required|integer|exists:workers,id',
            'items'              => 'required|array|min:1',
            'items.*.idproducto' => 'required|integer',
            'items.*.nombre'     => 'required|string',
            'items.*.cantidad'   => 'required|integer|min:1',
            'items.*.precio'     => 'required|numeric|min:0',
            'total'              => 'required|numeric|min:0',
            'buyer_name'         => 'required|string|max:100',
            'buyer_email'        => 'required|email',
            'buyer_phone'        => 'nullable|string|max:20',
            'delivery'           => 'sometimes|boolean',
            'delivery_address'   => 'nullable|string|max:255',
        ]);

        $worker = Worker::with('user')->findOrFail($validated['worker_id']);
        if (!$worker->is_seller) {
            return response()->json(['status' => 'error', 'message' => 'Este trabajador no tiene tienda activa'], 422);
        }

        $amount = (int) round($validated['total']);
        $confirmationCode = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        // 1) Crear orden local primero para usar su ID en MP external_reference y back_urls.
        $order = StoreOrder::create([
            'worker_id'         => $worker->id,
            'buyer_name'        => $validated['buyer_name'],
            'buyer_email'       => $validated['buyer_email'],
            'buyer_phone'       => $validated['buyer_phone'] ?? null,
            'items'             => $validated['items'],
            'total'             => $amount,
            'delivery'          => (bool) ($validated['delivery'] ?? false),
            'delivery_address'  => $validated['delivery_address'] ?? null,
            'status'            => 'pending',
            'confirmation_code' => $confirmationCode,
            'expires_at'        => Carbon::now()->addHours(24),
            'public_token'      => Str::random(48),
        ]);

        // 2) Crear preferencia MP.
        $mpItems = array_map(fn ($i) => [
            'id'          => 'prod-' . $i['idproducto'],
            'title'       => $i['nombre'],
            'quantity'    => (int) $i['cantidad'],
            'unit_price'  => (float) round($i['precio']),
            'currency_id' => 'CLP',
        ], $validated['items']);

        $mpPayload = [
            'items'              => $mpItems,
            'payer'              => ['email' => $validated['buyer_email']],
            // Usar el ID de la orden para rastrear 1:1 en webhook.
            'external_reference' => (string) $order->id,
            'notification_url'   => config('app.url') . '/api/v1/store/webhook',
            'back_urls' => [
                'success' => config('app.url') . '/tienda/success?confirmation_code=' . $confirmationCode . '&external_reference=' . $order->id . '&token=' . $order->public_token,
                'failure' => config('app.url') . '/tienda/failure',
                'pending' => config('app.url') . '/tienda/pending',
            ],
            'auto_return'          => 'approved',
            'statement_descriptor' => 'JobsHours',
            'metadata'             => ['worker_id' => $worker->id, 'store_order_id' => $order->id],
        ];

        $mpResponse = Http::withToken($this->mpToken())
            ->post("{$this->mpBase}/checkout/preferences", $mpPayload);

        if (!$mpResponse->successful()) {
            Log::error('[StoreOrder] Error MP preferencia', [
                'trace_id' => $traceId,
                'order_id' => $order->id,
                'http_status' => $mpResponse->status(),
                'body' => $mpResponse->json(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Error al generar link de pago',
                'trace_id' => $traceId,
            ], 500);
        }

        $mpData = $mpResponse->json();
        $payLink = config('app.env') === 'production'
            ? ($mpData['init_point'] ?? null)
            : ($mpData['sandbox_init_point'] ?? ($mpData['init_point'] ?? null));

        $order->update([
            'mp_preference_id' => $mpData['id'] ?? null,
        ]);

        Log::info('[StoreOrder] Checkout link generado', [
            'trace_id' => $traceId,
            'order_id' => $order->id,
            'mp_preference_id' => $mpData['id'] ?? null,
            'worker_id' => $worker->id,
            'amount' => $amount,
            'buyer_email' => $validated['buyer_email'],
        ]);

        // Push FCM al worker (no bloqueante).
        try {
            $storeName = $worker->store_name ?? 'tu tienda';
            $itemCount = array_sum(array_column($validated['items'], 'cantidad'));
            app(FCMService::class)->sendToUser(
                $worker->user,
                '🛒 Nuevo pedido — ' . $storeName,
                "{$validated['buyer_name']} pidió {$itemCount} producto(s) por $" . number_format($amount, 0, ',', '.') . " CLP. Tienes 24h para confirmar.",
                ['type' => 'store_order_pending', 'order_id' => (string) $order->id]
            );
        } catch (\Throwable $e) {
            Log::warning('[StoreOrder] FCM error', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'status'            => 'success',
            'order_id'          => $order->id,
            'public_token'      => $order->public_token,
            'payment_link'      => $payLink,
            'amount'            => $amount,
            'confirmation_code' => $confirmationCode,
            'expires_at'        => optional($order->expires_at)->toIso8601String(),
            'trace_id'          => $traceId,
        ]);
    }

    /**
     * GET /api/v1/store/orders
     * Worker ve sus pedidos.
     */
    public function myOrders(Request $request)
    {
        $worker = Worker::where('user_id', $request->user()->id)->first();
        if (!$worker) {
            return response()->json(['status' => 'error', 'message' => 'No eres worker'], 404);
        }

        StoreOrder::where('worker_id', $worker->id)
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now())
            ->update(['status' => 'expired']);

        $orders = StoreOrder::where('worker_id', $worker->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $data = $orders->map(function (StoreOrder $order) {
            $quote = null;
            $serviceRequest = null;

            if ($order->integrated_quote_id) {
                $quote = IntegratedQuote::select([
                    'id',
                    'status',
                    'total_amount',
                    'service_amount',
                    'materials_amount',
                    'delivery_amount',
                    'tool_wear_amount',
                    'service_type',
                    'service_description',
                ])->find($order->integrated_quote_id);

                $serviceRequest = ServiceRequest::where('integrated_quote_id', $order->integrated_quote_id)
                    ->orderByDesc('id')
                    ->first();
            }

            return [
                'id' => $order->id,
                'buyer_name' => $order->buyer_name,
                'buyer_email' => $order->buyer_email,
                'buyer_phone' => $order->buyer_phone,
                'items' => $order->items,
                'total' => $order->total,
                'delivery' => $order->delivery,
                'delivery_address' => $order->delivery_address,
                'status' => $order->status,
                'mp_status' => $order->mp_status,
                'expires_at' => $order->expires_at,
                'confirmed_at' => $order->confirmed_at,
                'rejected_at' => $order->rejected_at,
                'reject_reason' => $order->reject_reason,
                'created_at' => $order->created_at,
                'integrated_quote_id' => $order->integrated_quote_id,
                'integrated_quote' => $quote,
                'service_request' => $serviceRequest ? [
                    'id' => $serviceRequest->id,
                    'status' => $serviceRequest->status,
                    'offered_price' => $serviceRequest->offered_price,
                    'completed_at' => $serviceRequest->completed_at,
                ] : null,
            ];
        });

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    /**
     * GET /api/v1/store/orders/{id}
     * Estado público para que el cliente vea la timeline.
     */
    public function showPublic(Request $request, int $id)
    {
        $order = StoreOrder::where('id', $id)->first();
        if (!$order) {
            return response()->json(['status' => 'error', 'message' => 'Pedido no encontrado'], 404);
        }

        $token = (string) $request->query('token', '');
        if ($token === '' || !hash_equals((string) $order->public_token, $token)) {
            return response()->json(['status' => 'error', 'message' => 'Token inválido'], 403);
        }

        $quote = null;
        $serviceRequest = null;
        if ($order->integrated_quote_id) {
            $quote = IntegratedQuote::with('items')->find($order->integrated_quote_id);
            $serviceRequest = ServiceRequest::where('integrated_quote_id', $order->integrated_quote_id)
                ->orderByDesc('id')
                ->first();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'order' => [
                    'id' => $order->id,
                    'status' => $order->status,
                    'mp_status' => $order->mp_status,
                    'total' => $order->total,
                    'delivery' => $order->delivery,
                    'delivery_address' => $order->delivery_address,
                    'items' => $order->items,
                    'integrated_quote_id' => $order->integrated_quote_id,
                    'confirmed_at' => $order->confirmed_at,
                    'created_at' => $order->created_at,
                ],
                'integrated_quote' => $quote,
                'service_request' => $serviceRequest ? [
                    'id' => $serviceRequest->id,
                    'status' => $serviceRequest->status,
                    'offered_price' => $serviceRequest->offered_price,
                    'completed_at' => $serviceRequest->completed_at,
                ] : null,
            ],
        ]);
    }

    /**
     * POST /api/v1/store/orders/{id}/confirm
     * Comprador confirma recepción con código (público).
     */
    public function confirm(Request $request, int $id)
    {
        $request->validate(['code' => 'required|string|size:4']);

        $order = StoreOrder::findOrFail($id);

        if (!in_array($order->status, ['pending', 'paid'], true)) {
            return response()->json(['status' => 'error', 'message' => 'Pedido no está pendiente de confirmación'], 422);
        }
        if ($order->mp_status !== 'approved') {
            return response()->json(['status' => 'error', 'message' => 'El pago aún no ha sido confirmado por Mercado Pago'], 422);
        }
        if ($order->expires_at && $order->expires_at < Carbon::now()) {
            $order->update(['status' => 'expired']);
            return response()->json(['status' => 'error', 'message' => 'Pedido expirado'], 422);
        }
        if ($order->confirmation_code !== $request->code) {
            return response()->json(['status' => 'error', 'message' => 'Código incorrecto'], 422);
        }

        $order->update(['status' => 'confirmed', 'confirmed_at' => Carbon::now()]);

        // Si es parte de una cotización integrada, marcar materiales confirmados.
        if ($order->integrated_quote_id) {
            $quote = IntegratedQuote::find($order->integrated_quote_id);
            if ($quote) {
                // Si no hay servicio asociado, al confirmar el PIN se puede cerrar completo.
                $hasService = (int) ($quote->service_amount ?? 0) > 0 || !empty($quote->service_type) || !empty($quote->service_description);
                if (!$hasService) {
                    $quote->update(['status' => 'closed']);
                } else {
                    // No "rebote": si el servicio ya estaba completado, cerrar; si no, confirmar materiales.
                    if ($quote->status === 'service_completed') {
                        $quote->update(['status' => 'closed']);
                    } elseif ($quote->status !== 'closed') {
                        $quote->update(['status' => 'materials_confirmed']);
                    }
                }
            }
        }

        // Descontar stock en inventario-api (best-effort; si falla, se puede reintentar manualmente).
        foreach (($order->items ?? []) as $item) {
            try {
                Http::post("{$this->inventarioApi}/ventas", [
                    'idproducto' => $item['idproducto'],
                    'cantidad'   => $item['cantidad'],
                    'tipo'       => 'venta_tienda',
                ]);
            } catch (\Throwable $e) {
                Log::warning('[StoreOrder] Error descontando stock', ['item' => $item, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['status' => 'success', 'message' => 'Pedido confirmado', 'order' => $order]);
    }

    /**
     * POST /api/v1/store/orders/{id}/reject
     * Worker rechaza.
     */
    public function reject(Request $request, int $id)
    {
        $request->validate(['reason' => 'nullable|string|max:255']);

        $worker = Worker::where('user_id', $request->user()->id)->first();
        $order = StoreOrder::where('id', $id)->where('worker_id', $worker?->id)->firstOrFail();

        if ($order->status !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'Pedido no está pendiente'], 422);
        }

        $order->update([
            'status'        => 'rejected',
            'rejected_at'   => Carbon::now(),
            'reject_reason' => $request->reason ?? 'Sin stock disponible',
        ]);

        return response()->json(['status' => 'success', 'message' => 'Pedido rechazado', 'order' => $order]);
    }

    /**
     * POST /api/v1/store/orders/{id}/qa-paid
     * Bypass QA: marca un pedido como pagado con clave temporal.
     */
    public function qaMarkPaid(Request $request, int $id)
    {
        if (app()->environment('production')) {
            return response()->json(['status' => 'error', 'message' => 'No disponible en producción'], 403);
        }

        $validated = $request->validate([
            'qa_key' => 'required|string',
            'note' => 'nullable|string|max:120',
        ]);

        $expected = (string) env('STORE_QA_BYPASS_KEY', '');
        if ($expected === '' || !hash_equals($expected, (string) $validated['qa_key'])) {
            return response()->json(['status' => 'error', 'message' => 'Clave QA inválida'], 403);
        }

        $order = StoreOrder::findOrFail($id);
        $wasPending = $order->status === 'pending';
        $manualPaymentId = 'qa-manual-' . $order->id . '-' . now()->format('YmdHis');

        $updates = [
            'mp_payment_id' => $manualPaymentId,
            'mp_status' => 'approved',
        ];
        if (in_array($order->status, ['pending', 'paid'], true)) {
            $updates['status'] = 'paid';
        }
        $order->update($updates);

        if ($order->integrated_quote_id) {
            IntegratedQuote::where('id', $order->integrated_quote_id)->update([
                'mp_payment_id' => $manualPaymentId,
                'mp_status' => 'approved',
                'status' => 'paid',
            ]);
        }

        Log::warning('[StoreOrder][QA] Pedido marcado como pagado manualmente', [
            'order_id' => $order->id,
            'manual_payment_id' => $manualPaymentId,
            'note' => $validated['note'] ?? null,
        ]);

        $order->refresh();
        if ($wasPending && $order->status === 'paid') {
            try {
                app(StoreOrderPaidMailer::class)->sendReceiptsIfPaid($order);
            } catch (\Throwable $e) {
                Log::warning('[StoreOrder][QA] Email post-pago falló', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pedido marcado como pagado (QA)',
            'data' => [
                'order_id' => $order->id,
                'mp_payment_id' => $manualPaymentId,
                'mp_status' => 'approved',
                'order_status' => $order->fresh()->status,
            ],
        ]);
    }

    /**
     * POST /api/v1/store/webhook
     * Webhook Mercado Pago para store_orders (público).
     */
    public function webhook(Request $request)
    {
        if (! $this->isWebhookSignatureValid($request)) {
            Log::warning('[StoreOrder] Webhook firma inválida', [
                'ip' => $request->ip(),
                'x_request_id' => $request->header('x-request-id'),
            ]);
            return response()->json(['status' => 'invalid_signature'], 401);
        }

        $type = $request->input('type') ?? $request->input('topic');
        if ($type !== 'payment') {
            return response()->json(['status' => 'ok']);
        }

        $paymentId = $request->input('data.id') ?? $request->input('id');
        if (! $paymentId) {
            return response()->json(['status' => 'ok']);
        }

        $paymentId = (string) $paymentId;

        if (config('mercadopago.webhook_sync', false)) {
            try {
                $result = app(\App\Services\StoreMercadoPagoWebhookProcessor::class)->processByPaymentId($paymentId);

                return response()->json(['status' => 'ok_sync', 'result' => $result]);
            } catch (\Throwable $e) {
                Log::error('[StoreOrder] Webhook sync falló', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);

                return response()->json(['status' => 'error', 'message' => 'sync_failed'], 500);
            }
        }

        ProcessStoreMercadoPagoWebhook::dispatch($paymentId);

        return response()->json(['status' => 'accepted', 'payment_id' => $paymentId]);
    }
}

