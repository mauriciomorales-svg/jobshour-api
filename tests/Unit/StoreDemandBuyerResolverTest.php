<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\StoreDemandBuyerResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StoreDemandBuyerResolverTest extends TestCase
{
    use DatabaseTransactions;

    public function test_crea_usuario_employer_si_no_existe(): void
    {
        $email = 'nuevo-buyer-'.uniqid().'@test.cl';
        $resolver = new StoreDemandBuyerResolver;

        $result = $resolver->resolveOrCreate($email, 'Pedro', '+56987654321');

        $this->assertFalse($result['existed']);
        $this->assertSame($email, $result['user']->email);
        $this->assertSame('employer', $result['user']->type);
        $this->assertDatabaseHas('users', ['email' => $email]);
    }

    public function test_reutiliza_usuario_por_email(): void
    {
        $user = User::factory()->create(['email' => 'reuse@test.cl']);
        $resolver = new StoreDemandBuyerResolver;

        $result = $resolver->resolveOrCreate('reuse@test.cl', 'Otro nombre');

        $this->assertTrue($result['existed']);
        $this->assertSame($user->id, $result['user']->id);
    }
}
