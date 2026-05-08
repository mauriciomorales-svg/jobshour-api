<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceRequestMercadoPagoPricingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeRequest(array $attrs = [], int $hourlyRate = 15000): ServiceRequest
    {
        $category = Category::factory()->create();
        $workerUser = User::factory()->create();
        $worker = Worker::factory()->create([
            'user_id' => $workerUser->id,
            'category_id' => $category->id,
            'hourly_rate' => $hourlyRate,
        ]);
        $client = User::factory()->create();

        return ServiceRequest::create(array_merge([
            'client_id' => $client->id,
            'worker_id' => $worker->id,
            'category_id' => $category->id,
            'description' => 'Unit test SR',
            'status' => 'accepted',
            'type' => 'fixed_job',
        ], $attrs));
    }

    public function test_mercado_pago_base_prefers_final_price_over_hourly(): void
    {
        $sr = $this->makeRequest([
            'final_price' => 5000,
            'offered_price' => 8000,
        ], 15000);

        self::assertEqualsWithDelta(5000.0, $sr->mercadoPagoBasePriceClp(), 0.01);
        self::assertSame('negotiated', $sr->mercadoPagoPricingSource());
    }

    public function test_mercado_pago_base_uses_hourly_when_no_negotiated_amount(): void
    {
        $sr = $this->makeRequest([
            'final_price' => null,
            'offered_price' => null,
        ], 16200);

        self::assertEqualsWithDelta(16200.0, $sr->mercadoPagoBasePriceClp(), 0.01);
        self::assertSame('hourly_rate', $sr->mercadoPagoPricingSource());
    }

    public function test_mercado_pago_base_uses_approved_adjusted_price(): void
    {
        $sr = $this->makeRequest([
            'final_price' => null,
            'offered_price' => 10000,
            'adjusted_price' => 12300,
            'client_approved_adjustment' => true,
        ], 20000);

        self::assertEqualsWithDelta(12300.0, $sr->mercadoPagoBasePriceClp(), 0.01);
        self::assertSame('negotiated', $sr->mercadoPagoPricingSource());
    }
}
