<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewModalVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_despues_de_calificar_la_review_se_detecta_por_service_request_id(): void
    {
        $category = Category::factory()->create();

        /** @var User $workerUser */
        $workerUser = User::factory()->create(['type' => 'worker']);
        $worker = Worker::factory()->create([
            'user_id' => $workerUser->id,
            'category_id' => $category->id,
        ]);

        /** @var User $client */
        $client = User::factory()->create(['type' => 'employer']);

        $serviceRequest = ServiceRequest::create([
            'client_id' => $client->id,
            'worker_id' => $worker->id,
            'category_id' => $category->id,
            'status' => 'completed',
            'description' => 'Servicio finalizado para prueba de calificación',
        ]);

        $this->actingAs($client, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'service_request_id' => $serviceRequest->id,
                'stars' => 5,
                'comment' => 'Excelente servicio, puntual y muy prolijo.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('status', 'success');

        $reviewsResponse = $this->actingAs($client, 'sanctum')
            ->getJson("/api/v1/workers/{$worker->id}/reviews")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->json('data');

        $this->assertIsArray($reviewsResponse);

        $hasReviewForRequest = collect($reviewsResponse)->contains(function (array $review) use ($serviceRequest) {
            return ($review['service_request_id'] ?? null) === $serviceRequest->id;
        });

        // Este comportamiento es clave para que el frontend oculte el modal tras calificar.
        $this->assertTrue($hasReviewForRequest);
    }
}

