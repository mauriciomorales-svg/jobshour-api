<?php

namespace Tests\Feature;

use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Worker;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private function createServiceRequest(): array
    {
        /** @var User $client */
        $client = User::factory()->create();
        /** @var User $workerUser */
        $workerUser = User::factory()->create();
        $category = Category::factory()->create();

        /** @var Worker $worker */
        $worker = Worker::factory()->create([
            'user_id' => $workerUser->id,
            'category_id' => $category->id,
        ]);

        $sr = ServiceRequest::create([
            'client_id' => $client->id,
            'worker_id' => $worker->id,
            'description' => 'Test request',
            'status' => 'accepted',
            'offered_price' => 10000,
        ]);

        return ['client' => $client, 'worker' => $workerUser, 'sr' => $sr];
    }

    public function test_mensajes_sin_auth_retorna_401(): void
    {
        $response = $this->getJson('/api/v1/requests/1/messages');

        $response->assertStatus(401);
    }

    public function test_enviar_mensaje_sin_auth_retorna_401(): void
    {
        $response = $this->postJson('/api/v1/requests/1/messages', ['body' => 'Hola']);

        $response->assertStatus(401);
    }

    public function test_cliente_puede_obtener_mensajes(): void
    {
        $data = $this->createServiceRequest();
        /** @var User $client */
        $client = $data['client'];
        $sr = $data['sr'];

        $response = $this->actingAs($client)->getJson("/api/v1/requests/{$sr->id}/messages");

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_cliente_puede_enviar_mensaje(): void
    {
        $data = $this->createServiceRequest();
        /** @var User $client */
        $client = $data['client'];
        $sr = $data['sr'];

        $response = $this->actingAs($client)->postJson("/api/v1/requests/{$sr->id}/messages", [
            'body' => 'Hola, cuándo puedes?',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'body', 'sender_id', 'created_at']]);
    }

    public function test_tercero_no_puede_enviar_mensaje(): void
    {
        $data = $this->createServiceRequest();
        $sr = $data['sr'];

        /** @var User $outsider */
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->postJson("/api/v1/requests/{$sr->id}/messages", [
            'body' => 'Mensaje no autorizado',
        ]);

        $response->assertStatus(403);
    }

    public function test_mensaje_vacio_retorna_422(): void
    {
        $data = $this->createServiceRequest();
        /** @var User $client */
        $client = $data['client'];
        $sr = $data['sr'];

        $response = $this->actingAs($client)->postJson("/api/v1/requests/{$sr->id}/messages", [
            'body' => '',
        ]);

        $response->assertStatus(422);
    }
}
