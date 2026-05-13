<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkerPulseTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_pulse_requires_auth(): void
    {
        $this->getJson('/api/v1/dashboard/worker-pulse')->assertStatus(401);
    }

    public function test_worker_pulse_returns_shape_for_worker(): void
    {
        $user = User::factory()->create();
        Worker::factory()->create(['user_id' => $user->id, 'is_seller' => true, 'store_name' => 'Mi tienda']);

        Sanctum::actingAs($user);

        $res = $this->getJson('/api/v1/dashboard/worker-pulse');
        $res->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.has_worker', true)
            ->assertJsonPath('data.store.is_seller', true)
            ->assertJsonPath('data.store.store_name', 'Mi tienda')
            ->assertJsonStructure([
                'data' => [
                    'worker_id',
                    'tagline',
                    'store' => ['orders_pending', 'orders_paid_30d', 'revenue_paid_30d_clp'],
                    'services' => ['active_jobs', 'completed_30d'],
                    'reputation' => ['rating', 'rating_count', 'total_jobs_completed'],
                ],
            ]);
    }
}
