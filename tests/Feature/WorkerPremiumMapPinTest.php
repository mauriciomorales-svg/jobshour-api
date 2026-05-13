<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkerPremiumMapPinTest extends TestCase
{
    use RefreshDatabase;

    public function test_premium_map_pin_requires_auth(): void
    {
        $this->putJson('/api/v1/worker/premium-map-pin', [
            'show_premium_pin_on_map' => true,
            'premium_external_store_url' => 'https://example.com',
        ])->assertStatus(401);
    }

    public function test_premium_map_pin_updates_worker(): void
    {
        $user = User::factory()->create();
        Worker::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/worker/premium-map-pin', [
            'show_premium_pin_on_map' => true,
            'premium_external_store_url' => 'https://mitienda.cl/catalogo',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('show_premium_pin_on_map', true)
            ->assertJsonPath('premium_external_store_url', 'https://mitienda.cl/catalogo');

        $this->assertDatabaseHas('workers', [
            'user_id' => $user->id,
            'show_premium_pin_on_map' => true,
            'premium_external_store_url' => 'https://mitienda.cl/catalogo',
        ]);
    }

    public function test_premium_map_pin_off_clears_url(): void
    {
        $user = User::factory()->create();
        Worker::factory()->create([
            'user_id' => $user->id,
            'show_premium_pin_on_map' => true,
            'premium_external_store_url' => 'https://old.cl',
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/worker/premium-map-pin', [
            'show_premium_pin_on_map' => false,
        ])
            ->assertOk()
            ->assertJsonPath('show_premium_pin_on_map', false)
            ->assertJsonPath('premium_external_store_url', null);

        $this->assertDatabaseHas('workers', [
            'user_id' => $user->id,
            'show_premium_pin_on_map' => false,
            'premium_external_store_url' => null,
        ]);
    }

    public function test_worker_me_includes_premium_fields(): void
    {
        $user = User::factory()->create();
        Worker::factory()->create([
            'user_id' => $user->id,
            'show_premium_pin_on_map' => true,
            'premium_external_store_url' => 'https://x.cl',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/worker/me')
            ->assertOk()
            ->assertJsonPath('data.show_premium_pin_on_map', true)
            ->assertJsonPath('data.premium_external_store_url', 'https://x.cl');
    }
}
