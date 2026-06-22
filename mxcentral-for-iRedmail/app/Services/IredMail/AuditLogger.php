<?php

namespace App\Services\IredMail;

use Illuminate\Support\Facades\DB;

final class AuditLogger
{
    public function log(string $event, string $message, ?string $domain = null, ?string $username = null, string $level = 'info'): void
    {
        try {
            DB::connection('iredadmin')->table('log')->insert([
                'admin' => CurrentActor::fromSession()?->email ?? 'system',
                'ip' => request()?->ip() ?? '',
                'domain' => $domain ?? '',
                'username' => $username ?? '',
                'event' => substr($event, 0, 20),
                'loglevel' => substr($level, 0, 10),
                'msg' => $message,
            ]);
        } catch (\Throwable) {
            report(new \RuntimeException('Unable to write iredadmin audit log.'));
        }
    }

    public function deletedMailbox(string $username, string $maildir, string $domain, ?int $keepDays = null): void
    {
        $deleteDate = $keepDays ? now()->addDays($keepDays)->toDateString() : null;

        DB::connection('iredadmin')->table('deleted_mailboxes')->insert([
            'username' => $username,
            'maildir' => $maildir,
            'domain' => $domain,
            'admin' => CurrentActor::fromSession()?->email ?? 'system',
            'delete_date' => $deleteDate,
        ]);
    }
}
