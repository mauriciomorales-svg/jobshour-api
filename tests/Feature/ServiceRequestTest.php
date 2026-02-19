<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_crear_solicitud_sin_auth_retorna_401(): void
    {
        $response = $this->postJson('/api/v1/requests', [
            'worker_id' => 1,
            'description' => 'Necesito ayuda',
            'lat' => -37.6672,
            'lng' => -72.5730,
        ]);

        $response->assertStatus(401);
    }

    public function test_crear_solicitud_sin_datos_retorna_422(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/requests', []);

        $response->assertStatus(422);
    }

    public function test_obtener_mis_solicitudes_requiere_auth(): void
    {
        $response = $this->getJson('/api/v1/requests/mine');

        $response->assertStatus(401);
    }

    public function test_obtener_mis_solicitudes_retorna_lista(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/requests/mine');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_solicitud_inexistente_retorna_404(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/requests/99999');

        $response->assertStatus(404);
    }
}
