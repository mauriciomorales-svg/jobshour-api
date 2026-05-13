<?php

namespace Tests\Feature;

use Tests\TestCase;

class AssignRequestIdMiddlewareTest extends TestCase
{
    public function test_health_ping_includes_x_request_id_header(): void
    {
        $response = $this->getJson('/api/v1/health/ping');

        $response->assertOk();
        $response->assertHeader('X-Request-Id');
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_client_x_request_id_is_echoed_on_response(): void
    {
        $rid = 'qa-trace-abc-123';
        $response = $this->withHeaders(['X-Request-Id' => $rid])
            ->getJson('/api/v1/health/ping');

        $response->assertOk();
        $this->assertSame($rid, $response->headers->get('X-Request-Id'));
    }
}
