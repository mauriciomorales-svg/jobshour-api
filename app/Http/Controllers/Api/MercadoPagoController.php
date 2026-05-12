<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMercadoPagoWebhook;
use App\Models\ServiceRequest;
use App\Services\FCMService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoPagoController extends Controller
{
    private string $accessToken;
    private string $baseUrl = 'https://api.mercadopago.com';

    public function __construct()
    {
        $this->accessToken = (string) (config('mercadopago.access_token') ?? '');
    }

    private function isWebhookSignatureValid(Request $request): bool
    {
        $secret = trim((string) config('services.mercadopago.webhook_secret', ''));
        if ($secret === '') {
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
     * Generar link de pago y enviarlo por chat al finalizar el trabajo
     */
    public function createPaymentLink(Request $request)
    {
        if (trim($this->accessToken) === '') {
            Log::warning('[MP] createPaymentLink sin access_token configurado');
            return response()->json(['status' => 'error', 'message' => 'Mercado Pago no está configurado en el servidor'], 503);
        }

        $request->validate([
            'service_request_id' => 'required|integer|exists:service_requests,id',
        ]);

        $serviceRequest = ServiceRequest::with(['worker.user', 'client'])->findOrFail($request->service_request_id);

        $workerId = auth()->id();
        if (! $workerId) {
            return response()->json(['status' => 'error', 'message' => 'No autenticado'], 401);
        }
        if (! $serviceRequest->worker || ! $serviceRequest->worker->user) {
            Log::warning('[MP] createPaymentLink sin worker asociado', ['service_request_id' => $serviceRequest->id]);
            return response()->json(['status' => 'error', 'message' => 'La solicitud no tiene trabajador asignado'], 422);
        }
        if (! $serviceRequest->client) {
            Log::warning('[MP] createPaymentLink sin cliente asociado', ['service_request_id' => $serviceRequest->id]);
            return response()->json(['status' => 'error', 'message' => 'La solicitud no tiene cliente asociado'], 422);
        }
        if ((int) $serviceRequest->worker->user_id !== (int) $workerId) {
            return response()->json(['status' => 'error', 'message' => 'Solo el trabajador puede solicitar el pago'], 403);
        }

        $baseClp = $serviceRequest->mercadoPagoBasePriceClp();
        $pricingSource = $serviceRequest->mercadoPagoPricingSource();
        $amount = (int) round($baseClp * 1.08);
        Log::info('[MP] createPaymentLink pricing', [
            'service_request_id' => $serviceRequest->id,
            'base_clp' => $baseClp,
            'charged_clp' => $amount,
            'source' => $pricingSource,
            'final_price' => $serviceRequest->final_price,
            'offered_price' => $serviceRequest->offered_price,
        ]);
        $clientEmail = $serviceRequest->client->email ?? 'cliente@jobshours.com';
        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $resultBase = $frontend . '/pago/resultado';
        $resultQuery = 'service_request_id=' . $serviceRequest->id;

        $payload = [
            'items' => [[
                'id'          => 'sr-' . $serviceRequest->id,
                'title'       => 'JobsHours - Servicio #' . $serviceRequest->id,
                'quantity'    => 1,
                'unit_price'  => (float) $amount,
                'currency_id' => 'CLP',
            ]],
            'external_reference' => (string) $serviceRequest->id,
            'notification_url'   => config('app.url') . '/api/v1/payments/mp/webhook',
            'back_urls' => [
                'success' => $resultBase . '?status=success&' . $resultQuery,
                'failure' => $resultBase . '?status=failure&' . $resultQuery,
                'pending' => $resultBase . '?status=pending&' . $resultQuery,
            ],
            'auto_return' => 'approved',
            'statement_descriptor' => 'JobsHours',
            'metadata' => ['service_request_id' => $serviceRequest->id],
        ];
        if (is_string($clientEmail) && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            $payload['payer'] = ['email' => $clientEmail];
        }

        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/checkout/preferences", $payload);

        if (!$response->successful()) {
            Log::error('[MP] Error creando preferencia', [
                'status' => $response->status(),
                'body' => $response->json(),
                'service_request_id' => $serviceRequest->id,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo generar el link de pago con Mercado Pago',
                'mp_status' => $response->status(),
            ], 502);
        }

        $data = $response->json();
        if (! is_array($data)) {
            Log::error('[MP] Preferencia: respuesta no JSON', ['raw' => $response->body()]);

            return response()->json(['status' => 'error', 'message' => 'Respuesta inválida de Mercado Pago'], 502);
        }

        $initPoint = config('app.env') === 'production'
            ? ($data['init_point'] ?? '')
            : ($data['sandbox_init_point'] ?? $data['init_point'] ?? '');

        if ($initPoint === '') {
            Log::error('[MP] Preferencia sin init_point', ['preference_id' => $data['id'] ?? null]);

            return response()->json(['status' => 'error', 'message' => 'Mercado Pago no devolvió URL de pago (revisá APP_ENV vs credenciales prod/test)'], 502);
        }

        $serviceRequest->update([
            'mp_preference_id' => $data['id'],
            'mp_status'        => 'pending_payment',
            'payment_status'   => 'pending',
        ]);

        $messageBody = json_encode([
            'type'       => 'payment_link',
            'amount'     => $amount,
            'link'       => $initPoint,
            'service_id' => $serviceRequest->id,
        ]);

        $serviceRequest->messages()->create([
            'sender_id' => $workerId,
            'body'      => $messageBody,
            // Tabla messages permite: text, image, location, system
            // El subtipo "payment_link" viaja dentro del body JSON.
            'type'      => 'system',
        ]);

        // Push al cliente
        $client = $serviceRequest->client;
        if ($client) {
            $workerName = $serviceRequest->worker->user->name ?? 'Tu trabajador';
            app(FCMService::class)->sendToUser(
                $client,
                '💳 Solicitud de pago',
                "{$workerName} ha finalizado el trabajo y solicita el pago de $" . number_format($amount, 0, ',', '.') . " CLP",
                [
                    'type'               => 'payment_request',
                    'service_request_id' => (string) $serviceRequest->id,
                    'amount'             => (string) $amount,
                ]
            );
        }

        return response()->json([
            'status'     => 'success',
            'link'       => $initPoint,
            'amount'     => $amount,
            'preference' => $data['id'],
            'pricing'    => [
                'base_clp' => $baseClp,
                'charged_clp' => $amount,
                'factor' => 1.08,
                'source' => $pricingSource,
            ],
        ]);
    }

    /**
     * Paso 1: Crear preferencia de pago (capture: false = retención)
     */
    public function initPayment(Request $request)
    {
        $request->validate([
            'service_request_id' => 'required|integer|exists:service_requests,id',
        ]);

        $serviceRequest = ServiceRequest::with('worker.user')->findOrFail($request->service_request_id);

        if ($serviceRequest->client_id !== auth()->id()) {
            return response()->json(['status' => 'error', 'message' => 'No autorizado'], 403);
        }

        if (trim($this->accessToken) === '') {
            Log::warning('[MP] initPayment sin access_token configurado');

            return response()->json(['status' => 'error', 'message' => 'Mercado Pago no está configurado en el servidor'], 503);
        }

        $baseClp = $serviceRequest->mercadoPagoBasePriceClp();
        if ($baseClp <= 0) {
            return response()->json(['status' => 'error', 'message' => 'La solicitud no tiene precio válido para cobrar'], 422);
        }

        $amount = round((float) $baseClp * 1.08, 2); // +8% comisión

        $payload = [
            'transaction_amount' => $amount,
            'description'        => 'JobsHours - Servicio #' . $serviceRequest->id,
            'payment_method_id'  => 'visa', // se sobreescribe desde el brick
            'capture'            => false,
            'external_reference' => (string) $serviceRequest->id,
            'notification_url'   => config('app.url') . '/api/v1/payments/mp/webhook',
            'metadata'           => [
                'service_request_id' => $serviceRequest->id,
                'worker_id'          => $serviceRequest->worker_id,
            ],
        ];

        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/v1/payments", $payload);

        if (! $response->successful()) {
            Log::error('[MP] Error creando pago', ['body' => $response->json()]);
            return response()->json(['status' => 'error', 'message' => 'Error al iniciar pago'], 500);
        }

        $data = $response->json();
        if (! is_array($data) || ! isset($data['id'], $data['status'])) {
            Log::error('[MP] initPayment: cuerpo inesperado', ['body' => $data]);

            return response()->json(['status' => 'error', 'message' => 'Respuesta inválida de Mercado Pago'], 502);
        }

        $serviceRequest->update([
            'mp_payment_id' => $data['id'],
            'mp_status'     => $data['status'],
            'payment_status'=> 'pending',
        ]);

        return response()->json([
            'status'     => 'success',
            'payment_id' => $data['id'],
            'mp_status'  => $data['status'],
            'amount'     => $amount,
            'pricing'    => [
                'base_clp' => $baseClp,
                'source' => $serviceRequest->mercadoPagoPricingSource(),
            ],
        ]);
    }

    /**
     * Paso 1b: Procesar pago con token del Payment Brick
     */
    public function processPayment(Request $request)
    {
        $request->validate([
            'service_request_id' => 'required|integer|exists:service_requests,id',
            'token'              => 'required|string',
            'payment_method_id'  => 'required|string',
            'installments'       => 'required|integer',
            /** El brick puede enviar issuer_id numérico (JSON number) */
            'issuer_id'          => 'nullable',
            'payer'              => 'nullable|array',
        ]);

        $serviceRequest = ServiceRequest::with('worker.user')->findOrFail($request->service_request_id);

        if ($serviceRequest->client_id !== auth()->id()) {
            return response()->json(['status' => 'error', 'message' => 'No autorizado'], 403);
        }

        if (trim($this->accessToken) === '') {
            Log::warning('[MP] processPayment sin access_token configurado');

            return response()->json(['status' => 'error', 'message' => 'Mercado Pago no está configurado en el servidor'], 503);
        }

        $baseClp = $serviceRequest->mercadoPagoBasePriceClp();
        if ($baseClp <= 0) {
            return response()->json(['status' => 'error', 'message' => 'La solicitud no tiene precio válido para cobrar'], 422);
        }

        $amount = round((float) $baseClp * 1.08, 2);

        $issuerId = $request->input('issuer_id');
        $issuerId = ($issuerId !== null && $issuerId !== '') ? (string) $issuerId : null;

        $payer = $request->input('payer', []);
        if (! is_array($payer)) {
            $payer = [];
        }
        if (empty($payer['email']) && auth()->check()) {
            $payer['email'] = (string) auth()->user()->email;
        }

        $payload = [
            'transaction_amount' => $amount,
            'token'              => $request->token,
            'description'        => 'JobsHours - Servicio #' . $serviceRequest->id,
            'installments'       => $request->installments,
            'payment_method_id'  => $request->payment_method_id,
            'issuer_id'          => $issuerId,
            'capture'            => false,
            'external_reference' => (string) $serviceRequest->id,
            'notification_url'   => config('app.url') . '/api/v1/payments/mp/webhook',
            'payer'              => $payer,
            'metadata'           => [
                'service_request_id' => $serviceRequest->id,
                'worker_id'          => $serviceRequest->worker_id,
            ],
        ];

        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/v1/payments", $payload);

        $body = $response->json();

        if (! $response->successful()) {
            Log::error('[MP] Error procesando pago', ['body' => $body, 'status' => $response->status()]);
            $msg = is_array($body) ? ($body['message'] ?? 'Error al procesar pago') : 'Error al procesar pago';

            return response()->json([
                'status'  => 'error',
                'message' => $msg,
            ], 422);
        }

        if (! is_array($body) || ! isset($body['id'], $body['status'])) {
            Log::error('[MP] processPayment: cuerpo inesperado', ['body' => $body]);

            return response()->json(['status' => 'error', 'message' => 'Respuesta inválida de Mercado Pago'], 502);
        }

        $data = $body;

        $serviceRequest->update([
            'mp_payment_id' => $data['id'],
            'mp_status'     => $data['status'],
            'payment_status'=> 'pending',
        ]);

        return response()->json([
            'status'     => 'success',
            'payment_id' => $data['id'],
            'mp_status'  => $data['status'],
            'amount'     => $amount,
        ]);
    }

    /**
     * Paso 2: Capturar fondos (trabajo finalizado)
     */
    public function capturePayment(Request $request, $serviceRequestId)
    {
        $serviceRequest = ServiceRequest::findOrFail($serviceRequestId);
        $user = $request->user();

        $isClient = (int) $serviceRequest->client_id === (int) $user->id;
        $isWorker = $serviceRequest->worker && (int) $serviceRequest->worker->user_id === (int) $user->id;
        if (! $isClient && ! $isWorker) {
            return response()->json(['status' => 'error', 'message' => 'No autorizado'], 403);
        }

        if (!$serviceRequest->mp_payment_id) {
            return response()->json(['status' => 'error', 'message' => 'Sin pago MP asociado'], 400);
        }

        $response = Http::withToken($this->accessToken)
            ->put("{$this->baseUrl}/v1/payments/{$serviceRequest->mp_payment_id}", [
                'capture' => true,
            ]);

        if (!$response->successful()) {
            Log::error('[MP] Error capturando pago', ['body' => $response->json()]);
            return response()->json(['status' => 'error', 'message' => 'Error al capturar pago'], 500);
        }

        $data = $response->json();

        $serviceRequest->update([
            'mp_status' => $data['status'],
            'status'    => 'completed',
        ]);

        return response()->json(['status' => 'success', 'mp_status' => $data['status']]);
    }

    /**
     * Checkout Pro: destacar demanda pendiente en el mapa (pago; aplica boosted_via webhook).
     */
    public function createDemandBoostCheckout(Request $request)
    {
        $validated = $request->validate([
            'service_request_id' => 'required|integer|exists:service_requests,id',
            'hours' => 'nullable|integer|min:1|max:336',
        ]);

        $sr = ServiceRequest::findOrFail($validated['service_request_id']);

        if ($sr->client_id !== auth()->id()) {
            return response()->json(['status' => 'error', 'message' => 'No autorizado'], 403);
        }

        if ($sr->status !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'Solo demandas pendientes pueden destacarse'], 422);
        }

        $hours = $validated['hours'] ?? (int) config('services.boost.default_hours', 24);
        $price = (int) config('services.boost.demand_price_clp', 4990);
        $user = auth()->user();

        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        $payload = [
            'items' => [[
                'id' => 'boost-demand-'.$sr->id,
                'title' => 'Destacar demanda #'.$sr->id.' en el mapa ('.$hours.'h)',
                'quantity' => 1,
                'unit_price' => (float) $price,
                'currency_id' => 'CLP',
            ]],
            'payer' => ['email' => $user->email ?? 'cliente@jobshour.cl'],
            'external_reference' => 'boost:'.$sr->id,
            'notification_url' => rtrim((string) config('app.url'), '/').'/api/v1/payments/mp/webhook',
            'metadata' => [
                'type' => 'demand_boost',
                'service_request_id' => $sr->id,
                'boost_hours' => $hours,
            ],
            'back_urls' => [
                'success' => $frontend.'/?boost=ok',
                'failure' => $frontend.'/?boost=fail',
                'pending' => $frontend.'/?boost=pending',
            ],
            'auto_return' => 'approved',
            'statement_descriptor' => 'JH BOOST',
        ];

        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/checkout/preferences", $payload);

        if (! $response->successful()) {
            Log::error('[MP] Error preferencia boost', ['body' => $response->json()]);

            return response()->json(['status' => 'error', 'message' => 'No se pudo iniciar el pago'], 500);
        }

        $data = $response->json();
        $initPoint = config('app.env') === 'production'
            ? ($data['init_point'] ?? '')
            : ($data['sandbox_init_point'] ?? $data['init_point'] ?? '');

        return response()->json([
            'status' => 'success',
            'link' => $initPoint,
            'preference_id' => $data['id'] ?? null,
            'amount_clp' => $price,
            'hours' => $hours,
        ]);
    }

    /**
     * GET /api/v1/payments/mp/credits-packs
     * Devuelve los paquetes de créditos disponibles.
     */
    public function creditsPacks(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'packs' => config('services.credits.packs', []),
        ]);
    }

    /**
     * POST /api/v1/payments/mp/credits-checkout
     * Crea preferencia MP para comprar un paquete de créditos.
     * Body: { pack_id: 'pack15' }
     */
    public function createCreditsCheckout(Request $request): \Illuminate\Http\JsonResponse
    {
        if (trim($this->accessToken) === '') {
            return response()->json(['status' => 'error', 'message' => 'Mercado Pago no configurado'], 503);
        }

        $validated = $request->validate([
            'pack_id' => 'required|string|max:32',
        ]);

        $packs = collect(config('services.credits.packs', []));
        $pack = $packs->firstWhere('id', $validated['pack_id']);

        if (! $pack) {
            return response()->json(['status' => 'error', 'message' => 'Paquete no encontrado'], 422);
        }

        $user = auth()->user();
        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        $payload = [
            'items' => [[
                'id'          => 'credits-' . $pack['id'],
                'title'       => 'JobsHours – ' . $pack['label'],
                'quantity'    => 1,
                'unit_price'  => (float) $pack['price_clp'],
                'currency_id' => 'CLP',
            ]],
            'external_reference' => 'credits:' . $user->id . ':' . $pack['id'],
            'notification_url'   => rtrim((string) config('app.url'), '/') . '/api/v1/payments/mp/webhook',
            'back_urls' => [
                'success' => $frontend . '/?credits=ok&pack=' . $pack['id'],
                'failure' => $frontend . '/?credits=fail',
                'pending' => $frontend . '/?credits=pending',
            ],
            'auto_return'          => 'approved',
            'statement_descriptor' => 'JH CREDITOS',
            'metadata' => [
                'type'       => 'credits_purchase',
                'user_id'    => $user->id,
                'pack_id'    => $pack['id'],
                'credits'    => $pack['credits'],
            ],
        ];

        if (filter_var($user->email ?? '', FILTER_VALIDATE_EMAIL)) {
            $payload['payer'] = ['email' => $user->email];
        }

        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/checkout/preferences", $payload);

        if (! $response->successful()) {
            Log::error('[MP] Error preferencia créditos', ['body' => $response->json()]);
            return response()->json(['status' => 'error', 'message' => 'No se pudo iniciar el pago'], 500);
        }

        $data = $response->json();
        $initPoint = config('app.env') === 'production'
            ? ($data['init_point'] ?? '')
            : ($data['sandbox_init_point'] ?? $data['init_point'] ?? '');

        return response()->json([
            'status'        => 'success',
            'link'          => $initPoint,
            'preference_id' => $data['id'] ?? null,
            'pack'          => $pack,
        ]);
    }

    /**
     * Clave pública para el Payment Brick (dato no secreto; evita duplicar NEXT_PUBLIC_MP_PUBLIC_KEY en el front).
     */
    public function brickConfig(): \Illuminate\Http\JsonResponse
    {
        $pk = trim((string) (config('mercadopago.public_key') ?? ''));

        return response()->json([
            'public_key' => $pk,
            'available' => $pk !== '',
        ]);
    }

    /**
     * Webhook de Mercado Pago
     */
    public function webhook(Request $request)
    {
        if (! $this->isWebhookSignatureValid($request)) {
            Log::warning('[MP] Webhook firma inválida', [
                'ip' => $request->ip(),
                'x_request_id' => $request->header('x-request-id'),
            ]);
            return response()->json(['status' => 'invalid_signature'], 401);
        }

        Log::info('[MP] Webhook recibido', $request->all());

        $type = $request->input('type') ?? $request->input('topic');

        if ($type !== 'payment') {
            return response()->json(['status' => 'ignored']);
        }

        $paymentId = $request->input('data.id') ?? $request->input('id');

        if (!$paymentId) {
            return response()->json(['status' => 'no_id']);
        }

        $paymentId = (string) $paymentId;

        if (config('mercadopago.webhook_sync', false)) {
            try {
                $result = app(\App\Services\MercadoPagoWebhookProcessor::class)->processByPaymentId($paymentId);

                return response()->json(['status' => 'ok_sync', 'result' => $result]);
            } catch (\Throwable $e) {
                Log::error('[MP] Webhook sync falló', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);

                return response()->json(['status' => 'error', 'message' => 'sync_failed'], 500);
            }
        }

        ProcessMercadoPagoWebhook::dispatch($paymentId);

        return response()->json(['status' => 'accepted', 'payment_id' => $paymentId]);
    }
}
