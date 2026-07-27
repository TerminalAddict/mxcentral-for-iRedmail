<?php

namespace App\Services\IredMail;

use App\Support\IredMailAddress;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

final class LoginRateLimiter
{
    public function normalizedAccount(string $account): string
    {
        $account = strtolower(trim($account));

        return IredMailAddress::email($account) ?? mb_substr($account, 0, 254);
    }

    public function retryAfter(string $account, string $ip): int
    {
        $now = now()->timestamp;

        return max(
            $this->remainingDelay($this->state($this->accountKey($account)), $now),
            $this->remainingDelay($this->state($this->ipKey($ip)), $now),
        );
    }

    public function recordFailure(string $account, string $ip): int
    {
        $accountDelay = $this->record($this->accountKey($account), (int) config('iredmail.login_account_lock_threshold', 5));
        $ipDelay = $this->record($this->ipKey($ip), (int) config('iredmail.login_ip_lock_threshold', 10));

        return max($accountDelay, $ipDelay);
    }

    public function clearAccount(string $account): void
    {
        $this->cache()->forget($this->accountKey($account));
    }

    public function clearIp(string $ip): void
    {
        $this->cache()->forget($this->ipKey($ip));
    }

    private function record(string $key, int $lockThreshold): int
    {
        $lock = $this->cache()->lock($key.':lock', 10);

        return $lock->block(5, function () use ($key, $lockThreshold): int {
            $now = now()->timestamp;
            $state = $this->state($key);
            $window = max(60, (int) config('iredmail.login_rate_window', 3600));

            if (($state['window_started_at'] ?? 0) + $window <= $now) {
                $state = [];
            }

            $failures = ((int) ($state['failures'] ?? 0)) + 1;
            $delay = $this->delayFor($failures, $lockThreshold);
            $updated = [
                'failures' => $failures,
                'window_started_at' => (int) ($state['window_started_at'] ?? $now),
                'locked_until' => $now + $delay,
            ];
            $this->cache()->put($key, $updated, $window);

            return $delay;
        });
    }

    private function delayFor(int $failures, int $lockThreshold): int
    {
        $maximum = max(60, (int) config('iredmail.login_max_lock_seconds', 900));
        if ($failures >= $lockThreshold) {
            $base = max(30, (int) config('iredmail.login_lock_seconds', 60));

            return min($maximum, $base * (2 ** min(4, $failures - $lockThreshold)));
        }

        return min(8, 2 ** max(0, $failures - 1));
    }

    private function remainingDelay(array $state, int $now): int
    {
        return max(0, ((int) ($state['locked_until'] ?? 0)) - $now);
    }

    private function state(string $key): array
    {
        $state = $this->cache()->get($key);

        return is_array($state) ? $state : [];
    }

    private function accountKey(string $account): string
    {
        return 'mxcentral:login-rate:v1:account:'.hash('sha256', $this->normalizedAccount($account));
    }

    private function ipKey(string $ip): string
    {
        return 'mxcentral:login-rate:v1:ip:'.hash('sha256', trim($ip));
    }

    private function cache(): Repository
    {
        return Cache::store((string) config('iredmail.login_rate_cache_store', 'file'));
    }
}
