<?php

namespace Tests\Fakes;

use App\Services\IredMail\AuthService;
use App\Services\IredMail\CurrentActor;

final class FakeAuthService extends AuthService
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly string $version = 'test-security-version',
        private readonly string $password = 'current-password',
    ) {}

    public function refreshIdentity(string $email, string $source): ?array
    {
        if (strtolower($email) !== strtolower($this->actor->email)) {
            return null;
        }

        return [
            'actor' => $this->actor,
            'source' => $source,
            'version' => $this->version,
        ];
    }

    public function verifyIdentityPassword(string $email, string $source, string $password): bool
    {
        return strtolower($email) === strtolower($this->actor->email)
            && in_array($source, ['admin-record', 'mailbox-admin', 'mailbox-user'], true)
            && hash_equals($this->password, $password);
    }
}
