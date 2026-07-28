<?php

namespace App\Services\IredMail;

final class PasswordRevealAccess
{
    public function allows(CurrentActor $actor): bool
    {
        return $actor->globalAdmin
            && in_array(strtolower($actor->email), $this->allowedAdmins(), true)
            && (! $this->requiresTotp() || $this->totpSecret($actor->email) !== null);
    }

    public function requiresTotp(): bool
    {
        return (bool) config('iredmail.password_reveal_require_totp', true);
    }

    public function totpSecret(string $email): ?string
    {
        $configured = json_decode((string) config('iredmail.password_reveal_totp_secrets', '{}'), true);
        if (! is_array($configured)) {
            return null;
        }

        $secret = $configured[strtolower($email)] ?? null;

        return is_string($secret) && trim($secret) !== '' ? strtoupper(trim($secret)) : null;
    }

    private function allowedAdmins(): array
    {
        return array_values(array_filter(array_map(
            fn (string $email): string => strtolower(trim($email)),
            preg_split('/[,;\s]+/', (string) config('iredmail.password_reveal_admins', '')) ?: [],
        )));
    }
}
