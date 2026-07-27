<?php

namespace App\Services\IredMail;

use App\Support\IredMailAddress;
use App\Support\IredMailPassword;
use Illuminate\Support\Facades\DB;

class AuthService
{
    public function attempt(string $email, string $password, string $mode = 'admin'): ?CurrentActor
    {
        return $this->attemptIdentity($email, $password, $mode)['actor'] ?? null;
    }

    /**
     * @return array{actor: CurrentActor, source: string, version: string}|null
     */
    public function attemptIdentity(string $email, string $password, string $mode = 'admin'): ?array
    {
        $email = IredMailAddress::email($email);
        if (! $email || $password === '') {
            return null;
        }

        if ($mode === 'user') {
            $mailbox = $this->activeMailbox($email);
            if (! $mailbox || ! IredMailPassword::verify($password, (string) $mailbox->password)) {
                return null;
            }

            return $this->identityForMailboxUser($email, $mailbox);
        }

        $admin = DB::connection('vmail')->table('admin')
            ->where('username', $email)
            ->where('active', 1)
            ->first();

        if ($admin && IredMailPassword::verify($password, (string) $admin->password)) {
            return $this->identityForAdminRecord($email, $admin);
        }

        $mailbox = DB::connection('vmail')->table('mailbox')
            ->where('username', $email)
            ->where('active', 1)
            ->where(function ($query) {
                $query->where('isadmin', 1)->orWhere('isglobaladmin', 1);
            })
            ->first();

        if ($mailbox && IredMailPassword::verify($password, (string) $mailbox->password)) {
            return $this->identityForMailboxAdmin($email, $mailbox);
        }

        return null;
    }

    /**
     * Reload an identity from vmail and calculate its current security version.
     *
     * @return array{actor: CurrentActor, source: string, version: string}|null
     */
    public function refreshIdentity(string $email, string $source): ?array
    {
        $email = IredMailAddress::email($email);
        if (! $email) {
            return null;
        }

        if ($source === 'mailbox-user') {
            $mailbox = $this->activeMailbox($email);

            return $mailbox ? $this->identityForMailboxUser($email, $mailbox) : null;
        }

        if ($source === 'admin-record') {
            $admin = DB::connection('vmail')->table('admin')
                ->where('username', $email)
                ->where('active', 1)
                ->first();

            return $admin ? $this->identityForAdminRecord($email, $admin) : null;
        }

        if ($source === 'mailbox-admin') {
            $mailbox = DB::connection('vmail')->table('mailbox')
                ->where('username', $email)
                ->where('active', 1)
                ->where(function ($query) {
                    $query->where('isadmin', 1)->orWhere('isglobaladmin', 1);
                })
                ->first();

            return $mailbox ? $this->identityForMailboxAdmin($email, $mailbox) : null;
        }

        return null;
    }

    public function verifyIdentityPassword(string $email, string $source, string $password): bool
    {
        $email = IredMailAddress::email($email);
        if (! $email || $password === '') {
            return false;
        }

        $table = $source === 'admin-record' ? 'admin' : 'mailbox';
        if (! in_array($source, ['admin-record', 'mailbox-admin', 'mailbox-user'], true)) {
            return false;
        }

        $record = DB::connection('vmail')->table($table)
            ->where('username', $email)
            ->where('active', 1)
            ->first();

        return $record && IredMailPassword::verify($password, (string) $record->password);
    }

    private function activeMailbox(string $email): ?object
    {
        return DB::connection('vmail')->table('mailbox')
            ->where('username', $email)
            ->where('active', 1)
            ->first();
    }

    private function identityForMailboxUser(string $email, object $mailbox): array
    {
        $actor = new CurrentActor($email, 'user', false, false, true, [IredMailAddress::domainOf($email)]);

        return [
            'actor' => $actor,
            'source' => 'mailbox-user',
            'version' => $this->securityVersion($mailbox, []),
        ];
    }

    private function identityForAdminRecord(string $email, object $admin): ?array
    {
        [$actor, $domains] = $this->actorForAdmin($email, 'admin');
        if ($domains === []) {
            return null;
        }

        return [
            'actor' => $actor,
            'source' => 'admin-record',
            'version' => $this->securityVersion($admin, $domains),
        ];
    }

    private function identityForMailboxAdmin(string $email, object $mailbox): array
    {
        [$actor, $domains] = $this->actorForAdmin(
            $email,
            'mailbox-admin',
            (int) ($mailbox->isglobaladmin ?? 0) === 1,
        );

        return [
            'actor' => $actor,
            'source' => 'mailbox-admin',
            'version' => $this->securityVersion($mailbox, $domains),
        ];
    }

    /**
     * @return array{CurrentActor, array<int, string>}
     */
    private function actorForAdmin(string $email, string $type, bool $mailboxGlobal = false): array
    {
        $domains = DB::connection('vmail')->table('domain_admins')
            ->where('username', $email)
            ->where('active', 1)
            ->pluck('domain')
            ->map(fn ($domain) => strtolower((string) $domain))
            ->all();

        $global = $mailboxGlobal || in_array('all', $domains, true);
        $managed = array_values(array_filter($domains, fn ($domain) => $domain !== 'all'));

        return [
            new CurrentActor($email, $type, $global, ! $global, false, $managed),
            $domains,
        ];
    }

    private function securityVersion(object $record, array $domains): string
    {
        sort($domains);

        return hash('sha256', json_encode([
            'password' => (string) ($record->password ?? ''),
            'passwordlastchange' => (string) ($record->passwordlastchange ?? ''),
            'active' => (int) ($record->active ?? 0),
            'isadmin' => (int) ($record->isadmin ?? 0),
            'isglobaladmin' => (int) ($record->isglobaladmin ?? 0),
            'modified' => (string) ($record->modified ?? ''),
            'domains' => $domains,
        ], JSON_THROW_ON_ERROR));
    }
}
