<?php

namespace Tests\Unit;

use App\Support\ClientIp;
use PHPUnit\Framework\TestCase;

class ClientIpTest extends TestCase
{
    public function test_parses_ip_with_or_without_port(): void
    {
        $this->assertSame('192.0.2.10', ClientIp::parse('192.0.2.10'));
        $this->assertSame('192.0.2.10', ClientIp::parse('192.0.2.10:46532'));
        $this->assertSame('2001:db8::1', ClientIp::parse('2001:db8::1'));
        $this->assertSame('2001:db8::1', ClientIp::parse('[2001:db8::1]:443'));
        $this->assertNull(ClientIp::parse('not-an-ip'));
        $this->assertNull(ClientIp::parse(''));
    }
}
