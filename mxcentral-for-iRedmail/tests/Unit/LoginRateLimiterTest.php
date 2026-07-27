<?php

namespace Tests\Unit;

use App\Services\IredMail\LoginRateLimiter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class LoginRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'iredmail.login_rate_cache_store' => 'array',
            'iredmail.login_rate_window' => 3600,
            'iredmail.login_account_lock_threshold' => 3,
            'iredmail.login_ip_lock_threshold' => 4,
            'iredmail.login_lock_seconds' => 60,
            'iredmail.login_max_lock_seconds' => 900,
        ]);
        Cache::store('array')->clear();
        Carbon::setTestNow('2026-07-28 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::store('array')->clear();

        parent::tearDown();
    }

    public function test_failures_apply_account_and_ip_delays_then_a_temporary_lockout(): void
    {
        $limiter = new LoginRateLimiter;

        $this->assertSame(1, $limiter->recordFailure('Admin@Example.COM', '192.0.2.10'));
        $this->assertSame(1, $limiter->retryAfter('admin@example.com', '192.0.2.10'));

        Carbon::setTestNow(now()->addSeconds(2));
        $this->assertSame(2, $limiter->recordFailure('admin@example.com', '192.0.2.10'));

        Carbon::setTestNow(now()->addSeconds(3));
        $this->assertSame(60, $limiter->recordFailure('admin@example.com', '192.0.2.10'));
        $this->assertSame(60, $limiter->retryAfter('admin@example.com', '198.51.100.20'));
    }

    public function test_unlock_command_clears_account_and_optional_ip_state(): void
    {
        $limiter = new LoginRateLimiter;
        $limiter->recordFailure('admin@example.com', '192.0.2.10');

        $this->artisan('mxcentral:unlock-admin', [
            'email' => 'ADMIN@example.com',
            '--ip' => '192.0.2.10',
        ])->assertSuccessful();

        $this->assertSame(0, $limiter->retryAfter('admin@example.com', '192.0.2.10'));
    }
}
