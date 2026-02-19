<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_cambiar_estado_sin_auth_retorna_401(): void
    {
        $response = $this->postJson('/api/v1/worker/status', [
            'status' => 'active',
            'lat' => -37.6672,
            'lng' => -72.5730,
        ]);

        $response->assertStatus(401);
    }

    public function test_estado_invalido_retorna_422(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/worker/status', [
            'status' => 'flying',
            'lat' => -37.6672,
            'lng' => -72.5730,
        ]);

        $response->assertStatus(422);
    }

    public function test_activar_sin_categoria_retorna_error_require_category(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/worker/status', [
            'status' => 'active',
            'lat' => -37.6672,
            'lng' => -72.5730,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['code' => 'REQUIRE_CATEGORY']);
    }

    public function test_activar_con_categoria_valida_retorna_200(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/worker/status', [
            'status' => 'active',
            'lat' => -37.6672,
            'lng' => -72.5730,
            'categories' => [$category->id],
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['availability_status' => 'active']);
    }

    public function test_desactivar_retorna_inactive(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/worker/status', [
            'status' => 'inactive',
            'lat' => -37.6672,
            'lng' => -72.5730,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['availability_status' => 'inactive']);
    }
}
