<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Simula cliente + trabajador vía HTTP (actingAs usuarios distintos),
 * igual que dos cuentas reales usando el API Sanctum.
 */
class PriceNegotiationFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeActiveWorker(?Category $category = null): array
    {
        $category = $category ?? Category::factory()->create();
        $workerUser = User::factory()->create();
        $worker = Worker::factory()->create([
            'user_id' => $workerUser->id,
            'category_id' => $category->id,
            'availability_status' => 'active',
        ]);

        return [$workerUser, $worker];
    }

    public function test_sin_oferta_no_se_puede_completar_hasta_negociacion(): void
    {
        [, $worker] = $this->makeActiveWorker();
        $clientUser = User::factory()->create();

        $create = $this->actingAs($clientUser)->postJson('/api/v1/requests', [
            'worker_id' => $worker->id,
            'description' => 'Limpiar patio',
            'type' => 'fixed_job',
        ]);
        $create->assertStatus(201);
        $requestId = (int) ($create->json('data.id') ?? 0);
        self::assertGreaterThan(0, $requestId);

        $sr = ServiceRequest::findOrFail($requestId);
        self::assertNull($sr->offered_price);

        $this->actingAs($worker->user)->postJson('/api/v1/requests/'.$requestId.'/respond', [
            'action' => 'accept',
        ])->assertStatus(200);

        $this->actingAs($worker->user)->postJson('/api/v1/requests/'.$requestId.'/complete', [])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Debes definir y acordar un monto final mayor a 0 antes de completar el servicio',
            ]);

        $this->actingAs($worker->user)->postJson('/api/v1/requests/'.$requestId.'/adjust-price', [
            'adjusted_price' => 12500,
            'reason' => 'Acordado tras visita presencial.',
        ])->assertStatus(200)->assertJsonFragment(['adjusted_price' => 12500]);

        $this->actingAs($clientUser)->postJson('/api/v1/requests/'.$requestId.'/approve-adjustment', [])
            ->assertStatus(200);

        $this->actingAs($worker->user)->postJson('/api/v1/requests/'.$requestId.'/complete', [])
            ->assertStatus(200);

        $sr->refresh();
        self::assertSame('completed', $sr->status);
        self::assertEqualsWithDelta(12500.0, (float) $sr->fresh()->final_price, 0.01);
    }

    public function test_con_oferta_inicial_se_puede_completar_sin_adjust(): void
    {
        [, $worker] = $this->makeActiveWorker();
        $clientUser = User::factory()->create();

        $create = $this->actingAs($clientUser)->postJson('/api/v1/requests', [
            'worker_id' => $worker->id,
            'description' => 'Cambiar llaves',
            'type' => 'fixed_job',
            'offered_price' => 20000,
        ]);
        $create->assertStatus(201);
        $requestId = (int) ($create->json('data.id') ?? 0);

        $this->actingAs($worker->user)->postJson('/api/v1/requests/'.$requestId.'/respond', [
            'action' => 'accept',
        ])->assertStatus(200);

        $this->actingAs($worker->user)->postJson('/api/v1/requests/'.$requestId.'/complete', [])
            ->assertStatus(200);

        $sr = ServiceRequest::findOrFail($requestId);
        self::assertSame('completed', $sr->status);
        self::assertNotNull($sr->final_price);
    }
}
