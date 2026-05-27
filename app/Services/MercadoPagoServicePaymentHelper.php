<?php

namespace App\Services;

use App\Models\ServiceRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cobros y reembolsos de servicios vía Mercado Pago (JobsHours).
 */
class MercadoPagoServicePaymentHelper
{
    private string $baseUrl = 'https://api.mercadopago.com';

    /**
     * @param  array<string, mixed>  $preference  Respuesta POST /checkout/preferences
     */
    public static function preferenceInitPoint(array $preference): string
    {
        $useSandbox = (bool) config('mercadopago.use_sandbox_checkout', false)
            || config('app.env') !== 'production';

        return $useSandbox
            ? (string) ($preference['sandbox_init_point'] ?? $preference['init_point'] ?? '')
            : (string) ($preference['init_point'] ?? '');
    }

    /** En sandbox MP no admite retención (capture:false); en producción JobsHours retiene fondos. */
    public static function shouldCaptureImmediately(): bool
    {
        return (bool) config('mercadopago.use_sandbox_checkout', false)
            || config('app.env') !== 'production';
    }

    private function accessToken(): string
    {
        return trim((string) (config('mercadopago.access_token') ?? ''));
    }

    public function expectedChargeClp(ServiceRequest $serviceRequest): float
    {
        return round((float) $serviceRequest->mercadoPagoBasePriceClp() * 1.08, 2);
    }

    /**
     * @param  array<string, mixed>  $payment  Respuesta GET /v1/payments/{id}
     */
    public function paymentAmountMatches(ServiceRequest $serviceRequest, array $payment): bool
    {
        $expected = $this->expectedChargeClp($serviceRequest);
        $paid = (float) ($payment['transaction_amount'] ?? 0);
        $currency = strtoupper((string) ($payment['currency_id'] ?? 'CLP'));

        if ($currency !== '' && $currency !== 'CLP') {
            Log::warning('[MP] Moneda inesperada en pago de servicio', [
                'service_request_id' => $serviceRequest->id,
                'currency' => $currency,
            ]);

            return false;
        }

        return abs($paid - $expected) <= 1.0;
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    public function syncServiceRequestFromMpPayment(ServiceRequest $serviceRequest, array $payment): void
    {
        $status = (string) ($payment['status'] ?? '');
        $updates = ['mp_status' => $status];

        if ($status === 'approved') {
            $updates['payment_status'] = 'completed';
            $updates['paid_at'] = $serviceRequest->paid_at ?? now();
        } elseif ($status === 'authorized') {
            $updates['payment_status'] = 'pending';
        } elseif (in_array($status, ['pending', 'in_process'], true)) {
            $updates['payment_status'] = 'pending';
        } elseif (in_array($status, ['cancelled', 'rejected', 'refunded'], true)) {
            $updates['payment_status'] = $status === 'refunded' ? 'refunded' : 'failed';
        }

        $serviceRequest->update($updates);
    }

    /**
     * Reembolso total del pago MP asociado a la solicitud.
     *
     * @return array{success: bool, message: string, mp_refund_id?: string}
     */
    public function refundServicePayment(ServiceRequest $serviceRequest): array
    {
        $token = $this->accessToken();
        if ($token === '') {
            return ['success' => false, 'message' => 'Mercado Pago no está configurado'];
        }

        $mpId = $serviceRequest->mp_payment_id;
        if (! $mpId) {
            return ['success' => false, 'message' => 'La solicitud no tiene pago de Mercado Pago asociado'];
        }

        if (! in_array($serviceRequest->payment_status, ['completed', 'pending'], true)) {
            return ['success' => false, 'message' => 'No hay un pago activo para reembolsar'];
        }

        $response = Http::timeout(25)
            ->withToken($token)
            ->post("{$this->baseUrl}/v1/payments/{$mpId}/refunds", []);

        if (! $response->successful()) {
            $body = $response->json();
            $msg = is_array($body) ? ($body['message'] ?? 'No se pudo procesar el reembolso') : 'No se pudo procesar el reembolso';
            Log::error('[MP] Refund servicio falló', [
                'service_request_id' => $serviceRequest->id,
                'mp_payment_id' => $mpId,
                'http' => $response->status(),
                'body' => $body,
            ]);

            return ['success' => false, 'message' => $msg];
        }

        $data = $response->json();
        $refundId = is_array($data) ? ($data['id'] ?? null) : null;

        $serviceRequest->update([
            'payment_status' => 'refunded',
            'mp_status' => 'refunded',
        ]);

        Log::info('[MP] Reembolso de servicio OK', [
            'service_request_id' => $serviceRequest->id,
            'mp_payment_id' => $mpId,
            'refund_id' => $refundId,
        ]);

        return [
            'success' => true,
            'message' => 'Reembolso procesado',
            'mp_refund_id' => $refundId ? (string) $refundId : null,
        ];
    }
}
