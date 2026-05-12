<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IntegratedQuote;
use App\Models\IntegratedQuoteItem;
use App\Models\ServiceRequest;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IntegratedQuoteController extends Controller
{
    private string $mpBase = 'https://api.mercadopago.com';
    private ?bool $hasStoreOrderIntegratedQuoteId = null;
    private ?bool $hasServiceRequestIntegratedQuoteId = null;

    private function mpToken(): string
    {
        return (string) config('services.mercadopago.access_token', '');
    }

    private function frontendBase(): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/');
    }

    private function findQuoteByPublicToken(string $token): ?IntegratedQuote
    {
        // Consulta JSON portable (MySQL/PostgreSQL) para evitar 500 en producción por sintaxis específica.
        return IntegratedQuote::where('metadata->public_token', $token)->first();
    }

    private function isQuoteExpired(IntegratedQuote $quote): bool
    {
        $expiresAt = data_get($quote->metadata, 'expires_at');
        if (!$expiresAt) {
            return false;
        }

        try {
            return Carbon::parse($expiresAt)->isPast();
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasStoreOrderQuoteColumn(): bool
    {
        if ($this->hasStoreOrderIntegratedQuoteId !== null) {
            return $this->hasStoreOrderIntegratedQuoteId;
        }
        return $this->hasStoreOrderIntegratedQuoteId = Schema::hasColumn('store_orders', 'integrated_quote_id');
    }

    private function hasServiceRequestQuoteColumn(): bool
    {
        if ($this->hasServiceRequestIntegratedQuoteId !== null) {
            return $this->hasServiceRequestIntegratedQuoteId;
        }
        return $this->hasServiceRequestIntegratedQuoteId = Schema::hasColumn('service_requests', 'integrated_quote_id');
    }

    private function latestStoreOrderForQuote(int $quoteId): ?StoreOrder
    {
        try {
            return StoreOrder::where('integrated_quote_id', $quoteId)->latest('id')->first();
        } catch (QueryException $e) {
            Log::warning('[IntegratedQuote] store_orders sin integrated_quote_id', [
                'quote_id' => $quoteId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function latestServiceRequestForQuote(int $quoteId): ?ServiceRequest
    {
        try {
            return ServiceRequest::where('integrated_quote_id', $quoteId)->latest('id')->first();
        } catch (QueryException $e) {
            Log::warning('[IntegratedQuote] service_requests sin integrated_quote_id', [
                'quote_id' => $quoteId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * POST /api/v1/integrated-quotes/checkout
     *
     * Crea una cotización integrada que combina:
     * - Materiales (tienda) -> store_orders + link MP
     * - Servicio pre-asignado al worker -> service_requests
     *
     * Nota: el PIN/código de 4 dígitos sigue siendo el de store_orders (confirmación de materiales).
     */
    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'worker_id'          => 'required|integer|exists:workers,id',

            // Materiales (obligatorio para este MVP; si quieres "solo servicio" lo abrimos luego)
            'items'              => 'required|array|min:1',
            'items.*.idproducto' => 'required|integer',
            'items.*.nombre'     => 'required|string',
            'items.*.cantidad'   => 'required|integer|min:1',
            'items.*.precio'     => 'required|numeric|min:0',

            // Datos comprador
            'buyer_name'   => 'required|string|max:100',
            'buyer_email'  => 'required|email',
            'buyer_phone'  => 'nullable|string|max:20',

            // Servicio (opcional, pero recomendado)
            'service'                 => 'nullable|array',
            'service.type'            => 'nullable|in:fixed_job,ride_share,express_errand',
            'service.description'     => 'nullable|string|max:500',
            'service.offered_price'   => 'nullable|numeric|min:0',

            // Delivery (opcional)
            'wants_delivery'   => 'sometimes|boolean',
            'delivery_address' => 'nullable|string|max:255',
            'delivery_lat'     => 'nullable|numeric|between:-90,90',
            'delivery_lng'     => 'nullable|numeric|between:-180,180',

            // Extras (MVP)
            'tool_wear_amount' => 'nullable|integer|min:0',
            'delivery_amount'  => 'nullable|integer|min:0',
        ]);

        $worker = Worker::with('user')->findOrFail($validated['worker_id']);
        if (!$worker->is_seller) {
            return response()->json(['status' => 'error', 'message' => 'Este trabajador no tiene tienda activa'], 422);
        }

        $wantsDelivery = (bool) ($validated['wants_delivery'] ?? false);
        if ($wantsDelivery && empty($validated['delivery_address'])) {
            return response()->json(['status' => 'error', 'message' => 'Falta dirección de delivery'], 422);
        }

        $toolWear = (int) ($validated['tool_wear_amount'] ?? 0);
        $deliveryFee = (int) ($validated['delivery_amount'] ?? 0);

        $materialsAmount = 0;
        foreach ($validated['items'] as $i) {
            $materialsAmount += (int) round($i['precio']) * (int) $i['cantidad'];
        }

        $serviceType = data_get($validated, 'service.type');
        $serviceDesc = data_get($validated, 'service.description');
        $serviceAmount = (int) round((float) (data_get($validated, 'service.offered_price') ?? 0));

        $total = $materialsAmount + $serviceAmount + ($wantsDelivery ? $deliveryFee : 0) + $toolWear;

        $quote = null;
        $order = null;
        $sr = null;

        DB::transaction(function () use (
            $request,
            $validated,
            $worker,
            $wantsDelivery,
            $toolWear,
            $deliveryFee,
            $materialsAmount,
            $serviceType,
            $serviceDesc,
            $serviceAmount,
            $total,
            &$quote,
            &$order,
            &$sr
        ) {
            $quote = IntegratedQuote::create([
                'client_id'        => $request->user()->id,
                'worker_id'        => $worker->id,
                'status'           => 'draft',
                'total_amount'     => $total,
                'service_amount'   => $serviceAmount,
                'materials_amount' => $materialsAmount,
                'delivery_amount'  => $wantsDelivery ? $deliveryFee : 0,
                'tool_wear_amount' => $toolWear,
                'service_type'     => $serviceType,
                'service_description' => $serviceDesc,
                'wants_delivery'   => $wantsDelivery,
                'delivery_address' => $wantsDelivery ? ($validated['delivery_address'] ?? null) : null,
                'delivery_lat'     => $wantsDelivery ? ($validated['delivery_lat'] ?? null) : null,
                'delivery_lng'     => $wantsDelivery ? ($validated['delivery_lng'] ?? null) : null,
                'metadata'         => [
                    'version' => 1,
                ],
            ]);

            foreach ($validated['items'] as $i) {
                IntegratedQuoteItem::create([
                    'integrated_quote_id' => $quote->id,
                    'type' => 'product',
                    'reference_id' => (int) $i['idproducto'],
                    'title' => (string) $i['nombre'],
                    'quantity' => (int) $i['cantidad'],
                    'unit_amount' => (int) round($i['precio']),
                    'subtotal_amount' => (int) round($i['precio']) * (int) $i['cantidad'],
                ]);
            }

            if ($serviceAmount > 0 || $serviceType || $serviceDesc) {
                IntegratedQuoteItem::create([
                    'integrated_quote_id' => $quote->id,
                    'type' => 'service_labor',
                    'reference_id' => null,
                    'title' => $serviceType ? ('Servicio: ' . $serviceType) : 'Servicio',
                    'quantity' => 1,
                    'unit_amount' => $serviceAmount,
                    'subtotal_amount' => $serviceAmount,
                    'metadata' => [
                        'service_type' => $serviceType,
                        'description' => $serviceDesc,
                    ],
                ]);
            }

            if ($wantsDelivery && $deliveryFee > 0) {
                IntegratedQuoteItem::create([
                    'integrated_quote_id' => $quote->id,
                    'type' => 'delivery_fee',
                    'title' => 'Delivery',
                    'quantity' => 1,
                    'unit_amount' => $deliveryFee,
                    'subtotal_amount' => $deliveryFee,
                ]);
            }

            if ($toolWear > 0) {
                IntegratedQuoteItem::create([
                    'integrated_quote_id' => $quote->id,
                    'type' => 'tool_wear',
                    'title' => 'Desgaste/herramientas (estimado)',
                    'quantity' => 1,
                    'unit_amount' => $toolWear,
                    'subtotal_amount' => $toolWear,
                ]);
            }

            // Crear store_order ligado a quote (usa el mismo flujo de confirmación por PIN).
            $confirmationCode = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

            $order = StoreOrder::create([
                'worker_id'           => $worker->id,
                'buyer_name'          => $validated['buyer_name'],
                'buyer_email'         => $validated['buyer_email'],
                'buyer_phone'         => $validated['buyer_phone'] ?? null,
                'items'               => $validated['items'],
                'total'               => (int) $total, // total integrado por ahora (MVP)
                'delivery'            => $wantsDelivery,
                'delivery_address'    => $wantsDelivery ? ($validated['delivery_address'] ?? null) : null,
                'status'              => 'pending',
                'confirmation_code'   => $confirmationCode,
                'expires_at'          => Carbon::now()->addHours(24),
                'integrated_quote_id' => $quote->id,
                'public_token'        => Str::random(48),
            ]);

            // Crear service_request pre-asignado al mismo worker (no matching abierto).
            if ($serviceAmount > 0 || $serviceType || $serviceDesc) {
                $sr = ServiceRequest::create([
                    'integrated_quote_id' => $quote->id,
                    'client_id' => $request->user()->id,
                    'worker_id' => $worker->id,
                    'category_id' => $worker->category_id,
                    'type' => $serviceType ?? 'fixed_job',
                    'category_type' => $serviceType === 'ride_share' ? 'travel' : ($serviceType === 'express_errand' ? 'errand' : 'fixed'),
                    'description' => $serviceDesc ?? null,
                    'urgency' => 'normal',
                    'offered_price' => $serviceAmount > 0 ? $serviceAmount : null,
                    // No expirar en 5 minutos: es un paquete pagable/confirmable (se gestiona por status de quote).
                    'status' => 'pending',
                    'expires_at' => Carbon::now()->addHours(24),
                    'delivery_address' => $wantsDelivery ? ($validated['delivery_address'] ?? null) : null,
                    'delivery_lat' => $wantsDelivery ? ($validated['delivery_lat'] ?? null) : null,
                    'delivery_lng' => $wantsDelivery ? ($validated['delivery_lng'] ?? null) : null,
                    'payload' => [
                        'integrated' => true,
                        'store_order_id' => $order->id,
                    ],
                ]);
            }

            // Marcar quote como esperando pago
            $quote->update(['status' => 'awaiting_payment']);
        });

        // Generar preferencia MP usando external_reference = store_order_id (compatible con webhook actual)
        $mpItems = array_map(fn ($i) => [
            'id'          => 'prod-' . $i['idproducto'],
            'title'       => $i['nombre'],
            'quantity'    => (int) $i['cantidad'],
            'unit_price'  => (float) round($i['precio']),
            'currency_id' => 'CLP',
        ], $validated['items']);

        // En MVP el pago es “paquete”; dejamos la línea de servicio como metadata/extra.
        if ($serviceAmount > 0) {
            $mpItems[] = [
                'id' => 'labor',
                'title' => 'Mano de obra',
                'quantity' => 1,
                'unit_price' => (float) $serviceAmount,
                'currency_id' => 'CLP',
            ];
        }
        if ($wantsDelivery && $deliveryFee > 0) {
            $mpItems[] = [
                'id' => 'delivery',
                'title' => 'Delivery',
                'quantity' => 1,
                'unit_price' => (float) $deliveryFee,
                'currency_id' => 'CLP',
            ];
        }
        if ($toolWear > 0) {
            $mpItems[] = [
                'id' => 'tool-wear',
                'title' => 'Desgaste/herramientas (estimado)',
                'quantity' => 1,
                'unit_price' => (float) $toolWear,
                'currency_id' => 'CLP',
            ];
        }

        $mpPayload = [
            'items'              => $mpItems,
            'payer'              => ['email' => $validated['buyer_email']],
            'external_reference' => (string) $order->id,
            'notification_url'   => config('app.url') . '/api/v1/store/webhook',
            'back_urls' => [
                'success' => $this->frontendBase() . '/tienda/success?confirmation_code=' . $order->confirmation_code . '&external_reference=' . $order->id . '&token=' . $order->public_token,
                'failure' => $this->frontendBase() . '/tienda/failure',
                'pending' => $this->frontendBase() . '/tienda/pending',
            ],
            'auto_return'          => 'approved',
            'statement_descriptor' => 'JobsHours',
            'metadata'             => [
                'worker_id' => $worker->id,
                'store_order_id' => $order->id,
                'integrated_quote_id' => $quote->id,
                'service_request_id' => $sr?->id,
            ],
        ];

        $mpResponse = Http::withToken($this->mpToken())
            ->post("{$this->mpBase}/checkout/preferences", $mpPayload);

        if (!$mpResponse->successful()) {
            Log::error('[IntegratedQuote] Error MP preferencia', ['body' => $mpResponse->json()]);
            return response()->json(['status' => 'error', 'message' => 'Error al generar link de pago'], 500);
        }

        $mpData = $mpResponse->json();
        $payLink = config('app.env') === 'production'
            ? ($mpData['init_point'] ?? null)
            : ($mpData['sandbox_init_point'] ?? ($mpData['init_point'] ?? null));

        $quote->update([
            'payment_link' => $payLink,
            'mp_preference_id' => $mpData['id'] ?? null,
        ]);

        $order->update([
            'mp_preference_id' => $mpData['id'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'quote_id' => $quote->id,
            'store_order_id' => $order->id,
            'service_request_id' => $sr?->id,
            'payment_link' => $payLink,
            'total' => $quote->total_amount,
            'breakdown' => [
                'materials' => $quote->materials_amount,
                'service' => $quote->service_amount,
                'delivery' => $quote->delivery_amount,
                'tool_wear' => $quote->tool_wear_amount,
            ],
            'confirmation_code' => $order->confirmation_code,
            'public_token' => $order->public_token,
        ]);
    }

    /**
     * POST /api/v1/integrated-quotes/worker/create
     *
     * Worker arma una cotización para un comprador y recibe URL pública.
     */
    public function createByWorker(Request $request)
    {
        $validated = $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.idproducto' => 'required|integer',
            'items.*.nombre'     => 'required|string',
            'items.*.cantidad'   => 'required|integer|min:1',
            'items.*.precio'     => 'required|numeric|min:0',
            'buyer_name'         => 'required|string|max:100',
            'buyer_email'        => 'required|email',
            'buyer_phone'        => 'nullable|string|max:20',
            'service'            => 'nullable|array',
            'service.type'       => 'nullable|in:fixed_job,ride_share,express_errand',
            'service.description'=> 'nullable|string|max:500',
            'service.offered_price' => 'nullable|numeric|min:0',
            'wants_delivery'     => 'sometimes|boolean',
            'delivery_address'   => 'nullable|string|max:255',
            'delivery_lat'       => 'nullable|numeric|between:-90,90',
            'delivery_lng'       => 'nullable|numeric|between:-180,180',
            'tool_wear_amount'   => 'nullable|integer|min:0',
            'delivery_amount'    => 'nullable|integer|min:0',
            'expires_in_hours'   => 'nullable|integer|min:1|max:168',
        ]);

        $worker = Worker::where('user_id', $request->user()->id)->with('user')->first();
        if (!$worker) {
            return response()->json(['status' => 'error', 'message' => 'No tienes perfil de worker'], 422);
        }
        if (!$worker->is_seller) {
            return response()->json(['status' => 'error', 'message' => 'Tu tienda no está activa'], 422);
        }

        $wantsDelivery = (bool) ($validated['wants_delivery'] ?? false);
        if ($wantsDelivery && empty($validated['delivery_address'])) {
            return response()->json(['status' => 'error', 'message' => 'Falta dirección de delivery'], 422);
        }

        $toolWear = (int) ($validated['tool_wear_amount'] ?? 0);
        $deliveryFee = (int) ($validated['delivery_amount'] ?? 0);
        $serviceType = data_get($validated, 'service.type');
        $serviceDesc = data_get($validated, 'service.description');
        $serviceAmount = (int) round((float) (data_get($validated, 'service.offered_price') ?? 0));

        $materialsAmount = 0;
        foreach ($validated['items'] as $i) {
            $materialsAmount += (int) round($i['precio']) * (int) $i['cantidad'];
        }
        $total = $materialsAmount + $serviceAmount + ($wantsDelivery ? $deliveryFee : 0) + $toolWear;

        $publicToken = Str::uuid()->toString();
        $expiresAt = Carbon::now()->addHours((int) ($validated['expires_in_hours'] ?? 72));
        $buyerUser = User::where('email', $validated['buyer_email'])->first();

        $quote = null;
        DB::transaction(function () use (
            &$quote,
            $request,
            $worker,
            $validated,
            $buyerUser,
            $total,
            $serviceAmount,
            $materialsAmount,
            $wantsDelivery,
            $deliveryFee,
            $toolWear,
            $serviceType,
            $serviceDesc,
            $publicToken,
            $expiresAt
        ) {
            $quote = IntegratedQuote::create([
                'client_id'        => $buyerUser?->id ?? $request->user()->id,
                'worker_id'        => $worker->id,
                'status'           => 'quote_sent',
                'total_amount'     => $total,
                'service_amount'   => $serviceAmount,
                'materials_amount' => $materialsAmount,
                'delivery_amount'  => $wantsDelivery ? $deliveryFee : 0,
                'tool_wear_amount' => $toolWear,
                'service_type'     => $serviceType,
                'service_description' => $serviceDesc,
                'wants_delivery'   => $wantsDelivery,
                'delivery_address' => $wantsDelivery ? ($validated['delivery_address'] ?? null) : null,
                'delivery_lat'     => $wantsDelivery ? ($validated['delivery_lat'] ?? null) : null,
                'delivery_lng'     => $wantsDelivery ? ($validated['delivery_lng'] ?? null) : null,
                'metadata'         => [
                    'version' => 2,
                    'quote_mode' => 'worker_quote',
                    'buyer_name' => $validated['buyer_name'],
                    'buyer_email' => $validated['buyer_email'],
                    'buyer_phone' => $validated['buyer_phone'] ?? null,
                    'public_token' => $publicToken,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'created_by_user_id' => $request->user()->id,
                ],
            ]);

            foreach ($validated['items'] as $i) {
                IntegratedQuoteItem::create([
                    'integrated_quote_id' => $quote->id,
                    'type' => 'product',
                    'reference_id' => (int) $i['idproducto'],
                    'title' => (string) $i['nombre'],
                    'quantity' => (int) $i['cantidad'],
                    'unit_amount' => (int) round($i['precio']),
                    'subtotal_amount' => (int) round($i['precio']) * (int) $i['cantidad'],
                ]);
            }

            if ($serviceAmount > 0 || $serviceType || $serviceDesc) {
                IntegratedQuoteItem::create([
                    'integrated_quote_id' => $quote->id,
                    'type' => 'service_labor',
                    'title' => $serviceType ? ('Servicio: ' . $serviceType) : 'Servicio',
                    'quantity' => 1,
                    'unit_amount' => $serviceAmount,
                    'subtotal_amount' => $serviceAmount,
                    'metadata' => [
                        'service_type' => $serviceType,
                        'description' => $serviceDesc,
                    ],
                ]);
            }

            if ($wantsDelivery && $deliveryFee > 0) {
                IntegratedQuoteItem::create([
                    'integrated_quote_id' => $quote->id,
                    'type' => 'delivery_fee',
                    'title' => 'Delivery',
                    'quantity' => 1,
                    'unit_amount' => $deliveryFee,
                    'subtotal_amount' => $deliveryFee,
                ]);
            }

            if ($toolWear > 0) {
                IntegratedQuoteItem::create([
                    'integrated_quote_id' => $quote->id,
                    'type' => 'tool_wear',
                    'title' => 'Desgaste/herramientas (estimado)',
                    'quantity' => 1,
                    'unit_amount' => $toolWear,
                    'subtotal_amount' => $toolWear,
                ]);
            }
        });

        $publicUrl = $this->frontendBase() . '/tienda/cotizacion/' . $publicToken;

        return response()->json([
            'status' => 'success',
            'quote_id' => $quote?->id,
            'public_token' => $publicToken,
            'public_url' => $publicUrl,
            'expires_at' => $expiresAt->toIso8601String(),
            'total' => $total,
        ]);
    }

    /**
     * GET /api/v1/integrated-quotes/worker
     */
    public function workerQuotes(Request $request)
    {
        $worker = Worker::where('user_id', $request->user()->id)->first();
        if (!$worker) {
            return response()->json(['status' => 'error', 'message' => 'No eres worker'], 404);
        }

        $quotes = IntegratedQuote::with('items')
            ->where('worker_id', $worker->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $data = $quotes->map(function (IntegratedQuote $quote) {
            $order = $this->latestStoreOrderForQuote((int) $quote->id);
            $token = data_get($quote->metadata, 'public_token');
            $expiresAt = data_get($quote->metadata, 'expires_at');

            return [
                'id' => $quote->id,
                'status' => $quote->status,
                'total_amount' => $quote->total_amount,
                'materials_amount' => $quote->materials_amount,
                'service_amount' => $quote->service_amount,
                'delivery_amount' => $quote->delivery_amount,
                'tool_wear_amount' => $quote->tool_wear_amount,
                'buyer_name' => data_get($quote->metadata, 'buyer_name'),
                'buyer_email' => data_get($quote->metadata, 'buyer_email'),
                'buyer_phone' => data_get($quote->metadata, 'buyer_phone'),
                'public_token' => $token,
                'public_url' => $token ? ($this->frontendBase() . '/tienda/cotizacion/' . $token) : null,
                'expires_at' => $expiresAt,
                'expired' => $this->isQuoteExpired($quote),
                'payment_link' => $quote->payment_link,
                'created_at' => $quote->created_at,
                'items' => $quote->items->map(fn (IntegratedQuoteItem $i) => [
                    'type' => $i->type,
                    'title' => $i->title,
                    'quantity' => $i->quantity,
                    'unit_amount' => $i->unit_amount,
                    'subtotal_amount' => $i->subtotal_amount,
                ])->values(),
                'store_order' => $order ? [
                    'id' => $order->id,
                    'status' => $order->status,
                    'mp_status' => $order->mp_status,
                ] : null,
            ];
        });

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    /**
     * GET /api/v1/integrated-quotes/public/{token}
     */
    public function showPublic(string $token)
    {
        $quote = $this->findQuoteByPublicToken($token);
        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Cotización no encontrada'], 404);
        }

        $quote->loadMissing('items', 'worker.user');
        if ($this->isQuoteExpired($quote) && !in_array($quote->status, ['paid', 'closed', 'materials_confirmed', 'service_completed'], true)) {
            return response()->json(['status' => 'error', 'message' => 'Cotización expirada'], 410);
        }

        $order = $this->latestStoreOrderForQuote((int) $quote->id);
        $serviceRequest = $this->latestServiceRequestForQuote((int) $quote->id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'quote' => [
                    'id' => $quote->id,
                    'status' => $quote->status,
                    'total_amount' => $quote->total_amount,
                    'materials_amount' => $quote->materials_amount,
                    'service_amount' => $quote->service_amount,
                    'delivery_amount' => $quote->delivery_amount,
                    'tool_wear_amount' => $quote->tool_wear_amount,
                    'service_type' => $quote->service_type,
                    'service_description' => $quote->service_description,
                    'wants_delivery' => $quote->wants_delivery,
                    'delivery_address' => $quote->delivery_address,
                    'payment_link' => $quote->payment_link,
                    'buyer_name' => data_get($quote->metadata, 'buyer_name'),
                    'buyer_email' => data_get($quote->metadata, 'buyer_email'),
                    'buyer_phone' => data_get($quote->metadata, 'buyer_phone'),
                    'expires_at' => data_get($quote->metadata, 'expires_at'),
                    'items' => $quote->items->map(fn (IntegratedQuoteItem $i) => [
                        'type' => $i->type,
                        'title' => $i->title,
                        'quantity' => $i->quantity,
                        'unit_amount' => $i->unit_amount,
                        'subtotal_amount' => $i->subtotal_amount,
                    ])->values(),
                ],
                'worker' => [
                    'id' => $quote->worker_id,
                    'name' => $quote->worker?->user?->name,
                    'store_name' => $quote->worker?->store_name,
                ],
                'store_order' => $order ? [
                    'id' => $order->id,
                    'status' => $order->status,
                    'mp_status' => $order->mp_status,
                    'confirmation_code' => $order->confirmation_code,
                ] : null,
                'service_request' => $serviceRequest ? [
                    'id' => $serviceRequest->id,
                    'status' => $serviceRequest->status,
                ] : null,
            ],
        ]);
    }

    /**
     * POST /api/v1/integrated-quotes/public/{token}/checkout
     * Comprador acepta cotización y obtiene link de pago.
     */
    public function publicCheckout(Request $request, string $token)
    {
        $quote = $this->findQuoteByPublicToken($token);
        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Cotización no encontrada'], 404);
        }
        if ($this->isQuoteExpired($quote) && !in_array($quote->status, ['paid', 'closed'], true)) {
            return response()->json(['status' => 'error', 'message' => 'Cotización expirada'], 410);
        }

        $quote->loadMissing('items');
        $worker = Worker::with('user')->find($quote->worker_id);
        if (!$worker || !$worker->is_seller) {
            return response()->json(['status' => 'error', 'message' => 'Tienda del worker no disponible'], 422);
        }

        $validated = $request->validate([
            'buyer_name' => 'nullable|string|max:100',
            'buyer_email' => 'nullable|email',
            'buyer_phone' => 'nullable|string|max:20',
        ]);

        $buyerName = (string) ($validated['buyer_name'] ?? data_get($quote->metadata, 'buyer_name'));
        $buyerEmail = (string) ($validated['buyer_email'] ?? data_get($quote->metadata, 'buyer_email'));
        $buyerPhone = (string) ($validated['buyer_phone'] ?? data_get($quote->metadata, 'buyer_phone', ''));

        if (!$buyerName || !$buyerEmail) {
            return response()->json(['status' => 'error', 'message' => 'Faltan datos del comprador'], 422);
        }

        $existingOrder = $this->latestStoreOrderForQuote((int) $quote->id);
        if ($existingOrder && $quote->payment_link) {
            return response()->json([
                'status' => 'success',
                'quote_id' => $quote->id,
                'store_order_id' => $existingOrder->id,
                'payment_link' => $quote->payment_link,
                'confirmation_code' => $existingOrder->confirmation_code,
                'public_token' => $existingOrder->public_token,
                'total' => $quote->total_amount,
            ]);
        }

        $productItems = $quote->items
            ->where('type', 'product')
            ->map(fn (IntegratedQuoteItem $i) => [
                'idproducto' => (int) ($i->reference_id ?? 0),
                'nombre' => (string) ($i->title ?? 'Producto'),
                'cantidad' => (int) $i->quantity,
                'precio' => (int) $i->unit_amount,
            ])->values()->all();

        if (count($productItems) === 0) {
            return response()->json(['status' => 'error', 'message' => 'La cotización no tiene productos'], 422);
        }

        $order = null;
        $serviceRequest = null;
        DB::transaction(function () use (
            &$order,
            &$serviceRequest,
            $quote,
            $worker,
            $buyerName,
            $buyerEmail,
            $buyerPhone,
            $productItems
        ) {
            $confirmationCode = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

            $orderPayload = [
                'worker_id' => $worker->id,
                'buyer_name' => $buyerName,
                'buyer_email' => $buyerEmail,
                'buyer_phone' => $buyerPhone ?: null,
                'items' => $productItems,
                'total' => (int) $quote->total_amount,
                'delivery' => (bool) $quote->wants_delivery,
                'delivery_address' => $quote->wants_delivery ? $quote->delivery_address : null,
                'status' => 'pending',
                'confirmation_code' => $confirmationCode,
                'expires_at' => Carbon::now()->addHours(24),
                'public_token' => Str::random(48),
            ];
            if ($this->hasStoreOrderQuoteColumn()) {
                $orderPayload['integrated_quote_id'] = $quote->id;
            }
            $order = StoreOrder::create($orderPayload);

            if ((int) $quote->service_amount > 0 || $quote->service_type || $quote->service_description) {
                $servicePayload = [
                    'client_id' => $quote->client_id,
                    'worker_id' => $worker->id,
                    'category_id' => $worker->category_id,
                    'type' => $quote->service_type ?? 'fixed_job',
                    'category_type' => $quote->service_type === 'ride_share' ? 'travel' : ($quote->service_type === 'express_errand' ? 'errand' : 'fixed'),
                    'description' => $quote->service_description,
                    'urgency' => 'normal',
                    'offered_price' => $quote->service_amount > 0 ? $quote->service_amount : null,
                    'status' => 'pending',
                    'expires_at' => Carbon::now()->addHours(24),
                    'delivery_address' => $quote->wants_delivery ? $quote->delivery_address : null,
                    'delivery_lat' => $quote->wants_delivery ? $quote->delivery_lat : null,
                    'delivery_lng' => $quote->wants_delivery ? $quote->delivery_lng : null,
                    'payload' => [
                        'integrated' => true,
                        'store_order_id' => $order->id,
                    ],
                ];
                if ($this->hasServiceRequestQuoteColumn()) {
                    $servicePayload['integrated_quote_id'] = $quote->id;
                }
                $serviceRequest = ServiceRequest::create($servicePayload);
            }
        });

        $mpItems = array_map(fn ($i) => [
            'id'          => 'prod-' . $i['idproducto'],
            'title'       => $i['nombre'],
            'quantity'    => (int) $i['cantidad'],
            'unit_price'  => (float) $i['precio'],
            'currency_id' => 'CLP',
        ], $productItems);

        if ((int) $quote->service_amount > 0) {
            $mpItems[] = [
                'id' => 'labor',
                'title' => 'Mano de obra',
                'quantity' => 1,
                'unit_price' => (float) $quote->service_amount,
                'currency_id' => 'CLP',
            ];
        }
        if ((int) $quote->delivery_amount > 0) {
            $mpItems[] = [
                'id' => 'delivery',
                'title' => 'Delivery',
                'quantity' => 1,
                'unit_price' => (float) $quote->delivery_amount,
                'currency_id' => 'CLP',
            ];
        }
        if ((int) $quote->tool_wear_amount > 0) {
            $mpItems[] = [
                'id' => 'tool-wear',
                'title' => 'Desgaste/herramientas (estimado)',
                'quantity' => 1,
                'unit_price' => (float) $quote->tool_wear_amount,
                'currency_id' => 'CLP',
            ];
        }

        $mpPayload = [
            'items'              => $mpItems,
            'payer'              => ['email' => $buyerEmail],
            'external_reference' => (string) $order->id,
            'notification_url'   => config('app.url') . '/api/v1/store/webhook',
            'back_urls' => [
                'success' => $this->frontendBase() . '/tienda/success?confirmation_code=' . $order->confirmation_code . '&external_reference=' . $order->id . '&token=' . $order->public_token,
                'failure' => $this->frontendBase() . '/tienda/failure',
                'pending' => $this->frontendBase() . '/tienda/pending',
            ],
            'auto_return' => 'approved',
            'statement_descriptor' => 'JobsHours',
            'metadata' => [
                'worker_id' => $worker->id,
                'store_order_id' => $order->id,
                'integrated_quote_id' => $quote->id,
                'service_request_id' => $serviceRequest?->id,
                'public_token' => $token,
            ],
        ];

        $mpResponse = Http::withToken($this->mpToken())
            ->post("{$this->mpBase}/checkout/preferences", $mpPayload);

        if (!$mpResponse->successful()) {
            Log::error('[IntegratedQuote] Error MP preferencia public checkout', ['body' => $mpResponse->json(), 'quote_id' => $quote->id]);
            return response()->json(['status' => 'error', 'message' => 'Error al generar link de pago'], 500);
        }

        $mpData = $mpResponse->json();
        $payLink = config('app.env') === 'production'
            ? ($mpData['init_point'] ?? null)
            : ($mpData['sandbox_init_point'] ?? ($mpData['init_point'] ?? null));

        $quote->update([
            'status' => 'awaiting_payment',
            'payment_link' => $payLink,
            'mp_preference_id' => $mpData['id'] ?? null,
        ]);
        $order->update(['mp_preference_id' => $mpData['id'] ?? null]);

        return response()->json([
            'status' => 'success',
            'quote_id' => $quote->id,
            'store_order_id' => $order->id,
            'service_request_id' => $serviceRequest?->id,
            'payment_link' => $payLink,
            'confirmation_code' => $order->confirmation_code,
            'public_token' => $order->public_token,
            'total' => $quote->total_amount,
        ]);
    }
}

