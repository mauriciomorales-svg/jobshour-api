<?php

namespace App\Services;

use App\Models\IntegratedQuote;
use App\Models\StoreOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Procesa webhooks MP para pedidos de tienda ({@see StoreOrder}).
 */
class StoreMercadoPagoWebhookProcessor
{
    private string $mpBase = 'https://api.mercadopago.com';

    private function accessToken(): string
    {
        return trim((string) config('services.mercadopago.access_token', ''));
    }

    public function processByPaymentId(string $paymentId): string
    {
        $token = $this->accessToken();
        if ($token === '') {
            Log::warning('[StoreOrder] Webhook job sin access_token');

            return 'no_token';
        }

        $response = Http::timeout(25)
            ->withToken($token)
            ->get("{$this->mpBase}/v1/payments/{$paymentId}");

        if (! $response->successful()) {
            $code = $response->status();
            Log::warning('[StoreOrder] Webhook job fetch pago falló', ['payment_id' => $paymentId, 'http' => $code]);
            if ($code >= 500 || $code === 429) {
                throw new \RuntimeException("MP payments API HTTP {$code}");
            }

            return 'mp_fetch_client_error';
        }

        /** @var array<string, mixed> $pay */
        $pay = $response->json();
        if (! is_array($pay)) {
            return 'mp_invalid_json';
        }

        $externalRef = $pay['external_reference'] ?? null;

        $order = null;
        if ($externalRef && ctype_digit((string) $externalRef)) {
            $order = StoreOrder::where('id', (int) $externalRef)->first();
        }
        if (! $order) {
            $order = StoreOrder::where('mp_preference_id', $pay['preference_id'] ?? '')
                ->orWhere('mp_payment_id', $paymentId)
                ->first();
        }

        if (! $order) {
            return 'order_not_found';
        }

        $updates = [
            'mp_payment_id' => $paymentId,
            'mp_status' => (string) ($pay['status'] ?? ''),
        ];
        if (($pay['status'] ?? null) === 'approved' && $order->status === 'pending') {
            $updates['status'] = 'paid';
        }
        $order->update($updates);

        if ($order->integrated_quote_id) {
            $qUpdates = [
                'mp_payment_id' => $paymentId,
                'mp_status' => (string) ($pay['status'] ?? ''),
            ];
            if (($pay['status'] ?? null) === 'approved') {
                $qUpdates['status'] = 'paid';
            }
            IntegratedQuote::where('id', $order->integrated_quote_id)->update($qUpdates);
        }

        return 'ok';
    }
}
