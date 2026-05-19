<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ServiceRequest;
use App\Models\StoreDemandIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StorePartnerDemandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sin_token_retorna_401(): void
    {
        $response = $this->postJson('/api/v1/integrations/store-demand', [
            'external_order_id' => 'ord-1',
            'description' => 'Delivery',
            'lat' => -37.6672,
            'lng' => -72.5730,
        ]);
        $response->assertStatus(401);
    }

    public function test_publicar_desde_tienda_exitoso(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $plain = 'jdh_test_partner_token_'.str_repeat('x', 32);
        StoreDemandIntegration::query()->create([
            'name' => 'Tienda test',
            'token_hash' => hash('sha256', $plain),
            'user_id' => $user->id,
            'default_category_id' => $category->id,
            'active' => true,
        ]);

        $response = $this->postJson('/api/v1/integrations/store-demand', [
            'external_order_id' => 'webhook-order-99',
            'description' => 'Pedido pagado — retiro en tienda, entrega en domicilio',
            'lat' => -37.6672,
            'lng' => -72.5730,
            'offered_price' => 5000,
            'store_name' => 'Tienda test',
        ], [
            'Authorization' => 'Bearer '.$plain,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.idempotent', false);

        $this->assertNotNull($response->json('data.request_id'));
    }

    public function test_idempotencia_mismo_pedido(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $plain = 'jdh_test_partner_token_'.str_repeat('y', 32);
        StoreDemandIntegration::query()->create([
            'name' => 'Tienda idem',
            'token_hash' => hash('sha256', $plain),
            'user_id' => $user->id,
            'default_category_id' => $category->id,
            'active' => true,
        ]);

        $body = [
            'external_order_id' => 'same-order',
            'description' => 'Delivery',
            'lat' => -37.6672,
            'lng' => -72.5730,
        ];
        $headers = ['Authorization' => 'Bearer '.$plain];

        $r1 = $this->postJson('/api/v1/integrations/store-demand', $body, $headers);
        $r1->assertStatus(201);
        $id = $r1->json('data.request_id');

        $r2 = $this->postJson('/api/v1/integrations/store-demand', $body, $headers);
        $r2->assertStatus(200)
            ->assertJsonPath('data.idempotent', true)
            ->assertJsonPath('data.request_id', $id);
    }

    public function test_ip_no_permitida_retorna_403(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $plain = 'jdh_test_partner_token_'.str_repeat('z', 32);
        StoreDemandIntegration::query()->create([
            'name' => 'Tienda IP lock',
            'token_hash' => hash('sha256', $plain),
            'user_id' => $user->id,
            'default_category_id' => $category->id,
            'active' => true,
            'allowed_ips' => ['203.0.113.50'],
        ]);

        $response = $this->postJson('/api/v1/integrations/store-demand', [
            'external_order_id' => 'ord-ip',
            'description' => 'Test',
            'lat' => -37.6672,
            'lng' => -72.5730,
        ], ['Authorization' => 'Bearer '.$plain]);

        $response->assertStatus(403);
    }

    public function test_ip_permitida_publica_ok(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $plain = 'jdh_test_partner_token_'.str_repeat('w', 32);
        StoreDemandIntegration::query()->create([
            'name' => 'Tienda IP ok',
            'token_hash' => hash('sha256', $plain),
            'user_id' => $user->id,
            'default_category_id' => $category->id,
            'active' => true,
            'allowed_ips' => ['127.0.0.1'],
        ]);

        $response = $this->postJson('/api/v1/integrations/store-demand', [
            'external_order_id' => 'ord-ip-ok',
            'description' => 'Test',
            'lat' => -37.6672,
            'lng' => -72.5730,
        ], ['Authorization' => 'Bearer '.$plain]);

        $response->assertStatus(201);
    }

    public function test_buyer_email_asigna_cliente_final(): void
    {
        $storeUser = User::factory()->create(['type' => 'employer']);
        $category = Category::factory()->create();
        $plain = 'jdh_test_partner_token_'.str_repeat('b', 32);
        StoreDemandIntegration::query()->create([
            'name' => 'DondeMorales',
            'token_hash' => hash('sha256', $plain),
            'user_id' => $storeUser->id,
            'default_category_id' => $category->id,
            'active' => true,
        ]);

        $buyerEmail = 'comprador-'.uniqid().'@test.cl';

        $response = $this->postJson('/api/v1/integrations/store-demand', [
            'external_order_id' => 'dm-buyer-1',
            'description' => 'Envío pedido tienda',
            'lat' => -37.6672,
            'lng' => -72.5730,
            'offered_price' => 3500,
            'buyer_email' => $buyerEmail,
            'buyer_name' => 'María Compradora',
            'buyer_phone' => '+56912345678',
        ], ['Authorization' => 'Bearer '.$plain]);

        $response->assertStatus(201)
            ->assertJsonPath('data.buyer_assigned', true)
            ->assertJsonPath('data.buyer_existed', false)
            ->assertJsonPath('data.client_email', $buyerEmail);

        $requestId = (int) $response->json('data.request_id');
        $sr = ServiceRequest::query()->findOrFail($requestId);
        $this->assertNotEquals($storeUser->id, $sr->client_id);

        $buyer = User::query()->where('email', $buyerEmail)->first();
        $this->assertNotNull($buyer);
        $this->assertSame($buyer->id, $sr->client_id);
        $this->assertNotEmpty($response->json('data.customer_url'));
    }

    public function test_buyer_email_existente_reutiliza_usuario(): void
    {
        $storeUser = User::factory()->create(['type' => 'employer']);
        $buyer = User::factory()->create(['type' => 'employer', 'email' => 'cliente-existente@test.cl']);
        $category = Category::factory()->create();
        $plain = 'jdh_test_partner_token_'.str_repeat('c', 32);
        StoreDemandIntegration::query()->create([
            'name' => 'Tienda',
            'token_hash' => hash('sha256', $plain),
            'user_id' => $storeUser->id,
            'default_category_id' => $category->id,
            'active' => true,
        ]);

        $response = $this->postJson('/api/v1/integrations/store-demand', [
            'external_order_id' => 'dm-buyer-2',
            'description' => 'Delivery',
            'lat' => -37.6672,
            'lng' => -72.5730,
            'buyer_email' => $buyer->email,
        ], ['Authorization' => 'Bearer '.$plain]);

        $response->assertStatus(201)
            ->assertJsonPath('data.buyer_existed', true)
            ->assertJsonPath('data.client_user_id', $buyer->id);

        $sr = ServiceRequest::query()->findOrFail((int) $response->json('data.request_id'));
        $this->assertSame($buyer->id, $sr->client_id);
    }
}
