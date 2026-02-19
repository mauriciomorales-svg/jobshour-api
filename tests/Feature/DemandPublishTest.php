<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DemandPublishTest extends TestCase
{
    use DatabaseTransactions;

    private function createAuthUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        return [$user, $token];
    }

    public function test_publicar_demanda_sin_auth_retorna_401(): void
    {
        $response = $this->postJson('/api/v1/demand/publish', []);
        $response->assertStatus(401);
    }

    public function test_publicar_demanda_sin_datos_retorna_422(): void
    {
        [$user] = $this->createAuthUser();

        $response = $this->actingAs($user)->postJson('/api/v1/demand/publish', []);
        $response->assertStatus(422);
    }

    public function test_publicar_demanda_basica_exitosa(): void
    {
        [$user] = $this->createAuthUser();
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/demand/publish', [
            'category_id' => $category->id,
            'description' => 'Necesito electricista urgente',
            'lat' => -37.6672,
            'lng' => -72.5730,
            'offered_price' => 15000,
            'ttl_minutes' => 30,
            'type' => 'fixed_job',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['status' => 'success']);
    }

    public function test_publicar_demanda_programada(): void
    {
        [$user] = $this->createAuthUser();
        $category = Category::factory()->create();
        $scheduledAt = now()->addDays(2)->toIso8601String();

        $response = $this->actingAs($user)->postJson('/api/v1/demand/publish', [
            'category_id' => $category->id,
            'description' => 'Electricista para el viernes',
            'lat' => -37.6672,
            'lng' => -72.5730,
            'offered_price' => 20000,
            'type' => 'fixed_job',
            'scheduled_at' => $scheduledAt,
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['status' => 'success']);
    }

    public function test_publicar_demanda_multi_worker(): void
    {
        [$user] = $this->createAuthUser();
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/demand/publish', [
            'category_id' => $category->id,
            'description' => 'Necesito 3 personas para mudanza',
            'lat' => -37.6672,
            'lng' => -72.5730,
            'offered_price' => 50000,
            'type' => 'fixed_job',
            'workers_needed' => 3,
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['status' => 'success']);
    }

    public function test_publicar_demanda_recurrente(): void
    {
        [$user] = $this->createAuthUser();
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/demand/publish', [
            'category_id' => $category->id,
            'description' => 'Paseo de perro lunes miercoles viernes',
            'lat' => -37.6672,
            'lng' => -72.5730,
            'offered_price' => 5000,
            'type' => 'fixed_job',
            'recurrence' => 'custom',
            'recurrence_days' => [1, 3, 5],
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['status' => 'success']);
    }

    public function test_publicar_ride_share_con_direcciones(): void
    {
        [$user] = $this->createAuthUser();
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/demand/publish', [
            'category_id' => $category->id,
            'description' => 'Viajo a Angol, tengo 2 asientos',
            'lat' => -37.6672,
            'lng' => -72.5730,
            'offered_price' => 3000,
            'type' => 'ride_share',
            'pickup_address' => 'Renaico centro',
            'delivery_address' => 'Angol plaza',
            'seats' => 2,
            'departure_time' => now()->addHours(2)->toIso8601String(),
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['status' => 'success']);
    }

    public function test_publicar_express_errand(): void
    {
        [$user] = $this->createAuthUser();
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/demand/publish', [
            'category_id' => $category->id,
            'description' => 'Comprar pan en la esquina',
            'lat' => -37.6672,
            'lng' => -72.5730,
            'offered_price' => 2000,
            'type' => 'express_errand',
            'store_name' => 'Panadería El Trigo',
            'requires_vehicle' => false,
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['status' => 'success']);
    }

    public function test_demanda_nearby_retorna_200(): void
    {
        $response = $this->getJson('/api/v1/demand/nearby?lat=-37.6672&lng=-72.5730');
        $response->assertStatus(200);
    }

    public function test_dashboard_feed_retorna_200(): void
    {
        $response = $this->getJson('/api/v1/dashboard/feed?lat=-37.6672&lng=-72.5730');
        $response->assertStatus(200);
    }

    public function test_rate_limit_publicar_demanda(): void
    {
        [$user] = $this->createAuthUser();
        $category = Category::factory()->create();

        $payload = [
            'category_id' => $category->id,
            'description' => 'Test rate limit',
            'lat' => -37.6672,
            'lng' => -72.5730,
            'offered_price' => 5000,
            'type' => 'fixed_job',
        ];

        // 5 req/min allowed
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson('/api/v1/demand/publish', $payload);
        }

        // 6th should be throttled
        $response = $this->actingAs($user)->postJson('/api/v1/demand/publish', $payload);
        $response->assertStatus(429);
    }
}
