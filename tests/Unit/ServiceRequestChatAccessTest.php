<?php

namespace Tests\Unit;

use App\Models\ServiceRequest;
use App\Models\Worker;
use App\Support\ServiceRequestChatAccess;
use Tests\TestCase;

class ServiceRequestChatAccessTest extends TestCase
{
    public function test_taken_public_demand_parent_denies_chat(): void
    {
        $sr = new ServiceRequest([
            'status' => 'pending',
            'worker_id' => null,
            'taken_by_worker_id' => 5,
            'client_id' => 10,
        ]);

        $this->assertTrue(ServiceRequestChatAccess::isTakenPublicDemandParent($sr));
        $this->assertFalse(ServiceRequestChatAccess::canAccess($sr, 99));
        $this->assertFalse(ServiceRequestChatAccess::canAccess($sr, 10));
    }

    public function test_assigned_pending_allows_participants(): void
    {
        $sr = new ServiceRequest([
            'status' => 'pending',
            'worker_id' => 3,
            'client_id' => 10,
        ]);
        $sr->setRelation('worker', new Worker(['user_id' => 20]));

        $this->assertTrue(ServiceRequestChatAccess::canAccess($sr, 10));
        $this->assertTrue(ServiceRequestChatAccess::canAccess($sr, 20));
        $this->assertTrue(ServiceRequestChatAccess::allowsSending($sr));
    }

    public function test_completed_allows_sending(): void
    {
        $sr = new ServiceRequest(['status' => 'completed', 'client_id' => 1, 'worker_id' => 2]);
        $this->assertTrue(ServiceRequestChatAccess::allowsSending($sr));
    }
}
