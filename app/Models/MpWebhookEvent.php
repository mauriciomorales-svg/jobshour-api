<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Registro idempotente de webhooks de Mercado Pago.
 *
 * @property string $mp_payment_id
 * @property string $event_type
 * @property string|null $external_reference
 * @property string|null $mp_status
 * @property string $result
 * @property string|null $notes
 */
class MpWebhookEvent extends Model
{
    protected $fillable = [
        'mp_payment_id',
        'event_type',
        'external_reference',
        'mp_status',
        'result',
        'notes',
    ];

    /**
     * Registra el evento y devuelve si fue el primero (true) o ya existía (false).
     * Uso: if (!MpWebhookEvent::record(...)) { return response()->json(['status' => 'already_processed']); }
     */
    public static function record(
        string $mpPaymentId,
        string $eventType,
        string $mpStatus,
        string $extRef = '',
        string $result = 'ok',
        string $notes = ''
    ): bool {
        $existing = self::where('mp_payment_id', $mpPaymentId)
            ->where('event_type', $eventType)
            ->first();

        if ($existing) {
            return false; // ya procesado
        }

        try {
            self::create([
                'mp_payment_id'      => $mpPaymentId,
                'event_type'         => $eventType,
                'external_reference' => $extRef ?: null,
                'mp_status'          => $mpStatus,
                'result'             => $result,
                'notes'              => $notes ?: null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dos webhooks concurrentes: el otro insertó primero.
            return false;
        }

        return true; // procesado por primera vez
    }
}
