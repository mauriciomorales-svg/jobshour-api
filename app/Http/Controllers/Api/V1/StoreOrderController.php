<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StoreOrder;
use App\Models\Worker;
use App\Services\FCMService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class StoreOrderController extends Controller
{
    private string $mpBase = 'https://api.mercadopago.com';
    private string $inventarioApi = 'http://127.0.0.1:8003/api';

    private function mpToken(): string
    {
        return config('services.mercadopago.access_token', '');
    }

    // POST /api/v1/store/orders  — cliente crea pedido y paga (autorización diferida)
    public function create(Request $request)
    {
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
            'delivery'           => 'boolean',
            'delivery_address'   => 'nullable|string|max:255',
        ]);

        $worker = Worker::with('user')->findOrFail($validated['worker_id']);
        if (!$worker->is_seller) {
            return response()->json(['status' => 'error', 'message' => 'Este trabajador no tiene tienda activa'], 422);
        }

        $amount = (int) round($validated['total']);

        // Crear preferencia MP (checkout normal — captura al confirmar)
        $mpItems = array_map(fn($i) => [
            'id'          => 'prod-' . $i['idproducto'],
            'title'       => $i['nombre'],
            'quantity'    => (int) $i['cantidad'],
            'unit_price'  => (float) round($i['precio']),
            'currency_id' => 'CLP',
        ], $validated['items']);

        $mpPayload = [
            'items'              => $mpItems,
            'payer'              => ['email' => $validated['buyer_email']],
            'external_reference' => 'store-' . $worker->id . '-' . time(),
            'notification_url'   => config('app.url') . '/api/v1/store/webhook',
            'back_urls' => [
                'success' => config('app.url') . '/tienda/success',
                'failure' => config('app.url') . '/tienda/failure',
                'pending' => config('app.url') . '/tienda/pending',
            ],
            'auto_return'          => 'approved',
            'statement_descriptor' => 'JobsHours',
            'metadata'             => ['worker_id' => $worker->id],
        ];

        $mpResponse = Http::withToken($this->mpToken())
            ->post("{$this->mpBase}/checkout/preferences", $mpPayload);

        if (!$mpResponse->successful()) {
            Log::error('[StoreOrder] Error MP preferencia', ['body' => $mpResponse->json()]);
            return response()->json(['status' => 'error', 'message' => 'Error al generar link de pago'], 500);
        }

        $mpData  = $mpResponse->json();
        $payLink = config('app.env') === 'production'
            ? $mpData['init_point']
            : $mpData['sandbox_init_point'];

        // Generar código de confirmación de 4 dígitos
        $confirmationCode = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        // Crear pedido en BD con estado pending y expiración 24h
        $order = StoreOrder::create([
            'worker_id'         => $worker->id,
            'buyer_name'        => $validated['buyer_name'],
            'buyer_email'       => $validated['buyer_email'],
            'buyer_phone'       => $validated['buyer_phone'] ?? null,
            'items'             => $validated['items'],
            'total'             => $amount,
            'delivery'          => $validated['delivery'] ?? false,
            'delivery_address'  => $validated['delivery_address'] ?? null,
            'status'            => 'pending',
            'confirmation_code' => $confirmationCode,
            'mp_preference_id'  => $mpData['id'],
            'expires_at'        => Carbon::now()->addHours(24),
        ]);

        // Push FCM al worker
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
            'payment_link'      => $payLink,
            'amount'            => $amount,
            'confirmation_code' => $confirmationCode,
            'expires_at'        => $order->expires_at->toIso8601String(),
        ]);
    }

    // GET /api/v1/store/orders  — worker ve sus pedidos pendientes
    public function myOrders(Request $request)
    {
        $worker = Worker::where('user_id', $request->user()->id)->first();
        if (!$worker) {
            return response()->json(['status' => 'error', 'message' => 'No eres worker'], 404);
        }

        // Auto-expirar pedidos vencidos
        StoreOrder::where('worker_id', $worker->id)
            ->where('status', 'pending')
            ->where('expires_at', '<', Carbon::now())
            ->update(['status' => 'expired']);

        $orders = StoreOrder::where('worker_id', $worker->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['status' => 'success', 'data' => $orders]);
    }

    // POST /api/v1/store/orders/{id}/confirm  — worker confirma con código
    public function confirm(Request $request, int $id)
    {
        $request->validate(['code' => 'required|string|size:4']);

        $worker = Worker::where('user_id', $request->user()->id)->first();
        $order  = StoreOrder::where('id', $id)->where('worker_id', $worker?->id)->firstOrFail();

        if (!in_array($order->status, ['pending', 'paid'])) {
            return response()->json(['status' => 'error', 'message' => 'Pedido no está pendiente'], 422);
        }
        if ($order->mp_status !== 'approved') {
            return response()->json(['status' => 'error', 'message' => 'El pago aún no ha sido confirmado por Mercado Pago. Pide al comprador que complete el pago.'], 422);
        }
        if ($order->expires_at < Carbon::now()) {
            $order->update(['status' => 'expired']);
            return response()->json(['status' => 'error', 'message' => 'Pedido expirado'], 422);
        }
        if ($order->confirmation_code !== $request->code) {
            return response()->json(['status' => 'error', 'message' => 'Código incorrecto'], 422);
        }

        $order->update(['status' => 'confirmed', 'confirmed_at' => Carbon::now()]);

        // Descontar stock en inventario-api
        foreach ($order->items as $item) {
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

    // POST /api/v1/store/orders/{id}/reject  — worker rechaza
    public function reject(Request $request, int $id)
    {
        $request->validate(['reason' => 'nullable|string|max:255']);

        $worker = Worker::where('user_id', $request->user()->id)->first();
        $order  = StoreOrder::where('id', $id)->where('worker_id', $worker?->id)->firstOrFail();

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

    // POST /api/v1/store/webhook  — MP notifica pago completado
    public function webhook(Request $request)
    {
        $type = $request->input('type') ?? $request->input('topic');
        if ($type !== 'payment') {
            return response()->json(['status' => 'ok']);
        }

        $paymentId = $request->input('data.id') ?? $request->input('id');
        if (!$paymentId) return response()->json(['status' => 'ok']);

        $payRes = Http::withToken($this->mpToken())
            ->get("{$this->mpBase}/v1/payments/{$paymentId}");

        if (!$payRes->successful()) return response()->json(['status' => 'ok']);

        $pay = $payRes->json();
        $ref = $pay['external_reference'] ?? '';

        // Buscar pedido por preference_id o external_reference
        $order = StoreOrder::where('mp_preference_id', $pay['preference_id'] ?? '')
            ->orWhere('mp_payment_id', $paymentId)
            ->first();

        if ($order) {
            $updates = [
                'mp_payment_id' => $paymentId,
                'mp_status'     => $pay['status'],
            ];
            // Marcar como pagado (listo para que el vendedor confirme con código)
            if ($pay['status'] === 'approved' && in_array($order->status, ['pending'])) {
                $updates['status'] = 'paid';
            }
            $order->update($updates);
        }

        return response()->json(['status' => 'ok']);
    }
}
