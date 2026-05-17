<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\StoreDemandIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StoreDemandIntegrationCommandsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_integration_rotate_actualiza_token_hash(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $plain = 'jdh_rot_'.str_repeat('a', 40);
        $integration = StoreDemandIntegration::query()->create([
            'name' => 'Para rotar',
            'token_hash' => hash('sha256', $plain),
            'user_id' => $user->id,
            'default_category_id' => $category->id,
            'active' => true,
        ]);
        $oldHash = $integration->token_hash;

        $this->artisan('store-demand:integration-rotate', [
            'integration_id' => (string) $integration->id,
        ])->assertExitCode(0);

        $integration->refresh();
        $this->assertNotSame($oldHash, $integration->token_hash);
    }

    public function test_integration_ips_actualiza_y_clear(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $plain = 'jdh_ips_'.str_repeat('b', 40);
        $integration = StoreDemandIntegration::query()->create([
            'name' => 'IPs',
            'token_hash' => hash('sha256', $plain),
            'user_id' => $user->id,
            'default_category_id' => $category->id,
            'active' => true,
        ]);

        $this->artisan('store-demand:integration-ips', [
            'integration_id' => (string) $integration->id,
            'ips' => '10.0.0.1,10.0.0.2',
        ])->assertExitCode(0);

        $integration->refresh();
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $integration->allowed_ips);

        $this->artisan('store-demand:integration-ips', [
            'integration_id' => (string) $integration->id,
            '--clear' => true,
        ])->assertExitCode(0);

        $integration->refresh();
        $this->assertNull($integration->allowed_ips);
    }

    public function test_integration_create_rechaza_ip_invalida(): void
    {
        $user = User::factory()->create();

        $this->artisan('store-demand:integration', [
            'user_id' => (string) $user->id,
            'name' => 'Mala IP',
            '--ips' => 'no-es-una-ip',
        ])->assertExitCode(1);
    }
}
