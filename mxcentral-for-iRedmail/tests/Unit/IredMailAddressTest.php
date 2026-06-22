<?php

namespace Tests\Unit;

use App\Support\IredMailAddress;
use PHPUnit\Framework\TestCase;

final class IredMailAddressTest extends TestCase
{
    public function test_it_normalizes_valid_email(): void
    {
        $this->assertSame('user@example.com', IredMailAddress::email(' User@Example.COM '));
        $this->assertNull(IredMailAddress::email('not-an-email'));
    }

    public function test_it_validates_domains(): void
    {
        $this->assertSame('example.com', IredMailAddress::domain('Example.COM'));
        $this->assertNull(IredMailAddress::domain('bad_domain'));
        $this->assertNull(IredMailAddress::domain('user@example.com'));
    }

    public function test_it_reverses_amavisd_domains(): void
    {
        $this->assertSame('com.example.mail', IredMailAddress::amavisdDomain('mail.example.com'));
    }

    public function test_it_accepts_policy_addresses(): void
    {
        $this->assertTrue(IredMailAddress::validPolicyAddress('@.'));
        $this->assertTrue(IredMailAddress::validPolicyAddress('@example.com'));
        $this->assertTrue(IredMailAddress::validPolicyAddress('192.168.1.0/24'));
        $this->assertTrue(IredMailAddress::validPolicyAddress('user@*'));
        $this->assertFalse(IredMailAddress::validPolicyAddress('not valid'));
    }
}
