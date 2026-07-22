<?php

namespace Tests\Unit;

use App\Services\IredMail\AuditLogger;
use App\Services\IredMail\SystemSettingsService;
use ReflectionClass;
use Tests\TestCase;

final class SystemSettingsServiceTest extends TestCase
{
    public function test_it_accepts_non_octet_boundary_ipv4_cidr_networks(): void
    {
        $this->assertSame(
            ['103.123.164.0/22'],
            $this->invokePrivate('normalizeNetworks', ['103.123.164.0/22'])
        );
    }

    public function test_sender_access_pattern_matches_non_octet_boundary_ipv4_cidr(): void
    {
        $block = $this->invokePrivate('senderAccessBlock', [[], ['103.123.164.0/22']]);

        $this->assertMatchesRegularExpression('/103/', $block);
        $this->assertTrue($this->senderAccessBlockMatches($block, '103.123.164.0'));
        $this->assertTrue($this->senderAccessBlockMatches($block, '103.123.165.42'));
        $this->assertTrue($this->senderAccessBlockMatches($block, '103.123.167.255'));
        $this->assertFalse($this->senderAccessBlockMatches($block, '103.123.163.255'));
        $this->assertFalse($this->senderAccessBlockMatches($block, '103.123.168.0'));
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokePrivate(string $method, array $arguments): mixed
    {
        $service = new SystemSettingsService(new AuditLogger());
        $reflectionMethod = (new ReflectionClass($service))->getMethod($method);

        return $reflectionMethod->invokeArgs($service, $arguments);
    }

    private function senderAccessBlockMatches(string $block, string $ip): bool
    {
        foreach (explode("\n", $block) as $line) {
            if (! str_ends_with($line, ' OK')) {
                continue;
            }

            $pattern = substr($line, 0, -3);
            if (@preg_match($pattern, $ip) === 1) {
                return true;
            }
        }

        return false;
    }
}
