<?php

namespace Tests\Feature;

use App\Models\ProductAnalyticsEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ProductAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingesta_analytics_sin_secreto_configurado_responde_204(): void
    {
        Config::set('services.analytics.ingest_secret', '');

        $response = $this->postJson('/api/v1/analytics/events', [
            'name' => 'test_event',
            'payload' => ['a' => 1],
            't' => (int) (microtime(true) * 1000),
        ]);

        $response->assertNoContent();
        $this->assertDatabaseHas('product_analytics_events', ['name' => 'test_event']);
    }

    public function test_ingesta_con_secreto_incorrecto_es_403(): void
    {
        Config::set('services.analytics.ingest_secret', 'correct');

        $response = $this->postJson('/api/v1/analytics/events', [
            'name' => 'x',
            't' => 1,
        ], ['X-Analytics-Secret' => 'wrong']);

        $response->assertStatus(403);
    }

    public function test_ingesta_con_bearer_guarda_user_id(): void
    {
        Config::set('services.analytics.ingest_secret', '');

        /** @var User $user */
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/analytics/events', [
                'name' => 'home_app_mount',
                't' => (int) (microtime(true) * 1000),
            ]);

        $response->assertNoContent();
        $this->assertEquals($user->id, ProductAnalyticsEvent::query()->where('name', 'home_app_mount')->value('user_id'));
    }

    public function test_admin_summary_requiere_admin(): void
    {
        Config::set('admin.user_ids', [99]);
        /** @var User $user */
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/analytics/summary');

        $response->assertStatus(403);
    }

    public function test_admin_summary_ok_para_id_configurado(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        Config::set('admin.user_ids', [$user->id]);
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/analytics/summary');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'generated_at',
                'totals',
                'unique_ips',
                'users_with_events',
                'cohort',
                'by_name',
            ]);
    }
}
