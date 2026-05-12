<?php

namespace App\Jobs;

use App\Services\MercadoPagoWebhookProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMercadoPagoWebhook implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 8;

    /** Evita encolar varios jobs iguales si MP dispara el webhook varias veces seguidas. */
    public int $uniqueFor = 120;

    public function __construct(public string $paymentId) {}

    public function uniqueId(): string
    {
        return 'mp-webhook-' . $this->paymentId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300, 600];
    }

    public function handle(MercadoPagoWebhookProcessor $processor): void
    {
        $result = $processor->processByPaymentId($this->paymentId);
        Log::info('[MP] Webhook job completado', [
            'payment_id' => $this->paymentId,
            'result' => $result,
        ]);
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('[MP] Webhook job falló tras reintentos', [
            'payment_id' => $this->paymentId,
            'error' => $e?->getMessage(),
        ]);
    }
}
