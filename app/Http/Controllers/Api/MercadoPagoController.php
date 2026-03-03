<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Services\FCMService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MercadoPagoController extends Controller
{
    private string $accessToken;
    private string $baseUrl = 'https://api.mercadopago.com';

    public function __construct()
    {
        $this->accessToken = config('services.mercadopago.access_token');
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
        $amount   = round($tarifa * 1.10);
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

        $data    = $response->json();
        $initPoint = config('app.env') === 'production'
            ? $data['init_point']
            : $data['sandbox_init_point'];

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

        $amount = round($serviceRequest->agreed_price * 1.10, 2); // +10% comisión

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

        if (!$response->successful()) {
            Log::error('[MP] Error creando pago', ['body' => $response->json()]);
            return response()->json(['status' => 'error', 'message' => 'Error al iniciar pago'], 500);
        }

        $data = $response->json();

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
            'issuer_id'          => 'nullable|string',
            'payer'              => 'required|array',
        ]);

        $serviceRequest = ServiceRequest::with('worker.user')->findOrFail($request->service_request_id);

        if ($serviceRequest->client_id !== auth()->id()) {
            return response()->json(['status' => 'error', 'message' => 'No autorizado'], 403);
        }

        $amount = round($serviceRequest->agreed_price * 1.10, 2);

        $payload = [
            'transaction_amount' => $amount,
            'token'              => $request->token,
            'description'        => 'JobsHours - Servicio #' . $serviceRequest->id,
            'installments'       => $request->installments,
            'payment_method_id'  => $request->payment_method_id,
            'issuer_id'          => $request->issuer_id,
            'capture'            => false,
            'external_reference' => (string) $serviceRequest->id,
            'notification_url'   => config('app.url') . '/api/v1/payments/mp/webhook',
            'payer'              => $request->payer,
            'metadata'           => [
                'service_request_id' => $serviceRequest->id,
                'worker_id'          => $serviceRequest->worker_id,
            ],
        ];

        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/v1/payments", $payload);

        if (!$response->successful()) {
            Log::error('[MP] Error procesando pago', ['body' => $response->json()]);
            return response()->json([
                'status'  => 'error',
                'message' => $response->json()['message'] ?? 'Error al procesar pago',
            ], 422);
        }

        $data = $response->json();

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
        $serviceRequestId = $payment['external_reference'] ?? null;

        if (!$serviceRequestId) {
            return response()->json(['status' => 'no_reference']);
        }

        $serviceRequest = ServiceRequest::find($serviceRequestId);
        if (!$serviceRequest) {
            return response()->json(['status' => 'not_found']);
        }

        $serviceRequest->update(['mp_status' => $payment['status']]);

        if ($payment['status'] === 'authorized') {
            $serviceRequest->update(['status' => 'scheduled']);
            Log::info('[MP] Pago autorizado, servicio agendado', ['sr_id' => $serviceRequestId]);
        } elseif ($payment['status'] === 'approved') {
            $serviceRequest->update(['status' => 'completed']);
            Log::info('[MP] Pago capturado, servicio completado', ['sr_id' => $serviceRequestId]);

            // Notificar al admin por email
            try {
                $amount     = $payment['transaction_amount'] ?? 0;
                $workerName = optional(optional($serviceRequest->worker)->user)->name ?? 'Worker #' . $serviceRequest->worker_id;
                $clientName = optional($serviceRequest->client)->name ?? 'Cliente';
                $adminEmail = config('mail.admin', env('MAIL_ADMIN', 'mauricio.morales@usach.cl'));

                Mail::raw(
                    "💰 PAGO RECIBIDO — JobsHours\n\n" .
                    "🔔 Servicio #: {$serviceRequest->id}\n" .
                    "👷 Worker: {$workerName}\n" .
                    "👤 Cliente: {$clientName}\n" .
                    "💵 Monto cobrado: $" . number_format($amount, 0, ',', '.') . " CLP\n" .
                    "📅 Fecha: " . now()->format('d/m/Y H:i') . "\n\n" .
                    "Recuerda transferir el monto al worker descontando la comisión de JobsHours.",
                    function ($message) use ($adminEmail, $serviceRequest, $amount) {
                        $message->to($adminEmail)
                            ->subject('[JH-PAGO] Servicio #' . $serviceRequest->id . ' — $' . number_format($amount, 0, ',', '.') . ' CLP');
                    }
                );
            } catch (\Throwable $e) {
                Log::warning('[MP] Error enviando email admin', ['error' => $e->getMessage()]);
            }

            // Push notification al admin
            try {
                $amount     = $payment['transaction_amount'] ?? 0;
                $workerName = optional(optional($serviceRequest->worker)->user)->name ?? 'Worker #' . $serviceRequest->worker_id;
                $adminUser  = \App\Models\User::find(config('app.admin_user_id', 24));
                if ($adminUser) {
                    app(FCMService::class)->sendToUser(
                        $adminUser,
                        '💰 Pago recibido',
                        "{$workerName} — $" . number_format($amount, 0, ',', '.') . " CLP — Servicio #{$serviceRequest->id}",
                        [
                            'type'               => 'payment_received',
                            'service_request_id' => (string) $serviceRequest->id,
                            'amount'             => (string) $amount,
                            'sound'              => 'cash_register',
                        ]
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('[MP] Error enviando push admin', ['error' => $e->getMessage()]);
            }
        } elseif (in_array($payment['status'], ['cancelled', 'rejected'])) {
            $serviceRequest->update(['status' => 'cancelled']);
            Log::info('[MP] Pago rechazado/cancelado', ['sr_id' => $serviceRequestId]);
        }

        return response()->json(['status' => 'ok']);
    }
}
