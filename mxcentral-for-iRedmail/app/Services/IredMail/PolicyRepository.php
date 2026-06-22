<?php

namespace App\Services\IredMail;

use App\Support\IredMailAddress;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

final class PolicyRepository
{
    public function throttle(CurrentActor $actor, ?string $account = null): LengthAwarePaginator
    {
        $query = DB::connection('iredapd')->table('throttle');
        if ($account) {
            $this->assertPolicyAccess($actor, $account);
            $query->where('account', $this->policyAccount($account));
        } elseif (! $actor->globalAdmin) {
            $accounts = array_merge($actor->domains, array_map(fn ($domain) => '@'.$domain, $actor->domains));
            $query->whereIn('account', $accounts ?: ['']);
        }

        return $query->orderBy('account')->orderBy('kind')->paginate(config('iredmail.page_size'));
    }

    public function saveThrottle(CurrentActor $actor, array $data): void
    {
        $account = $this->policyAccount($data['account'] ?? '@.');
        $this->assertPolicyAccess($actor, $account);
        $kind = in_array($data['kind'] ?? '', ['inbound', 'outbound'], true) ? $data['kind'] : 'outbound';

        DB::connection('iredapd')->table('throttle')->updateOrInsert(
            ['account' => $account, 'kind' => $kind],
            [
                'period' => (int) ($data['period'] ?? 0),
                'msg_size' => (int) ($data['msg_size'] ?? -1),
                'max_msgs' => (int) ($data['max_msgs'] ?? -1),
                'max_quota' => (int) ($data['max_quota'] ?? -1),
                'max_rcpts' => (int) ($data['max_rcpts'] ?? -1),
            ],
        );
    }

    public function wblist(CurrentActor $actor, ?string $account = null): LengthAwarePaginator
    {
        $query = DB::connection('amavisd')->table('wblist')
            ->leftJoin('mailaddr', 'wblist.sid', '=', 'mailaddr.id')
            ->select('wblist.*', 'mailaddr.email as sender');

        if ($account) {
            $this->assertPolicyAccess($actor, $account);
            $query->where('wblist.rid', $this->mailaddrId($this->policyAccount($account)));
        }

        return $query->orderByDesc('wblist.rid')->paginate(config('iredmail.page_size'));
    }

    public function addWblist(CurrentActor $actor, array $data): void
    {
        $recipient = $this->policyAccount($data['recipient'] ?? '@.');
        $sender = strtolower(trim($data['sender'] ?? ''));
        $wb = in_array($data['wb'] ?? '', ['W', 'B'], true) ? $data['wb'] : 'W';

        $this->assertPolicyAccess($actor, $recipient);
        if (! IredMailAddress::validPolicyAddress($sender)) {
            throw ValidationException::withMessages(['sender' => 'Enter a valid sender, domain, IP, or network.']);
        }

        DB::connection('amavisd')->table('wblist')->updateOrInsert(
            ['rid' => $this->mailaddrId($recipient), 'sid' => $this->mailaddrId($sender)],
            ['wb' => $wb],
        );
    }

    public function fail2ban(CurrentActor $actor): LengthAwarePaginator
    {
        abort_unless($actor->globalAdmin, 403);

        return DB::connection('fail2ban')->table('banned')->orderByDesc('id')->paginate(config('iredmail.page_size'));
    }

    public function unban(CurrentActor $actor, string $ip): array
    {
        abort_unless($actor->globalAdmin, 403);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw ValidationException::withMessages(['ip' => 'Invalid IP address.']);
        }

        DB::connection('fail2ban')->table('banned')->where('ip', $ip)->update(['remove' => 1]);

        $command = config('iredmail.fail2ban_unban_command');
        if ($command !== '') {
            $process = new Process(array_merge($this->commandArguments($command), [$ip]));
            $process->setTimeout(30);
            $process->run();

            return [
                'marked' => true,
                'configured' => true,
                'ok' => $process->isSuccessful(),
                'message' => trim($process->getOutput()."\n".$process->getErrorOutput()),
            ];
        }

        return ['marked' => true, 'configured' => false, 'ok' => true, 'message' => 'No direct unban command configured.'];
    }

    private function commandArguments(string $command): array
    {
        return array_values(array_filter(str_getcsv($command, ' ', '"', '\\'), fn (string $argument) => $argument !== ''));
    }

    private function assertPolicyAccess(CurrentActor $actor, string $account): void
    {
        if ($account === '@.') {
            abort_unless($actor->globalAdmin, 403);
            return;
        }

        $domain = str_starts_with($account, '@') ? ltrim($account, '@.') : IredMailAddress::domainOf($account);
        abort_unless($actor->canManageDomain($domain) || $actor->canManageEmail($account), 403);
    }

    private function policyAccount(string $account): string
    {
        $account = strtolower(trim($account));
        if ($account === '@.') {
            return $account;
        }
        if (IredMailAddress::email($account)) {
            return $account;
        }
        if (IredMailAddress::domain(ltrim($account, '@.'))) {
            return str_starts_with($account, '@') ? $account : '@'.$account;
        }

        throw ValidationException::withMessages(['account' => 'Invalid policy account.']);
    }

    private function mailaddrId(string $email): int
    {
        $domain = str_contains($email, '@') ? IredMailAddress::domainOf($email) : ltrim($email, '@');
        $domain = $domain === '.' ? '.' : IredMailAddress::amavisdDomain($domain);

        $id = DB::connection('amavisd')->table('mailaddr')->where('email', $email)->value('id');
        if ($id) {
            return (int) $id;
        }

        return (int) DB::connection('amavisd')->table('mailaddr')->insertGetId(['priority' => 7, 'email' => $email, 'domain' => $domain]);
    }
}
