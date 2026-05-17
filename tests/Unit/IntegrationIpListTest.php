<?php

namespace Tests\Unit;

use App\Support\IntegrationIpList;
use PHPUnit\Framework\TestCase;

class IntegrationIpListTest extends TestCase
{
    public function test_normaliza_ipv4(): void
    {
        $err = null;
        $list = IntegrationIpList::normalizeOrNull([' 10.0.0.1 ', '10.0.0.2', '10.0.0.1'], $err);
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $list);
        $this->assertNull($err);
    }

    public function test_rechaza_invalida(): void
    {
        $err = null;
        $list = IntegrationIpList::normalizeOrNull(['999.0.0.1'], $err);
        $this->assertNull($list);
        $this->assertStringContainsString('inválida', (string) $err);
    }
}
