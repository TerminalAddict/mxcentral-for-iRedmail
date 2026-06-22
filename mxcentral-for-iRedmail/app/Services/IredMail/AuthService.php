<?php

namespace App\Services\IredMail;

use App\Support\IredMailAddress;
use App\Support\IredMailPassword;
use Illuminate\Support\Facades\DB;

final class AuthService
{
    public function attempt(string $email, string $password, string $mode = 'admin'): ?CurrentActor
    {
        $email = IredMailAddress::email($email);
        if (! $email || $password === '') {
            return null;
        }

        if ($mode === 'user') {
            return $this->attemptSelfService($email, $password);
        }

        $admin = DB::connection('vmail')->table('admin')
            ->where('username', $email)
            ->where('active', 1)
            ->first();

        if ($admin && IredMailPassword::verify($password, (string) $admin->password)) {
            return $this->actorForAdmin($email, 'admin');
        }

        $mailbox = DB::connection('vmail')->table('mailbox')
            ->where('username', $email)
            ->where('active', 1)
            ->where(function ($query) {
                $query->where('isadmin', 1)->orWhere('isglobaladmin', 1);
            })
            ->first();

        if ($mailbox && IredMailPassword::verify($password, (string) $mailbox->password)) {
            return $this->actorForAdmin($email, 'mailbox-admin', (int) ($mailbox->isglobaladmin ?? 0) === 1);
        }

        return null;
    }

    private function attemptSelfService(string $email, string $password): ?CurrentActor
    {
        $mailbox = DB::connection('vmail')->table('mailbox')
            ->where('username', $email)
            ->where('active', 1)
            ->first();

        if (! $mailbox || ! IredMailPassword::verify($password, (string) $mailbox->password)) {
            return null;
        }

        return new CurrentActor($email, 'user', false, false, true, [IredMailAddress::domainOf($email)]);
    }

    private function actorForAdmin(string $email, string $type, bool $mailboxGlobal = false): CurrentActor
    {
        $domains = DB::connection('vmail')->table('domain_admins')
            ->where('username', $email)
            ->pluck('domain')
            ->map(fn ($domain) => strtolower((string) $domain))
            ->all();

        $global = $mailboxGlobal || in_array('all', $domains, true);
        $managed = array_values(array_filter($domains, fn ($domain) => $domain !== 'all'));

        return new CurrentActor($email, $type, $global, ! $global, false, $managed);
    }
}
