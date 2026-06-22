<?php

namespace App\Services\IredMail;

use App\Support\IredMailAddress;

final class CurrentActor
{
    public function __construct(
        public readonly string $email,
        public readonly string $type,
        public readonly bool $globalAdmin,
        public readonly bool $domainAdmin,
        public readonly bool $selfService,
        public readonly array $domains = [],
    ) {
    }

    public static function fromSession(): ?self
    {
        $data = session('actor');
        if (! is_array($data) || empty($data['email'])) {
            return null;
        }

        return new self(
            email: $data['email'],
            type: $data['type'] ?? 'user',
            globalAdmin: (bool) ($data['global_admin'] ?? false),
            domainAdmin: (bool) ($data['domain_admin'] ?? false),
            selfService: (bool) ($data['self_service'] ?? false),
            domains: $data['domains'] ?? [],
        );
    }

    public function canManageDomain(string $domain): bool
    {
        return $this->globalAdmin || in_array(strtolower($domain), $this->domains, true);
    }

    public function canManageEmail(string $email): bool
    {
        if ($this->selfService) {
            return strtolower($email) === $this->email;
        }

        return $this->canManageDomain(IredMailAddress::domainOf($email));
    }
}
