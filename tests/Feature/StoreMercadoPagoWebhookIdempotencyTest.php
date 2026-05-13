<?php

namespace Tests\Feature;

use App\Models\StoreOrder;
use App\Models\Worker;
use App\Services\StoreMercadoPagoWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StoreMercadoPagoWebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_webhook_processor_inserts_single_mp_webhook_event_on_double_run(): void
    {
        Mail::fake();
        config(['services.mercadopago.access_token' => 'test-token']);

        $worker = Worker::factory()->create();
        $order = StoreOrder::create([
            'worker_id'         => $worker->id,
            'buyer_name'        => 'Test',
            'buyer_email'       => 'buyer@example.com',
            'items'             => [['id' => 1, 'nombre' => 'x', 'cantidad' => 1, 'precio' => 1000]],
            'total'             => 1000,
            'delivery'          => false,
            'status'            => 'pending',
            'confirmation_code' => '1234',
            'mp_preference_id'  => 'pref-test',
        ]);

        $paymentId = '88119922';
        Http::fake([
            "https://api.mercadopago.com/v1/payments/{$paymentId}" => Http::response([
                'id'                 => $paymentId,
                'status'             => 'approved',
                'external_reference' => (string) $order->id,
                'preference_id'      => 'pref-test',
            ], 200),
        ]);

        $processor = app(StoreMercadoPagoWebhookProcessor::class);
        $this->assertSame('ok', $processor->processByPaymentId($paymentId));
        $this->assertSame('ok', $processor->processByPaymentId($paymentId));

        $this->assertDatabaseCount('mp_webhook_events', 1);
        $this->assertDatabaseHas('mp_webhook_events', [
            'mp_payment_id' => $paymentId,
            'event_type'    => 'store_order',
        ]);

        $order->refresh();
        $this->assertSame('paid', $order->status);
    }
}
