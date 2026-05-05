<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

    /**
     * Generar link de pago y enviarlo por chat al finalizar el trabajo
     */
    public function createPaymentLink(Request $request)
    {
        $request->validate([
            'service_request_id' => 'required|integer|exists:service_requests,id',
        ]);

        $serviceRequest = ServiceRequest::with(['worker.user', 'client'])->findOrFail($request->service_request_id);

        $workerId = auth()->id();
        if ($serviceRequest->worker->user_id !== $workerId) {
            return response()->json(['status' => 'error', 'message' => 'Solo el trabajador puede solicitar el pago'], 403);
        }

        $tarifa   = $serviceRequest->agreed_price ?? $serviceRequest->worker->hourly_rate ?? 10000;
        $amount   = round($tarifa * 1.08);
        $clientEmail = $serviceRequest->client->email ?? 'cliente@jobshours.com';

        $payload = [
            'items' => [[
                'id'          => 'sr-' . $serviceRequest->id,
                'title'       => 'JobsHours - Servicio #' . $serviceRequest->id,
                'quantity'    => 1,
                'unit_price'  => (float) $amount,
                'currency_id' => 'CLP',
            ]],
            'payer' => ['email' => $clientEmail],
            'external_reference' => (string) $serviceRequest->id,
            'notification_url'   => config('app.url') . '/api/v1/payments/mp/webhook',
            'back_urls' => [
                'success' => config('app.url') . '/payment/success',
                'failure' => config('app.url') . '/payment/failure',
                'pending' => config('app.url') . '/payment/pending',
            ],
            'auto_return' => 'approved',
            'statement_descriptor' => 'JobsHours',
            'metadata' => ['service_request_id' => $serviceRequest->id],
        ];

        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/checkout/preferences", $payload);

        if (!$response->successful()) {
            Log::error('[MP] Error creando preferencia', ['body' => $response->json()]);
            return response()->json(['status' => 'error', 'message' => 'Error al generar link de pago'], 500);
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
            'status'           => 'pending_payment',
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
            'type'      => 'payment_link',
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

        $base = $serviceRequest->agreed_price;
        if ($base === null || (float) $base <= 0) {
            return response()->json(['status' => 'error', 'message' => 'La solicitud no tiene precio acordado válido'], 422);
        }

        $amount = round((float) $base * 1.08, 2); // +8% comisión

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
        ]);

        return response()->json([
            'status'     => 'success',
            'payment_id' => $data['id'],
            'mp_status'  => $data['status'],
            'amount'     => $amount,
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

        $base = $serviceRequest->agreed_price;
        if ($base === null || (float) $base <= 0) {
            return response()->json(['status' => 'error', 'message' => 'La solicitud no tiene precio acordado válido'], 422);
        }

        $amount = round((float) $base * 1.08, 2);

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
        ]);

        if ($data['status'] === 'authorized') {
            $serviceRequest->update(['status' => 'scheduled']);
        }

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
        Log::info('[MP] Webhook recibido', $request->all());

        $type = $request->input('type') ?? $request->input('topic');

        if ($type !== 'payment') {
            return response()->json(['status' => 'ignored']);
        }

        $paymentId = $request->input('data.id') ?? $request->input('id');

        if (!$paymentId) {
            return response()->json(['status' => 'no_id']);
        }

        $response = Http::withToken($this->accessToken)
            ->get("{$this->baseUrl}/v1/payments/{$paymentId}");

        if (!$response->successful()) {
            return response()->json(['status' => 'error'], 500);
        }

        $payment = $response->json();

        $extRef = (string) ($payment['external_reference'] ?? '');
        if (str_starts_with($extRef, 'boost:')) {
            return $this->applyDemandBoostFromPayment($payment, $extRef);
        }

        $serviceRequestId = is_numeric($extRef) ? (int) $extRef : null;

        if (! $serviceRequestId) {
            return response()->json(['status' => 'no_reference']);
        }

        $serviceRequest = ServiceRequest::find($serviceRequestId);
        if (! $serviceRequest) {
            return response()->json(['status' => 'not_found']);
        }

        $serviceRequest->update(['mp_status' => $payment['status']]);

        if ($payment['status'] === 'authorized') {
            $serviceRequest->update(['status' => 'scheduled']);
            Log::info('[MP] Pago autorizado, servicio agendado', ['sr_id' => $serviceRequestId]);
        } elseif ($payment['status'] === 'approved') {
            $serviceRequest->update(['status' => 'completed']);
            Log::info('[MP] Pago capturado, servicio completado', ['sr_id' => $serviceRequestId]);
        } elseif (in_array($payment['status'], ['cancelled', 'rejected'])) {
            $serviceRequest->update(['status' => 'cancelled']);
            Log::info('[MP] Pago rechazado/cancelado', ['sr_id' => $serviceRequestId]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * @param  array<string,mixed>  $payment
     */
    private function applyDemandBoostFromPayment(array $payment, string $extRef): \Illuminate\Http\JsonResponse
    {
        $srId = (int) substr($extRef, strlen('boost:'));
        if ($srId < 1) {
            return response()->json(['status' => 'bad_boost_ref']);
        }

        $sr = ServiceRequest::find($srId);
        if (! $sr) {
            return response()->json(['status' => 'not_found']);
        }

        $meta = isset($payment['metadata']) && is_array($payment['metadata']) ? $payment['metadata'] : [];
        $hours = (int) ($meta['boost_hours'] ?? config('services.boost.default_hours', 24));

        if (! empty($payment['id'])) {
            $sr->boost_mp_payment_id = (string) $payment['id'];
        }

        if (in_array($payment['status'] ?? '', ['approved', 'authorized'], true)) {
            $base = ($sr->boosted_until && $sr->boosted_until->isFuture()) ? $sr->boosted_until : now();
            $sr->boosted_until = $base->copy()->addHours($hours);
            Log::info('[MP] Boost demanda aplicado', ['sr' => $srId, 'hours' => $hours]);
        } else {
            Log::info('[MP] Boost webhook sin aprobación aún', ['sr' => $srId, 'status' => $payment['status'] ?? null]);
        }

        $sr->save();

        return response()->json(['status' => 'ok_boost']);
    }
}
