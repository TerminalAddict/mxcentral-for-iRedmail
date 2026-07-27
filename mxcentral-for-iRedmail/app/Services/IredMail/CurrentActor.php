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
    ) {}

    public static function fromSession(): ?self
    {
        if (! app()->bound('request')) {
            return null;
        }

        $actor = request()->attributes->get('mxcentral.current_actor');

        return $actor instanceof self ? $actor : null;
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
