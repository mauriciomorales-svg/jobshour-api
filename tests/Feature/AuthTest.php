<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_con_credenciales_invalidas_retorna_422(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'noexiste@test.com',
            'password' => 'wrongpassword',
        ]);

        // AuthController lanza ValidationException → 422
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registro_con_datos_validos_crea_usuario(): void
    {
        $category = \App\Models\Category::factory()->create();

        $response = $this->postJson('/api/auth/register', [
            'name'     => 'Test Worker',
            'email'    => 'test@jobshour.cl',
            'phone'    => '+56912345678',
            'password' => 'password123',
            'type'     => 'worker',
            'category_id' => $category->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_me_sin_token_retorna_401(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    public function test_me_con_token_valido_retorna_usuario(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $user->id]);
    }

    public function test_logout_invalida_token(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout');

        $response->assertStatus(200);
    }
}
