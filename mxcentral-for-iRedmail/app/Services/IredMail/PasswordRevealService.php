<?php

namespace App\Services\IredMail;

use App\Support\IredMailAddress;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class PasswordRevealService
{
    public function __construct(
        private readonly PasswordRevealAccess $access,
        private readonly AuthService $auth,
        private readonly AccountRepository $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function request(
        CurrentActor $actor,
        string $source,
        string $targetEmail,
        string $currentPassword,
        string $totpCode,
        string $purpose,
    ): string {
        abort_unless($this->access->allows($actor), 403);
        $targetEmail = IredMailAddress::email($targetEmail) ?? abort(404);
        $purpose = trim($purpose);
        if (mb_strlen($purpose) < 10 || mb_strlen($purpose) > 500) {
            throw ValidationException::withMessages([
                'purpose' => 'Describe the access purpose in 10 to 500 characters.',
            ]);
        }
        if (! $this->auth->verifyIdentityPassword($actor->email, $source, $currentPassword)) {
            throw ValidationException::withMessages([
                'current_password' => 'Current administrator password verification failed.',
            ]);
        }

        if ($this->access->requiresTotp()) {
            $secret = $this->access->totpSecret($actor->email);
            if (! $secret || ! $this->verifyTotp($secret, $totpCode)) {
                throw ValidationException::withMessages([
                    'totp_code' => 'The one-time authentication code is invalid.',
                ]);
            }
        }
        if (! $this->accounts->hasDecryptablePassword($actor, $targetEmail)) {
            throw ValidationException::withMessages([
                'password' => 'This mailbox does not have a stored decryptable password.',
            ]);
        }

        $token = bin2hex(random_bytes(32));
        $this->cache()->put($this->tokenKey($token), [
            'actor' => strtolower($actor->email),
            'target' => $targetEmail,
            'purpose' => $purpose,
        ], max(15, (int) config('iredmail.password_reveal_token_seconds', 60)));

        $this->audit->log(
            'view',
            "Authorized one-time decryptable password reveal for {$targetEmail}. Purpose: {$purpose}",
            IredMailAddress::domainOf($targetEmail),
            $targetEmail,
        );

        return $token;
    }

    public function consume(CurrentActor $actor, string $token): array
    {
        abort_unless($this->access->allows($actor), 403);
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token) === 1, 404);

        $key = $this->tokenKey($token);
        $state = $this->cache()->lock($key.':lock', 10)->block(5, function () use ($key) {
            $state = $this->cache()->get($key);
            $this->cache()->forget($key);

            return $state;
        });
        abort_unless(
            is_array($state)
            && is_string($state['actor'] ?? null)
            && is_string($state['target'] ?? null)
            && is_string($state['purpose'] ?? null)
            && hash_equals($state['actor'], strtolower($actor->email)),
            404,
        );

        $password = $this->accounts->revealDecryptablePassword($actor, (string) $state['target']);
        abort_unless($password !== null, 404);

        return [
            'email' => (string) $state['target'],
            'password' => $password,
            'purpose' => (string) $state['purpose'],
        ];
    }

    private function verifyTotp(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $key = $this->decodeBase32($secret);
        if ($key === null) {
            return false;
        }

        $counter = intdiv(now()->timestamp, 30);
        foreach (range(-1, 1) as $offset) {
            $binaryCounter = pack('N2', 0, $counter + $offset);
            $hash = hash_hmac('sha1', $binaryCounter, $key, true);
            $position = ord($hash[19]) & 0x0F;
            $number = (
                ((ord($hash[$position]) & 0x7F) << 24)
                | ((ord($hash[$position + 1]) & 0xFF) << 16)
                | ((ord($hash[$position + 2]) & 0xFF) << 8)
                | (ord($hash[$position + 3]) & 0xFF)
            ) % 1_000_000;
            if (hash_equals(str_pad((string) $number, 6, '0', STR_PAD_LEFT), $code)) {
                return true;
            }
        }

        return false;
    }

    private function decodeBase32(string $value): ?string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $value = rtrim(strtoupper(preg_replace('/[\s-]+/', '', $value) ?? ''), '=');
        if ($value === '' || preg_match('/^[A-Z2-7]+$/', $value) !== 1) {
            return null;
        }

        $bits = '';
        foreach (str_split($value) as $character) {
            $position = strpos($alphabet, $character);
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded === '' ? null : $decoded;
    }

    private function tokenKey(string $token): string
    {
        return 'mxcentral:password-reveal:v1:'.hash('sha256', $token);
    }

    private function cache(): Repository
    {
        return Cache::store((string) config('iredmail.password_reveal_cache_store', 'file'));
    }
}
