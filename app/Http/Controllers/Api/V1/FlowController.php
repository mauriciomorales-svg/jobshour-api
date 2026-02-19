<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FlowController - Integración con Flow.cl para pagos
 * 
 * ⚠️ CONFIGURACIÓN PENDIENTE:
 * - Las claves de Flow deben configurarse en .env
 * - FLOW_API_KEY: Clave pública de Flow
 * - FLOW_SECRET_KEY: Clave privada de Flow
 * - FLOW_SANDBOX: true para pruebas, false para producción
 * 
 * Ver: PENDIENTE_CONFIGURACION_FLOW.md
 */
class FlowController extends Controller
{
    /**
     * Iniciar pago con Flow
     * Endpoint: POST /api/v1/payments/flow/init
     */
    public function iniciar(Request $request)
    {
        $request->validate([
            'service_request_id' => 'required|exists:service_requests,id',
            'amount' => 'required|numeric|min:1',
        ]);

        $user = $request->user();
        $serviceRequest = ServiceRequest::findOrFail($request->service_request_id);

        // Verificar que el usuario sea el cliente del servicio
        if ($serviceRequest->client_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado',
            ], 403);
        }

        // Verificar que el servicio esté en estado válido para pago
        if (!in_array($serviceRequest->status, ['accepted', 'in_progress', 'completed'])) {
            return response()->json([
                'success' => false,
                'message' => 'El servicio no está en un estado válido para pago',
            ], 422);
        }

        // Verificar que no exista un pago ya completado
        $existingPayment = DB::table('payments')
            ->where('service_request_id', $serviceRequest->id)
            ->where('status', 'completed')
            ->first();

        if ($existingPayment) {
            return response()->json([
                'success' => false,
                'message' => 'Este servicio ya ha sido pagado',
            ], 422);
        }

        // Crear registro de pago en tabla payments
        $paymentId = DB::table('payments')->insertGetId([
            'service_request_id' => $serviceRequest->id,
            'client_id' => $user->id,
            'worker_id' => $serviceRequest->worker_id,
            'amount' => (int) round($request->amount),
            'payment_method' => 'flow',
            'status' => 'pending',
            'metadata' => json_encode([
                'flow_initiated_at' => now()->toISOString(),
                'service_description' => $serviceRequest->description,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Configuración de Flow
        $flowApiKey = config('services.flow.api_key', '');
        $flowSecret = config('services.flow.secret_key', '');
        $flowEndpoint = config('services.flow.sandbox', false)
            ? 'https://sandbox.flow.cl/api'
            : 'https://www.flow.cl/api';

        // Datos para Flow
        $orderData = [
            'apiKey' => $flowApiKey,
            'commerceOrder' => 'JOBSHOUR-' . $paymentId,
            'subject' => 'Pago de Servicio JobsHour',
            'currency' => 'CLP',
            'amount' => (int) round($request->amount),
            'email' => $user->email,
            'paymentMethod' => 9, // Todos los métodos de pago
            'urlConfirmation' => config('app.url') . '/api/v1/payments/flow/confirm',
            'urlReturn' => config('app.frontend_url', 'https://jobshour.dondemorales.cl') . '/pago/resultado',
        ];

        $orderData['s'] = $this->sign($orderData, $flowSecret);

        try {
            $response = $this->send($flowEndpoint . '/payment/create', $orderData);
            Log::info('Flow iniciar response', [
                'payment_id' => $payment->id,
                'response' => $response,
                'endpoint' => $flowEndpoint,
            ]);

            $result = json_decode($response, true);

            if (isset($result['url']) && isset($result['token'])) {
                // Actualizar payment con token de Flow
                $metadata = json_decode(DB::table('payments')->where('id', $paymentId)->value('metadata'), true) ?? [];
                $metadata['flow_token'] = $result['token'];
                $metadata['flow_url'] = $result['url'];
                
                DB::table('payments')->where('id', $paymentId)->update([
                    'metadata' => json_encode($metadata),
                    'updated_at' => now(),
                ]);

                return response()->json([
                    'success' => true,
                    'url' => $result['url'],
                    'token' => $result['token'],
                    'payment_id' => $paymentId,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Error al crear pago en Flow',
                'error' => $result,
            ], 400);

        } catch (\Exception $e) {
            Log::error('Flow iniciar error', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error de conexión con Flow',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Confirmar pago (webhook de Flow)
     * Endpoint: GET/POST /api/v1/payments/flow/confirm
     */
    public function confirm(Request $request)
    {
        $token = $request->get('token');

        Log::info('Flow confirm', [
            'token' => $token,
            'all_params' => $request->all(),
        ]);

        if (!$token) {
            return response()->json(['error' => 'Token no recibido'], 400);
        }

        $flowSecret = config('services.flow.secret_key', '');
        $flowEndpoint = config('services.flow.sandbox', false)
            ? 'https://sandbox.flow.cl/api'
            : 'https://www.flow.cl/api';

        $params = [
            'apiKey' => config('services.flow.api_key', ''),
            'token' => $token,
        ];
        $params['s'] = $this->sign($params, $flowSecret);

        try {
            $response = $this->sendGet($flowEndpoint . '/payment/getStatus', $params);
            Log::info('Flow getStatus response', ['response' => $response]);
            
            $result = json_decode($response, true);

            if (isset($result['commerceOrder'])) {
                $paymentId = str_replace('JOBSHOUR-', '', $result['commerceOrder']);
                $payment = DB::table('payments')->where('id', $paymentId)->first();

                if ($payment) {
                    $status = $result['status'] ?? 0;

                    DB::transaction(function () use ($payment, $paymentId, $status, $result) {
                        $metadata = json_decode($payment->metadata ?? '{}', true);
                        
                        if ($status == 2) {
                            // Pago exitoso
                            $metadata['flow_status'] = $status;
                            $metadata['flow_completed_at'] = now()->toISOString();
                            $metadata['flow_response'] = $result;
                            
                            DB::table('payments')->where('id', $paymentId)->update([
                                'status' => 'completed',
                                'completed_at' => now(),
                                'metadata' => json_encode($metadata),
                                'updated_at' => now(),
                            ]);

                            // Actualizar servicio como pagado
                            $serviceRequest = ServiceRequest::find($payment->service_request_id);
                            if ($serviceRequest) {
                                $serviceRequest->update([
                                    'payment_status' => 'completed',
                                    'paid_at' => now(),
                                ]);
                            }

                            // Incrementar ganancias del worker
                            if ($payment->worker_id) {
                                $worker = \App\Models\Worker::find($payment->worker_id);
                                if ($worker) {
                                    $worker->increment('total_earnings', $payment->amount);
                                }
                            }

                            Log::info('Flow payment completed', [
                                'payment_id' => $paymentId,
                                'service_request_id' => $payment->service_request_id,
                            ]);
                        } elseif ($status == 3 || $status == 4) {
                            // Pago rechazado
                            $metadata['flow_status'] = $status;
                            $metadata['flow_response'] = $result;
                            
                            DB::table('payments')->where('id', $paymentId)->update([
                                'status' => 'failed',
                                'metadata' => json_encode($metadata),
                                'updated_at' => now(),
                            ]);

                            Log::warning('Flow payment rejected', [
                                'payment_id' => $paymentId,
                                'status' => $status,
                            ]);
                        }
                    });

                    return response()->json([
                        'success' => $status == 2,
                        'status' => $status,
                        'payment_id' => $paymentId,
                    ]);
                }
            }

            return response()->json([
                'success' => false,
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Flow confirm error', [
                'error' => $e->getMessage(),
                'token' => $token,
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retorno después del pago (redirect desde Flow)
     * Endpoint: GET/POST /api/v1/payments/flow/return
     */
    public function retorno(Request $request)
    {
        $token = $request->input('token');
        
        Log::info('Flow retorno', [
            'token' => $token,
            'all_params' => $request->all(),
        ]);

        $frontendUrl = config('app.frontend_url', 'https://jobshour.dondemorales.cl');
        return redirect($frontendUrl . '/pago/resultado?token=' . $token . '&status=return');
    }

    /**
     * Firmar parámetros con HMAC-SHA256
     */
    private function sign($params, $secret)
    {
        ksort($params);
        $toSign = '';
        foreach ($params as $key => $value) {
            if ($key !== 's') {
                $toSign .= $key . $value;
            }
        }
        return hash_hmac('sha256', $toSign, $secret);
    }

    /**
     * Enviar POST request a Flow
     */
    private function send($url, $params)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    /**
     * Enviar GET request a Flow
     */
    private function sendGet($url, $params)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}
