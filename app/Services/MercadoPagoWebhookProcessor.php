<?php

namespace App\Services;

use App\Models\MpWebhookEvent;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Procesa la notificación de pago de Mercado Pago (consulta API + idempotencia + efectos).
 * Usado desde {@see \App\Jobs\ProcessMercadoPagoWebhook} para no bloquear la respuesta HTTP al webhook.
 */
class MercadoPagoWebhookProcessor
{
    private string $baseUrl = 'https://api.mercadopago.com';

    private function accessToken(): string
    {
        return trim((string) (config('mercadopago.access_token') ?? ''));
    }

    /**
     * @return string Clave de resultado para logs / tests (no es contrato HTTP hacia MP).
     */
    public function processByPaymentId(string $paymentId): string
    {
        $token = $this->accessToken();
        if ($token === '') {
            Log::warning('[MP] Webhook job sin access_token');

            return 'no_token';
        }

        $response = Http::timeout(25)
            ->withToken($token)
            ->get("{$this->baseUrl}/v1/payments/{$paymentId}");

        if (! $response->successful()) {
            $code = $response->status();
            Log::warning('[MP] Webhook job fetch pago falló', ['payment_id' => $paymentId, 'http' => $code]);
            if ($code >= 500 || $code === 429) {
                throw new \RuntimeException("MP payments API HTTP {$code}");
            }

            return 'mp_fetch_client_error';
        }

        /** @var array<string, mixed> $payment */
        $payment = $response->json();
        if (! is_array($payment)) {
            return 'mp_invalid_json';
        }

        $extRef = (string) ($payment['external_reference'] ?? '');
        if (str_starts_with($extRef, 'boost:')) {
            return $this->applyDemandBoostFromPayment($payment, $extRef);
        }
        if (str_starts_with($extRef, 'credits:')) {
            return $this->applyCreditsFromPayment($payment, $extRef);
        }

        return $this->applyServicePayment($payment, $extRef);
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function applyServicePayment(array $payment, string $extRef): string
    {
        $serviceRequestId = is_numeric($extRef) ? (int) $extRef : 0;
        if ($serviceRequestId < 1) {
            return 'no_reference';
        }

        $serviceRequest = ServiceRequest::find($serviceRequestId);
        if (! $serviceRequest) {
            return 'not_found';
        }

        $isNew = MpWebhookEvent::record(
            (string) $payment['id'],
            'service_payment',
            (string) ($payment['status'] ?? ''),
            $extRef
        );
        if (! $isNew) {
            Log::info('[MP] Webhook service_payment repetido; re-sincronizando estado', ['mp_id' => $payment['id']]);
        }

        $serviceRequest->update(['mp_status' => $payment['status']]);

        if ($payment['status'] === 'authorized') {
            $serviceRequest->update(['payment_status' => 'pending']);
            Log::info('[MP] Pago autorizado (retención)', ['sr_id' => $serviceRequestId]);
        } elseif ($payment['status'] === 'approved') {
            $serviceRequest->update([
                'payment_status' => 'completed',
                'paid_at' => now(),
            ]);
            Log::info('[MP] Pago capturado, servicio pagado', ['sr_id' => $serviceRequestId]);
        } elseif (in_array($payment['status'], ['cancelled', 'rejected'], true)) {
            $serviceRequest->update(['payment_status' => 'failed']);
            Log::info('[MP] Pago rechazado/cancelado', ['sr_id' => $serviceRequestId]);
        }

        return $isNew ? 'ok' : 'already_processed';
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function applyDemandBoostFromPayment(array $payment, string $extRef): string
    {
        $srId = (int) substr($extRef, strlen('boost:'));
        if ($srId < 1) {
            return 'bad_boost_ref';
        }

        $sr = ServiceRequest::find($srId);
        if (! $sr) {
            return 'not_found';
        }

        $mpStatus = (string) ($payment['status'] ?? '');
        if (! in_array($mpStatus, ['approved', 'authorized'], true)) {
            Log::info('[MP] Boost webhook sin aprobación aún', ['sr' => $srId, 'status' => $mpStatus]);

            return 'ok_boost_pending';
        }

        $isNew = MpWebhookEvent::record(
            (string) ($payment['id'] ?? 'unknown'),
            'boost',
            $mpStatus,
            $extRef
        );
        if (! $isNew) {
            Log::info('[MP] Boost ya procesado (idempotente)', ['sr' => $srId, 'mp_id' => $payment['id'] ?? null]);

            return 'already_processed';
        }

        $meta = isset($payment['metadata']) && is_array($payment['metadata']) ? $payment['metadata'] : [];
        $hours = (int) ($meta['boost_hours'] ?? config('services.boost.default_hours', 24));

        if (! empty($payment['id'])) {
            $sr->boost_mp_payment_id = (string) $payment['id'];
        }

        $base = ($sr->boosted_until && $sr->boosted_until->isFuture()) ? $sr->boosted_until : now();
        $sr->boosted_until = $base->copy()->addHours($hours);
        Log::info('[MP] Boost demanda aplicado', ['sr' => $srId, 'hours' => $hours]);
        $sr->save();

        return 'ok_boost';
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function applyCreditsFromPayment(array $payment, string $extRef): string
    {
        $parts = explode(':', $extRef, 3);
        if (count($parts) < 3) {
            Log::warning('[MP] credits: extRef malformado', ['ref' => $extRef]);

            return 'bad_credits_ref';
        }

        [, $userId, $packId] = $parts;
        $userId = (int) $userId;

        if ($userId < 1 || $packId === '') {
            return 'bad_credits_ref';
        }

        $status = $payment['status'] ?? '';
        if (! in_array($status, ['approved', 'authorized'], true)) {
            Log::info('[MP] Credits webhook sin aprobación aún', ['status' => $status, 'ref' => $extRef]);

            return 'ok_credits_pending';
        }

        $isNew = MpWebhookEvent::record(
            (string) ($payment['id'] ?? 'unknown'),
            'credits',
            (string) $status,
            $extRef
        );
        if (! $isNew) {
            Log::info('[MP] Credits ya procesado (idempotente)', ['user_id' => $userId, 'mp_id' => $payment['id'] ?? null]);

            return 'already_processed';
        }

        $packs = collect(config('services.credits.packs', []));
        $pack = $packs->firstWhere('id', $packId);
        $creditsToAdd = $pack ? (int) $pack['credits'] : 0;

        $meta = isset($payment['metadata']) && is_array($payment['metadata']) ? $payment['metadata'] : [];
        if (isset($meta['credits']) && (int) $meta['credits'] > 0) {
            $creditsToAdd = (int) $meta['credits'];
        }

        if ($creditsToAdd < 1) {
            Log::warning('[MP] credits: pack no encontrado o 0 créditos', ['pack_id' => $packId]);

            return 'unknown_pack';
        }

        $user = User::find($userId);
        if (! $user) {
            Log::warning('[MP] credits: usuario no encontrado', ['user_id' => $userId]);

            return 'user_not_found';
        }

        $user->increment('credits_balance', $creditsToAdd);
        Log::info('[MP] Créditos acreditados', [
            'user_id' => $userId,
            'pack_id' => $packId,
            'credits_added' => $creditsToAdd,
            'new_balance' => $user->fresh()->credits_balance,
            'mp_payment' => $payment['id'] ?? null,
        ]);

        return 'ok_credits';
    }
}
