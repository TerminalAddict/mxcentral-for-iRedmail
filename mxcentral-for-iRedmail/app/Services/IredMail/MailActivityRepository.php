<?php

namespace App\Services\IredMail;

use App\Support\IredMailAddress;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class MailActivityRepository
{
    public function logs(CurrentActor $actor, string $direction, ?string $account = null): LengthAwarePaginator
    {
        $query = DB::connection('amavisd')->table('msgs')
            ->leftJoin('msgrcpt', 'msgs.mail_id', '=', 'msgrcpt.mail_id')
            ->leftJoin('maddr as sender', 'msgs.sid', '=', 'sender.id')
            ->leftJoin('maddr as recip', 'msgrcpt.rid', '=', 'recip.id')
            ->select('msgs.mail_id', 'msgs.subject', 'msgs.spam_level', 'msgs.size', 'msgs.time_num', 'sender.email as sender_email', 'recip.email as recipient');

        $this->scopeMailLog($query, $actor, $direction, $account);

        return $query->where('msgs.quar_type', '<>', 'Q')
            ->orderByDesc('msgs.time_num')
            ->paginate(config('iredmail.page_size'));
    }

    public function quarantined(CurrentActor $actor, ?string $type = null, ?string $account = null): LengthAwarePaginator
    {
        $query = DB::connection('amavisd')->table('msgs')
            ->leftJoin('msgrcpt', 'msgs.mail_id', '=', 'msgrcpt.mail_id')
            ->leftJoin('maddr as sender', 'msgs.sid', '=', 'sender.id')
            ->leftJoin('maddr as recip', 'msgrcpt.rid', '=', 'recip.id')
            ->select('msgs.mail_id', 'msgs.secret_id', 'msgs.subject', 'msgs.content', 'msgs.spam_level', 'msgs.size', 'msgs.time_num', 'sender.email as sender_email', 'recip.email as recipient')
            ->where('msgs.quar_type', 'Q');

        $this->scopeEitherSide($query, $actor, $account);

        $content = ['spam' => ['S', 's', 'Y'], 'virus' => ['V'], 'banned' => ['B'], 'badheader' => ['H'], 'badmime' => ['M']];
        if ($type && isset($content[$type])) {
            $query->whereIn('msgs.content', $content[$type]);
        }

        return $query->orderByDesc('msgs.time_num')->paginate(config('iredmail.page_size'));
    }

    public function rawMessage(CurrentActor $actor, string $mailId): string
    {
        $mailId = $this->safeProtocolToken($mailId, 'mail_id');

        $allowed = DB::connection('amavisd')->table('msgs')
            ->leftJoin('msgrcpt', 'msgs.mail_id', '=', 'msgrcpt.mail_id')
            ->leftJoin('maddr as sender', 'msgs.sid', '=', 'sender.id')
            ->leftJoin('maddr as recip', 'msgrcpt.rid', '=', 'recip.id')
            ->where('msgs.mail_id', $mailId);
        $this->scopeEitherSide($allowed, $actor);
        abort_unless($allowed->exists(), 403);

        return DB::connection('amavisd')->table('quarantine')
            ->where('mail_id', $mailId)
            ->orderBy('chunk_ind')
            ->pluck('mail_text')
            ->implode('');
    }

    public function deleteQuarantine(CurrentActor $actor, array $mailIds): int
    {
        $mailIds = $this->filterMailIds($mailIds);
        if ($mailIds === []) {
            return 0;
        }

        $allowed = DB::connection('amavisd')->table('msgs')
            ->leftJoin('msgrcpt', 'msgs.mail_id', '=', 'msgrcpt.mail_id')
            ->leftJoin('maddr as sender', 'msgs.sid', '=', 'sender.id')
            ->leftJoin('maddr as recip', 'msgrcpt.rid', '=', 'recip.id')
            ->whereIn('msgs.mail_id', $mailIds);
        $this->scopeEitherSide($allowed, $actor);
        $allowedIds = $allowed->pluck('msgs.mail_id')->all();

        DB::connection('amavisd')->table('quarantine')->whereIn('mail_id', $allowedIds)->delete();
        return DB::connection('amavisd')->table('msgs')->whereIn('mail_id', $allowedIds)->delete();
    }

    public function release(CurrentActor $actor, string $mailId, string $secretId): bool
    {
        $mailId = $this->safeProtocolToken($mailId, 'mail_id');
        $secretId = $this->safeProtocolToken($secretId, 'secret_id');

        $record = $this->quarantined($actor)->getCollection()->firstWhere('mail_id', $mailId);
        abort_unless($record, 403);

        $socket = @fsockopen(config('iredmail.amavisd_quarantine_host'), config('iredmail.amavisd_quarantine_port'), $errno, $errstr, 10);
        if (! $socket) {
            return false;
        }

        fwrite($socket, "request=release\r\nmail_id={$mailId}\r\nsecret_id={$secretId}\r\nquar_type=Q\r\n\r\n");
        $response = stream_get_contents($socket);
        fclose($socket);

        return str_contains((string) $response, 'setreply=250');
    }

    private function scopeMailLog(Builder $query, CurrentActor $actor, string $direction, ?string $account): void
    {
        if ($account) {
            if (str_contains($account, '@')) {
                abort_unless($actor->canManageEmail($account), 403);
                $column = $direction === 'sent' ? 'sender.email' : 'recip.email';
                $query->where($column, strtolower($account));
                return;
            }

            abort_unless($actor->canManageDomain($account), 403);
            $column = $direction === 'sent' ? 'sender.domain' : 'recip.domain';
            $query->where($column, IredMailAddress::amavisdDomain($account));
            return;
        }

        if (! $actor->globalAdmin) {
            $domains = array_map([IredMailAddress::class, 'amavisdDomain'], $actor->domains);
            $column = $direction === 'sent' ? 'sender.domain' : 'recip.domain';
            $query->whereIn($column, $domains ?: ['']);
        }
    }

    private function scopeEitherSide(Builder $query, CurrentActor $actor, ?string $account = null): void
    {
        if ($actor->selfService) {
            $query->where(fn (Builder $query) => $query->where('sender.email', $actor->email)->orWhere('recip.email', $actor->email));
            return;
        }

        if ($account && str_contains($account, '@')) {
            abort_unless($actor->canManageEmail($account), 403);
            $query->where(fn (Builder $query) => $query->where('sender.email', strtolower($account))->orWhere('recip.email', strtolower($account)));
            return;
        }

        $domains = $account ? [$account] : $actor->domains;
        if (! $actor->globalAdmin || $account) {
            foreach ($domains as $domain) {
                abort_unless($actor->canManageDomain($domain), 403);
            }
            $reversed = array_map([IredMailAddress::class, 'amavisdDomain'], $domains);
            $query->where(fn (Builder $query) => $query->whereIn('sender.domain', $reversed ?: [''])->orWhereIn('recip.domain', $reversed ?: ['']));
        }
    }

    private function filterMailIds(array $mailIds): array
    {
        return array_values(array_filter(array_unique($mailIds), fn ($id) => is_string($id) && preg_match('/^[A-Za-z0-9+_.-]+$/', $id)));
    }

    private function safeProtocolToken(string $value, string $field): string
    {
        abort_unless($value !== '' && strlen($value) <= 255 && preg_match('/^[A-Za-z0-9+_.@:-]+$/', $value), 400, "Invalid {$field}.");

        return $value;
    }
}
