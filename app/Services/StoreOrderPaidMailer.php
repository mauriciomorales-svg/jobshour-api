<?php

namespace App\Services;

use App\Models\StoreOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Correo transaccional cuando un pedido de tienda pasa a pagado (Mercado Pago u QA).
 * Falla en silencio con log si MAIL no está configurado.
 */
class StoreOrderPaidMailer
{
    /**
     * Enviar recibos al comprador y aviso al vendedor. Llamar solo cuando el pedido acaba de pasar a paid.
     */
    public function sendReceiptsIfPaid(StoreOrder $order): void
    {
        if ($order->status !== 'paid') {
            return;
        }

        if (filter_var((string) $order->buyer_email, FILTER_VALIDATE_EMAIL)) {
            try {
                $this->sendToBuyer($order);
            } catch (\Throwable $e) {
                Log::warning('[StoreOrderPaidMailer] Fallo email comprador', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->sendToSeller($order);
        } catch (\Throwable $e) {
            Log::warning('[StoreOrderPaidMailer] Fallo email vendedor', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendToBuyer(StoreOrder $order): void
    {
        $frontend = rtrim((string) config('app.frontend_url', ''), '/');
        $token = (string) ($order->public_token ?? '');
        $code = (string) ($order->confirmation_code ?? '');
        $qs = http_build_query([
            'external_reference' => (string) $order->id,
            'confirmation_code' => $code,
            'token' => $token,
        ]);
        $detailUrl = "{$frontend}/tienda/success?{$qs}";

        $support = env('SUPPORT_EMAIL', 'contacto@jobshour.cl');
        $body = implode("\n", [
            "Hola {$order->buyer_name},",
            '',
            'Confirmamos que recibimos el pago de tu pedido en JobsHours.',
            '',
            "Pedido #{$order->id}",
            'Total (CLP): $' . number_format((int) $order->total, 0, ',', '.'),
            '',
            'Ver detalle y seguimiento:',
            $detailUrl,
            '',
            "Si no reconoces este cargo, escribe a {$support} con el número de pedido y la fecha.",
            '',
            '— JobsHours',
        ]);

        Mail::raw($body, function ($message) use ($order) {
            $message->to($order->buyer_email)
                ->subject('Pago confirmado — Pedido #' . $order->id . ' (JobsHours)');
        });
    }

    private function sendToSeller(StoreOrder $order): void
    {
        $order->loadMissing('worker.user');
        $sellerEmail = $order->worker?->user?->email;
        if (! $sellerEmail || ! filter_var($sellerEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $body = implode("\n", [
            'Hola,',
            '',
            "El pedido #{$order->id} de tu tienda en JobsHours quedó como PAGADO.",
            'Comprador: ' . ($order->buyer_name ?? '—'),
            'Total (CLP): $' . number_format((int) $order->total, 0, ',', '.'),
            '',
            'Revisa el pedido en tu panel de pedidos / notificaciones de la app.',
            '',
            '— JobsHours',
        ]);

        Mail::raw($body, function ($message) use ($sellerEmail, $order) {
            $message->to($sellerEmail)
                ->subject('Pedido de tienda pagado — #' . $order->id . ' (JobsHours)');
        });
    }
}
