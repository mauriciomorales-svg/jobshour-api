<?php

namespace App\Jobs;

use App\Services\StoreMercadoPagoWebhookProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessStoreMercadoPagoWebhook implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 8;

    public int $uniqueFor = 120;

    public function __construct(public string $paymentId) {}

    public function uniqueId(): string
    {
        return 'mp-store-webhook-' . $this->paymentId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300, 600];
    }

    public function handle(StoreMercadoPagoWebhookProcessor $processor): void
    {
        $result = $processor->processByPaymentId($this->paymentId);
        Log::info('[StoreOrder] Webhook job completado', [
            'payment_id' => $this->paymentId,
            'result' => $result,
        ]);
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('[StoreOrder] Webhook job falló tras reintentos', [
            'payment_id' => $this->paymentId,
            'error' => $e?->getMessage(),
        ]);
    }
}
