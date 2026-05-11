<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpirePendingAcceptWindowTest extends TestCase
{
    use RefreshDatabase;

    private function seedWorkerAndClient(): array
    {
        $category = Category::factory()->create();
        $workerUser = User::factory()->create();
        $worker = Worker::factory()->create([
            'user_id' => $workerUser->id,
            'category_id' => $category->id,
            'availability_status' => 'active',
        ]);
        $client = User::factory()->create();

        return [$client, $worker, $workerUser];
    }

    public function test_expira_pending_con_expires_at_vencido(): void
    {
        [$client, $worker] = $this->seedWorkerAndClient();

        $sr = ServiceRequest::create([
            'client_id' => $client->id,
            'worker_id' => $worker->id,
            'category_id' => $worker->category_id,
            'type' => 'fixed_job',
            'category_type' => 'fixed',
            'description' => 'Test',
            'urgency' => 'normal',
            'offered_price' => 10000,
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('jobshour:expire-pending-accept')->assertSuccessful();

        $sr->refresh();
        $this->assertSame('cancelled', $sr->status);
        $this->assertSame('auto_expired_worker_accept_window', $sr->cancellation_reason);
        $this->assertNotNull($sr->cancelled_at);
    }

    public function test_no_toca_pending_aun_vigente(): void
    {
        [$client, $worker] = $this->seedWorkerAndClient();

        $sr = ServiceRequest::create([
            'client_id' => $client->id,
            'worker_id' => $worker->id,
            'category_id' => $worker->category_id,
            'type' => 'fixed_job',
            'category_type' => 'fixed',
            'description' => 'Test',
            'urgency' => 'normal',
            'offered_price' => 10000,
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);

        $this->artisan('jobshour:expire-pending-accept')->assertSuccessful();

        $this->assertSame('pending', $sr->fresh()->status);
    }
}
