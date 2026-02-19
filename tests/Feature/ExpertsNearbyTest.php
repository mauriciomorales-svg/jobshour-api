<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpertsNearbyTest extends TestCase
{
    use RefreshDatabase;

    private function postgisAvailable(): bool
    {
        try {
            DB::select("SELECT ST_MakePoint(0,0)");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function test_nearby_sin_coordenadas_retorna_error(): void
    {
        if (!$this->postgisAvailable()) {
            $this->markTestSkipped('PostGIS no disponible en entorno de test');
        }

        $response = $this->getJson('/api/v1/experts/nearby');

        // Sin lat/lng el controller lanza error (500 o 422 según validación)
        $this->assertContains($response->status(), [422, 500]);
    }

    public function test_nearby_con_coordenadas_retorna_estructura(): void
    {
        if (!$this->postgisAvailable()) {
            $this->markTestSkipped('PostGIS no disponible en entorno de test');
        }

        $response = $this->getJson('/api/v1/experts/nearby?lat=-37.6672&lng=-72.5730');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_show_experto_inexistente_retorna_404(): void
    {
        $response = $this->getJson('/api/v1/experts/99999');

        $response->assertStatus(404);
    }

    public function test_categories_publico_retorna_lista(): void
    {
        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(200);
    }
}
