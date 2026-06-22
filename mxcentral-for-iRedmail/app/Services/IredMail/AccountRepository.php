<?php

namespace App\Services\IredMail;

use App\Support\IredMailAddress;
use App\Support\IredMailPassword;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountRepository
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function dashboard(CurrentActor $actor): array
    {
        return [
            'domains' => $this->visibleDomains($actor)->count(),
            'users' => $this->visibleUsers($actor)->count(),
            'aliases' => $this->visibleAliases($actor)->count(),
            'lists' => $this->visibleLists($actor)->count(),
            'admins' => $actor->globalAdmin ? DB::connection('vmail')->table('domain_admins')->distinct()->count('username') : null,
        ];
    }

    public function domains(CurrentActor $actor, ?string $search = null): LengthAwarePaginator
    {
        $query = $this->visibleDomains($actor)->orderBy('domain');
        if ($search) {
            $query->where('domain', 'like', '%'.strtolower($search).'%');
        }

        return $query->paginate(config('iredmail.page_size'));
    }

    public function domainOptions(CurrentActor $actor)
    {
        return $this->visibleDomains($actor)
            ->select('domain')
            ->orderBy('domain')
            ->get();
    }

    public function domain(CurrentActor $actor, ?string $domain = null)
    {
        $query = $this->visibleDomains($actor)->orderBy('domain');
        if ($domain) {
            $domain = IredMailAddress::domain($domain);
            if ($domain) {
                $query->where('domain', $domain);
            }
        }

        return $query->first();
    }

    public function aliasDomains(CurrentActor $actor, ?string $targetDomain = null)
    {
        $query = DB::connection('vmail')->table('alias_domain')->orderBy('target_domain')->orderBy('alias_domain');

        if ($targetDomain) {
            $targetDomain = IredMailAddress::domain($targetDomain) ?? abort(404);
            abort_unless($actor->canManageDomain($targetDomain), 403);
            $query->where('target_domain', $targetDomain);
        } elseif (! $actor->globalAdmin) {
            $query->whereIn('target_domain', $actor->domains ?: ['']);
        }

        return $query->get();
    }

    public function catchAllDestinations(CurrentActor $actor, string $domain)
    {
        $domain = IredMailAddress::domain($domain) ?? abort(404);
        abort_unless($actor->canManageDomain($domain), 403);

        return DB::connection('vmail')->table('forwardings')
            ->where('address', $domain)
            ->where('domain', $domain)
            ->orderBy('forwarding')
            ->get();
    }

    public function users(CurrentActor $actor, ?string $domain = null, ?string $search = null): LengthAwarePaginator
    {
        $query = $this->visibleUsers($actor);
        if ($domain) {
            $query->where('domain', strtolower($domain));
        }
        if ($search) {
            $query->where(function (Builder $query) use ($search) {
                $query->where('username', 'like', '%'.strtolower($search).'%')
                    ->orWhere('name', 'like', '%'.$search.'%');
            });
        }

        return $query->orderBy('username')->paginate(config('iredmail.page_size'));
    }

    public function userOptions(CurrentActor $actor, ?string $domain = null)
    {
        $query = $this->visibleUsers($actor)->select('username', 'domain');
        if ($domain) {
            $query->where('domain', strtolower($domain));
        }

        return $query->orderBy('username')->get();
    }

    public function user(CurrentActor $actor, ?string $email = null)
    {
        $query = $this->visibleUsers($actor)->orderBy('username');
        if ($email) {
            $email = IredMailAddress::email($email);
            if ($email) {
                $query->where('username', $email);
            }
        }

        return $query->first();
    }

    public function aliases(CurrentActor $actor, ?string $domain = null, ?string $search = null): LengthAwarePaginator
    {
        $query = $this->visibleAliases($actor);
        if ($domain) {
            $query->where('domain', strtolower($domain));
        }
        if ($search) {
            $query->where('address', 'like', '%'.strtolower($search).'%');
        }

        return $query->orderBy('address')->paginate(config('iredmail.page_size'));
    }

    public function aliasOptions(CurrentActor $actor, ?string $domain = null)
    {
        $query = $this->visibleAliases($actor)->select('alias.address', 'alias.domain');
        if ($domain) {
            $query->where('alias.domain', strtolower($domain));
        }

        return $query->orderBy('alias.address')->get();
    }

    public function alias(CurrentActor $actor, ?string $address = null)
    {
        $query = $this->visibleAliases($actor)->orderBy('alias.address');
        if ($address) {
            $address = IredMailAddress::email($address);
            if ($address) {
                $query->where('alias.address', $address);
            }
        }

        return $query->first();
    }

    public function lists(CurrentActor $actor, ?string $domain = null, ?string $search = null): LengthAwarePaginator
    {
        $query = $this->visibleLists($actor);
        if ($domain) {
            $query->where('domain', strtolower($domain));
        }
        if ($search) {
            $query->where('address', 'like', '%'.strtolower($search).'%');
        }

        return $query->orderBy('address')->paginate(config('iredmail.page_size'));
    }

    public function listOptions(CurrentActor $actor, ?string $domain = null)
    {
        $query = $this->visibleLists($actor)->select('maillists.address', 'maillists.domain');
        if ($domain) {
            $query->where('maillists.domain', strtolower($domain));
        }

        return $query->orderBy('maillists.address')->get();
    }

    public function list(CurrentActor $actor, ?string $address = null)
    {
        $query = $this->visibleLists($actor)->orderBy('maillists.address');
        if ($address) {
            $address = IredMailAddress::email($address);
            if ($address) {
                $query->where('maillists.address', $address);
            }
        }

        return $query->first();
    }

    public function admins(CurrentActor $actor): LengthAwarePaginator
    {
        abort_unless($actor->globalAdmin, 403);

        return DB::connection('vmail')->table('domain_admins')
            ->select('username')
            ->selectRaw('GROUP_CONCAT(domain ORDER BY domain SEPARATOR ", ") AS domains')
            ->groupBy('username')
            ->orderBy('username')
            ->paginate(config('iredmail.page_size'));
    }

    public function search(CurrentActor $actor, string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return ['domains' => collect(), 'users' => collect(), 'aliases' => collect(), 'lists' => collect()];
        }

        return [
            'domains' => $this->visibleDomains($actor)->where('domain', 'like', '%'.strtolower($term).'%')->limit(25)->get(),
            'users' => $this->visibleUsers($actor)->where(fn (Builder $query) => $query->where('username', 'like', '%'.strtolower($term).'%')->orWhere('name', 'like', '%'.$term.'%'))->limit(25)->get(),
            'aliases' => $this->visibleAliases($actor)->where('address', 'like', '%'.strtolower($term).'%')->limit(25)->get(),
            'lists' => $this->visibleLists($actor)->where('address', 'like', '%'.strtolower($term).'%')->limit(25)->get(),
        ];
    }

    public function createDomain(CurrentActor $actor, array $data): void
    {
        abort_unless($actor->globalAdmin, 403);
        $domain = IredMailAddress::domain($data['domain'] ?? '');
        if (! $domain) {
            throw ValidationException::withMessages(['domain' => 'Enter a valid domain name.']);
        }
        if (DB::connection('vmail')->table('domain')->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages(['domain' => 'Domain already exists.']);
        }

        $backupMx = ! empty($data['backupmx']);

        DB::connection('vmail')->table('domain')->insert([
            'domain' => $domain,
            'description' => $data['description'] ?? '',
            'transport' => $this->domainTransport($data, $backupMx),
            'aliases' => (int) ($data['aliases'] ?? 0),
            'mailboxes' => (int) ($data['mailboxes'] ?? 0),
            'maillists' => (int) ($data['maillists'] ?? 0),
            'maxquota' => (int) ($data['maxquota'] ?? 0),
            'quota' => (int) ($data['quota'] ?? 0),
            'backupmx' => (int) $backupMx,
            'created' => now()->toDateTimeString(),
            'modified' => now()->toDateTimeString(),
            'active' => 1,
        ]);

        $this->audit->log('create', "Created domain {$domain}.", $domain);
    }

    public function updateDomain(CurrentActor $actor, string $domain, array $data): void
    {
        $domain = IredMailAddress::domain($domain) ?? abort(404);
        abort_unless($actor->canManageDomain($domain), 403);
        $backupMx = ! empty($data['backupmx']);

        DB::connection('vmail')->table('domain')->where('domain', $domain)->update([
            'description' => $data['description'] ?? '',
            'transport' => $this->domainTransport($data, $backupMx),
            'aliases' => (int) ($data['aliases'] ?? 0),
            'mailboxes' => (int) ($data['mailboxes'] ?? 0),
            'maillists' => (int) ($data['maillists'] ?? 0),
            'maxquota' => (int) ($data['maxquota'] ?? 0),
            'quota' => (int) ($data['quota'] ?? 0),
            'backupmx' => (int) $backupMx,
            'active' => (int) ! empty($data['active']),
            'modified' => now()->toDateTimeString(),
        ]);

        $this->audit->log('update', "Updated domain {$domain}.", $domain);
    }

    public function createAliasDomain(CurrentActor $actor, string $targetDomain, array $data): void
    {
        $targetDomain = IredMailAddress::domain($targetDomain) ?? abort(404);
        abort_unless($actor->canManageDomain($targetDomain), 403);

        $aliasDomain = IredMailAddress::domain($data['alias_domain'] ?? '');
        if (! $aliasDomain) {
            throw ValidationException::withMessages(['alias_domain' => 'Enter a valid alias domain name.']);
        }

        if ($aliasDomain === $targetDomain) {
            throw ValidationException::withMessages(['alias_domain' => 'Alias domain must be different from the target domain.']);
        }

        if (DB::connection('vmail')->table('domain')->where('domain', $aliasDomain)->exists()) {
            throw ValidationException::withMessages(['alias_domain' => 'This domain already exists as a primary domain.']);
        }

        if (DB::connection('vmail')->table('alias_domain')->where('alias_domain', $aliasDomain)->exists()) {
            throw ValidationException::withMessages(['alias_domain' => 'Alias domain already exists.']);
        }

        DB::connection('vmail')->table('alias_domain')->insert([
            'alias_domain' => $aliasDomain,
            'target_domain' => $targetDomain,
        ]);

        $this->audit->log('create', "Added alias domain {$aliasDomain} -> {$targetDomain}.", $targetDomain);
    }

    public function deleteAliasDomain(CurrentActor $actor, string $aliasDomain): void
    {
        $aliasDomain = IredMailAddress::domain($aliasDomain) ?? abort(404);
        $row = DB::connection('vmail')->table('alias_domain')->where('alias_domain', $aliasDomain)->first();
        abort_unless($row, 404);
        abort_unless($actor->canManageDomain((string) $row->target_domain), 403);

        DB::connection('vmail')->table('alias_domain')->where('alias_domain', $aliasDomain)->delete();

        $this->audit->log('delete', "Removed alias domain {$aliasDomain} -> {$row->target_domain}.", (string) $row->target_domain);
    }

    public function createCatchAll(CurrentActor $actor, string $domain, array $data): void
    {
        $domain = IredMailAddress::domain($domain) ?? abort(404);
        abort_unless($actor->canManageDomain($domain), 403);

        $destination = IredMailAddress::email($data['forwarding'] ?? $data['destination'] ?? '');
        if (! $destination) {
            throw ValidationException::withMessages(['forwarding' => 'Enter a valid destination email address.']);
        }

        if (! DB::connection('vmail')->table('mailbox')->where('username', $destination)->exists()) {
            throw ValidationException::withMessages(['forwarding' => 'Catch-all destination must be an existing mailbox.']);
        }

        $destDomain = IredMailAddress::domainOf($destination);
        $exists = DB::connection('vmail')->table('forwardings')
            ->where('address', $domain)
            ->where('forwarding', $destination)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['forwarding' => 'This catch-all destination already exists.']);
        }

        DB::connection('vmail')->table('forwardings')->insert([
            'address' => $domain,
            'forwarding' => $destination,
            'domain' => $domain,
            'dest_domain' => $destDomain,
            'is_forwarding' => 1,
            'active' => 1,
        ]);

        $this->audit->log('create', "Added catch-all for {$domain} -> {$destination}.", $domain);
    }

    public function deleteCatchAll(CurrentActor $actor, string $domain, string $destination): void
    {
        $domain = IredMailAddress::domain($domain) ?? abort(404);
        abort_unless($actor->canManageDomain($domain), 403);

        $destination = IredMailAddress::email($destination) ?? abort(404);

        $deleted = DB::connection('vmail')->table('forwardings')
            ->where('address', $domain)
            ->where('forwarding', $destination)
            ->delete();
        abort_unless($deleted > 0, 404);

        $this->audit->log('delete', "Removed catch-all for {$domain} -> {$destination}.", $domain);
    }

    public function backupMxPrimaryIp(?object $domain): string
    {
        $transport = (string) ($domain->transport ?? '');
        if (preg_match('/^relay:\[([0-9a-f:.]+)\]:(\d+)$/i', $transport, $match)) {
            return $match[1];
        }

        return '';
    }

    private function domainTransport(array $data, bool $backupMx): string
    {
        if (! $backupMx) {
            $transport = trim((string) ($data['transport'] ?? config('iredmail.default_mta_transport')));

            return $transport !== '' ? $transport : (string) config('iredmail.default_mta_transport');
        }

        $ip = trim((string) ($data['backupmx_primary_ip'] ?? $data['primary_mx_ip'] ?? ''));
        if ($ip === '' && isset($data['transport']) && preg_match('/^relay:\[([0-9a-f:.]+)\]:(\d+)$/i', (string) $data['transport'], $match)) {
            $ip = $match[1];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw ValidationException::withMessages([
                'backupmx_primary_ip' => 'Enter the primary MX server IP address for Backup MX.',
            ]);
        }

        return "relay:[{$ip}]:25";
    }

    public function deleteDomain(CurrentActor $actor, string $domain, int $keepDays = 0): void
    {
        $domain = IredMailAddress::domain($domain) ?? abort(404);
        abort_unless($actor->globalAdmin, 403);

        $users = DB::connection('vmail')->table('mailbox')->where('domain', $domain)->get();

        DB::connection('vmail')->transaction(function () use ($domain) {
            DB::connection('vmail')->table('domain')->where('domain', $domain)->delete();
            DB::connection('vmail')->table('alias_domain')->where('target_domain', $domain)->orWhere('alias_domain', $domain)->delete();
            DB::connection('vmail')->table('mailbox')->where('domain', $domain)->delete();
            DB::connection('vmail')->table('alias')->where('domain', $domain)->delete();
            DB::connection('vmail')->table('maillists')->where('domain', $domain)->delete();
            DB::connection('vmail')->table('forwardings')->where('domain', $domain)->orWhere('dest_domain', $domain)->orWhere('address', $domain)->delete();
            DB::connection('vmail')->table('maillist_owners')->where('domain', $domain)->orWhere('dest_domain', $domain)->delete();
            DB::connection('vmail')->table('moderators')->where('domain', $domain)->orWhere('dest_domain', $domain)->delete();
            DB::connection('vmail')->table('domain_admins')->where('domain', $domain)->delete();
        });

        foreach ($users as $user) {
            $maildir = implode('/', array_filter([(string) ($user->storagebasedirectory ?? ''), (string) ($user->storagenode ?? ''), (string) ($user->maildir ?? '')]));
            $this->audit->deletedMailbox($user->username, $maildir, $domain, $keepDays);
        }

        $this->audit->log('delete', "Deleted domain {$domain} and related account records.", $domain);
    }

    public function createUser(CurrentActor $actor, array $data): void
    {
        $email = $this->newAccountEmail($data, 'username');
        if (! $email || ! $actor->canManageEmail($email)) {
            throw ValidationException::withMessages(['username' => 'Enter an email address in a managed domain.']);
        }
        if ($this->accountExists($email)) {
            throw ValidationException::withMessages(['username' => 'An account with this email address already exists.']);
        }

        $domain = IredMailAddress::domainOf($email);
        $local = strstr($email, '@', true);
        $maildir = sprintf('%s/%s/', $domain, $local);
        $password = $data['password'] ?? '';
        if (strlen($password) < 8) {
            throw ValidationException::withMessages(['password' => 'Password must be at least 8 characters.']);
        }

        DB::connection('vmail')->transaction(function () use ($data, $email, $domain, $maildir, $password) {
            DB::connection('vmail')->table('mailbox')->insert([
                'username' => $email,
                'password' => IredMailPassword::hash($password),
                'name' => $data['name'] ?? '',
                'language' => 'en_US',
                'domain' => $domain,
                'maildir' => $maildir,
                'quota' => (int) ($data['quota'] ?? 0),
                'storagebasedirectory' => config('iredmail.storage_base_directory'),
                'storagenode' => 'vmail1',
                'transport' => config('iredmail.default_mta_transport'),
                'created' => now()->toDateTimeString(),
                'modified' => now()->toDateTimeString(),
                'passwordlastchange' => now()->toDateTimeString(),
                'active' => 1,
            ]);

            DB::connection('vmail')->table('forwardings')->insert([
                'address' => $email,
                'forwarding' => $email,
                'domain' => $domain,
                'dest_domain' => $domain,
                'is_forwarding' => 1,
                'active' => 1,
            ]);
        });

        $this->audit->log('create', "Created user {$email}.", $domain, $email);
    }

    private function newAccountEmail(array $data, string $fullAddressField): ?string
    {
        $fullAddress = trim((string) ($data[$fullAddressField] ?? ''));
        if ($fullAddress !== '') {
            return IredMailAddress::email($fullAddress);
        }

        $local = strtolower(trim((string) ($data['local_part'] ?? '')));
        $domain = IredMailAddress::domain((string) ($data['domain'] ?? ''));
        if (! $domain || $local === '' || strlen($local) > 64 || ! preg_match('/^[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+$/', $local)) {
            return null;
        }

        return IredMailAddress::email($local.'@'.$domain);
    }

    public function updateUser(CurrentActor $actor, string $email, array $data): void
    {
        $email = IredMailAddress::email($email) ?? abort(404);
        abort_unless($actor->canManageEmail($email), 403);

        $updates = [
            'name' => $data['name'] ?? '',
            'quota' => (int) ($data['quota'] ?? 0),
            'active' => (int) ! empty($data['active']),
            'modified' => now()->toDateTimeString(),
        ];

        if (! empty($data['password'])) {
            if (strlen((string) $data['password']) < 8) {
                throw ValidationException::withMessages(['password' => 'Password must be at least 8 characters.']);
            }
            $updates['password'] = IredMailPassword::hash((string) $data['password']);
            $updates['passwordlastchange'] = now()->toDateTimeString();
        }

        DB::connection('vmail')->table('mailbox')->where('username', $email)->update($updates);
        $this->audit->log('update', "Updated user {$email}.", IredMailAddress::domainOf($email), $email);
    }

    public function createAlias(CurrentActor $actor, array $data): void
    {
        $address = $this->newAccountEmail($data, 'address');
        $members = $this->normalizeMembers($data['members'] ?? '');
        if (! $address || ! $actor->canManageEmail($address) || $members === []) {
            throw ValidationException::withMessages(['address' => 'Enter a valid alias and at least one member.']);
        }
        if ($this->accountExists($address)) {
            throw ValidationException::withMessages(['address' => 'An account with this address already exists.']);
        }

        $domain = IredMailAddress::domainOf($address);
        DB::connection('vmail')->transaction(function () use ($address, $domain, $members, $data) {
            DB::connection('vmail')->table('alias')->insert([
                'address' => $address,
                'name' => $data['name'] ?? '',
                'domain' => $domain,
                'accesspolicy' => $data['accesspolicy'] ?? 'public',
                'created' => now()->toDateTimeString(),
                'modified' => now()->toDateTimeString(),
                'active' => 1,
            ]);

            foreach ($members as $member) {
                DB::connection('vmail')->table('forwardings')->insert([
                    'address' => $address,
                    'forwarding' => $member,
                    'domain' => $domain,
                    'dest_domain' => IredMailAddress::domainOf($member),
                    'is_alias' => 1,
                    'active' => 1,
                ]);
            }
        });

        $this->audit->log('create', "Created alias {$address}.", $domain, $address);
    }

    public function updateAlias(CurrentActor $actor, string $address, array $data): void
    {
        $address = IredMailAddress::email($address) ?? abort(404);
        abort_unless($actor->canManageEmail($address), 403);
        $domain = IredMailAddress::domainOf($address);
        $members = $this->normalizeMembers($data['members'] ?? '');
        if ($members === []) {
            throw ValidationException::withMessages(['members' => 'Enter at least one alias member.']);
        }

        DB::connection('vmail')->transaction(function () use ($address, $domain, $members, $data) {
            DB::connection('vmail')->table('alias')->where('address', $address)->update([
                'name' => $data['name'] ?? '',
                'accesspolicy' => $data['accesspolicy'] ?? 'public',
                'active' => (int) ! empty($data['active']),
                'modified' => now()->toDateTimeString(),
            ]);

            DB::connection('vmail')->table('forwardings')->where('address', $address)->where('is_alias', 1)->delete();
            foreach ($members as $member) {
                DB::connection('vmail')->table('forwardings')->insert([
                    'address' => $address,
                    'forwarding' => $member,
                    'domain' => $domain,
                    'dest_domain' => IredMailAddress::domainOf($member),
                    'is_alias' => 1,
                    'active' => 1,
                ]);
            }
        });

        $this->audit->log('update', "Updated alias {$address}.", $domain, $address);
    }

    public function deleteAlias(CurrentActor $actor, string $address): void
    {
        $address = IredMailAddress::email($address) ?? abort(404);
        abort_unless($actor->canManageEmail($address), 403);

        DB::connection('vmail')->transaction(function () use ($address) {
            DB::connection('vmail')->table('alias')->where('address', $address)->delete();
            DB::connection('vmail')->table('forwardings')->where('address', $address)->delete();
            DB::connection('vmail')->table('moderators')->where('address', $address)->delete();
        });

        $this->audit->log('delete', "Deleted alias {$address}.", IredMailAddress::domainOf($address), $address);
    }

    public function createList(CurrentActor $actor, array $data): void
    {
        $address = $this->newAccountEmail($data, 'address');
        abort_unless($address && $actor->canManageEmail($address), 403);
        if ($this->accountExists($address)) {
            throw ValidationException::withMessages(['address' => 'An account with this address already exists.']);
        }
        $domain = IredMailAddress::domainOf($address);
        $owners = $this->normalizeMembers($data['owners'] ?? '');
        $members = $this->normalizeMembers($data['members'] ?? '');
        if ($owners === []) {
            throw ValidationException::withMessages(['owners' => 'Enter at least one list owner.']);
        }

        DB::connection('vmail')->transaction(function () use ($address, $domain, $owners, $members, $data) {
            DB::connection('vmail')->table('maillists')->insert([
                'address' => $address,
                'domain' => $domain,
                'transport' => 'mlmmj:'.$address,
                'accesspolicy' => $data['accesspolicy'] ?? 'public',
                'maxmsgsize' => (int) ($data['maxmsgsize'] ?? 0),
                'name' => $data['name'] ?? '',
                'description' => $data['description'] ?? '',
                'mlid' => (string) Str::uuid(),
                'created' => now()->toDateTimeString(),
                'modified' => now()->toDateTimeString(),
                'active' => 1,
            ]);

            DB::connection('vmail')->table('forwardings')->insert([
                'address' => $address,
                'forwarding' => $address,
                'domain' => $domain,
                'dest_domain' => $domain,
                'is_maillist' => 1,
                'active' => 1,
            ]);

            $this->replaceListPeople($address, $domain, $owners, $members);
        });

        $this->audit->log('create', "Created mailing list {$address}.", $domain, $address);
    }

    public function updateList(CurrentActor $actor, string $address, array $data): void
    {
        $address = IredMailAddress::email($address) ?? abort(404);
        abort_unless($actor->canManageEmail($address), 403);
        $domain = IredMailAddress::domainOf($address);
        $owners = $this->normalizeMembers($data['owners'] ?? '');
        $members = $this->normalizeMembers($data['members'] ?? '');
        if ($owners === []) {
            throw ValidationException::withMessages(['owners' => 'Enter at least one list owner.']);
        }

        DB::connection('vmail')->transaction(function () use ($address, $domain, $owners, $members, $data) {
            DB::connection('vmail')->table('maillists')->where('address', $address)->update([
                'accesspolicy' => $data['accesspolicy'] ?? 'public',
                'maxmsgsize' => (int) ($data['maxmsgsize'] ?? 0),
                'name' => $data['name'] ?? '',
                'description' => $data['description'] ?? '',
                'active' => (int) ! empty($data['active']),
                'modified' => now()->toDateTimeString(),
            ]);

            $this->replaceListPeople($address, $domain, $owners, $members);
        });

        $this->audit->log('update', "Updated mailing list {$address}.", $domain, $address);
    }

    public function deleteList(CurrentActor $actor, string $address): void
    {
        $address = IredMailAddress::email($address) ?? abort(404);
        abort_unless($actor->canManageEmail($address), 403);

        DB::connection('vmail')->transaction(function () use ($address) {
            DB::connection('vmail')->table('maillists')->where('address', $address)->delete();
            DB::connection('vmail')->table('forwardings')->where('address', $address)->delete();
            DB::connection('vmail')->table('maillist_owners')->where('address', $address)->delete();
            DB::connection('vmail')->table('moderators')->where('address', $address)->delete();
        });

        $this->audit->log('delete', "Deleted mailing list {$address}.", IredMailAddress::domainOf($address), $address);
    }

    public function updateUserServices(CurrentActor $actor, string $email, array $services): void
    {
        $email = IredMailAddress::email($email) ?? abort(404);
        abort_unless($actor->canManageEmail($email), 403);

        $columns = [];
        foreach (['smtp', 'smtpsecured', 'pop3', 'pop3secured', 'imap', 'imapsecured', 'managesieve', 'managesievesecured', 'sieve', 'sievesecured', 'deliver'] as $service) {
            $columns['enable'.$service] = in_array($service, $services, true) ? 1 : 0;
        }

        DB::connection('vmail')->table('mailbox')->where('username', $email)->update($columns);
        $this->audit->log('update', "Updated enabled services for {$email}.", IredMailAddress::domainOf($email), $email);
    }

    public function deleteUser(CurrentActor $actor, string $email, int $keepDays = 0): void
    {
        $email = IredMailAddress::email($email) ?? abort(404);
        abort_unless($actor->canManageEmail($email) && ! $actor->selfService, 403);

        $row = DB::connection('vmail')->table('mailbox')->where('username', $email)->first();
        abort_unless($row, 404);

        $domain = IredMailAddress::domainOf($email);
        $maildir = implode('/', array_filter([(string) ($row->storagebasedirectory ?? ''), (string) ($row->storagenode ?? ''), (string) ($row->maildir ?? '')]));

        DB::connection('vmail')->transaction(function () use ($email) {
            DB::connection('vmail')->table('mailbox')->where('username', $email)->delete();
            DB::connection('vmail')->table('forwardings')->where('address', $email)->orWhere('forwarding', $email)->delete();
            DB::connection('vmail')->table('domain_admins')->where('username', $email)->delete();
        });

        $this->audit->deletedMailbox($email, $maildir, $domain, $keepDays);
        $this->audit->log('delete', "Deleted user {$email}; mailbox path logged.", $domain, $email);
    }

    public function assignAdmin(CurrentActor $actor, array $data): void
    {
        abort_unless($actor->globalAdmin, 403);
        $email = IredMailAddress::email($data['username'] ?? '');
        $domain = strtoupper((string) ($data['domain'] ?? '')) === 'ALL' ? 'ALL' : IredMailAddress::domain($data['domain'] ?? '');
        if (! $email || ! $domain) {
            throw ValidationException::withMessages(['username' => 'Enter a valid admin email and domain.']);
        }

        $mailboxExists = DB::connection('vmail')->table('mailbox')->where('username', $email)->exists();
        $adminExists = DB::connection('vmail')->table('admin')->where('username', $email)->exists();
        if (! $mailboxExists && ! $adminExists) {
            $password = (string) ($data['password'] ?? '');
            if (strlen($password) < 8) {
                throw ValidationException::withMessages(['password' => 'Enter a password of at least 8 characters to create a separate admin account.']);
            }

            DB::connection('vmail')->table('admin')->insert([
                'username' => $email,
                'password' => IredMailPassword::hash($password),
                'name' => $data['name'] ?? '',
                'language' => 'en_US',
                'passwordlastchange' => now()->toDateTimeString(),
                'created' => now()->toDateTimeString(),
                'modified' => now()->toDateTimeString(),
                'active' => 1,
            ]);
            $adminExists = true;
        }

        $existing = DB::connection('vmail')->table('domain_admins')->where('username', $email)->where('domain', $domain)->exists();
        DB::connection('vmail')->table('domain_admins')->updateOrInsert(
            ['username' => $email, 'domain' => $domain],
            ['active' => 1, 'modified' => now()->toDateTimeString(), 'created' => $existing ? DB::raw('created') : now()->toDateTimeString(), 'expired' => '9999-12-31 00:00:00'],
        );

        if ($mailboxExists && $domain === 'ALL') {
            DB::connection('vmail')->table('mailbox')->where('username', $email)->update(['isglobaladmin' => 1, 'modified' => now()->toDateTimeString()]);
        } elseif ($mailboxExists) {
            DB::connection('vmail')->table('mailbox')->where('username', $email)->update(['isadmin' => 1, 'modified' => now()->toDateTimeString()]);
        }

        $this->audit->log('create', "Assigned {$email} as admin of {$domain}.", $domain === 'ALL' ? '' : $domain, $email);
    }

    public function deleteAdminAssignment(CurrentActor $actor, string $email, string $domain): void
    {
        abort_unless($actor->globalAdmin, 403);
        $email = IredMailAddress::email($email) ?? abort(404);
        $domain = strtoupper($domain) === 'ALL' ? 'ALL' : (IredMailAddress::domain($domain) ?? abort(404));

        DB::connection('vmail')->table('domain_admins')->where('username', $email)->where('domain', $domain)->delete();
        $remaining = DB::connection('vmail')->table('domain_admins')->where('username', $email)->pluck('domain')->all();
        DB::connection('vmail')->table('mailbox')->where('username', $email)->update([
            'isglobaladmin' => in_array('ALL', $remaining, true) ? 1 : 0,
            'isadmin' => count(array_diff($remaining, ['ALL'])) > 0 ? 1 : 0,
            'modified' => now()->toDateTimeString(),
        ]);

        $this->audit->log('delete', "Removed admin assignment {$email} -> {$domain}.", $domain === 'ALL' ? '' : $domain, $email);
    }

    public function exportAccounts(CurrentActor $actor): array
    {
        $rows = [['type', 'address', 'domain', 'name', 'active']];

        foreach ($this->visibleUsers($actor)->orderBy('username')->cursor() as $row) {
            $rows[] = ['user', $row->username, $row->domain, $row->name ?? '', $row->active ?? ''];
        }
        foreach ($this->visibleAliases($actor)->orderBy('address')->cursor() as $row) {
            $rows[] = ['alias', $row->address, $row->domain, '', $row->active ?? ''];
        }
        foreach ($this->visibleLists($actor)->orderBy('address')->cursor() as $row) {
            $rows[] = ['mailing-list', $row->address, $row->domain, $row->name ?? '', $row->active ?? ''];
        }

        return $rows;
    }

    public function exportAdminStats(CurrentActor $actor): array
    {
        abort_unless($actor->globalAdmin, 403);
        $rows = [['admin', 'domains', 'domain_count']];
        $stats = DB::connection('vmail')->table('domain_admins')
            ->select('username')
            ->selectRaw('GROUP_CONCAT(domain ORDER BY domain SEPARATOR ", ") AS domains')
            ->selectRaw('COUNT(domain) AS total')
            ->groupBy('username')
            ->orderBy('username')
            ->get();

        foreach ($stats as $row) {
            $rows[] = [$row->username, $row->domains, $row->total];
        }

        return $rows;
    }

    private function visibleDomains(CurrentActor $actor): Builder
    {
        $query = DB::connection('vmail')->table('domain');
        if (! $actor->globalAdmin) {
            $query->whereIn('domain', $actor->domains ?: ['']);
        }

        return $query;
    }

    private function visibleUsers(CurrentActor $actor): Builder
    {
        $query = DB::connection('vmail')->table('mailbox')
            ->select('mailbox.*')
            ->selectRaw('(SELECT GROUP_CONCAT(f.forwarding ORDER BY f.forwarding SEPARATOR ", ") FROM forwardings f WHERE f.address = mailbox.username AND f.is_forwarding = 1 AND f.forwarding <> mailbox.username) AS forwarding_destinations')
            ->selectRaw('(SELECT COUNT(*) FROM forwardings f WHERE f.address = mailbox.username AND f.is_forwarding = 1 AND f.forwarding = mailbox.username AND f.active = 1) AS keep_local_copy');
        if ($actor->selfService) {
            $query->where('username', $actor->email);
        } elseif (! $actor->globalAdmin) {
            $query->whereIn('domain', $actor->domains ?: ['']);
        }

        return $query;
    }

    private function visibleAliases(CurrentActor $actor): Builder
    {
        $query = DB::connection('vmail')->table('alias')
            ->select('alias.*')
            ->selectRaw('(SELECT GROUP_CONCAT(f.forwarding ORDER BY f.forwarding SEPARATOR ", ") FROM forwardings f WHERE f.address = alias.address AND f.is_alias = 1) AS members');
        if (! $actor->globalAdmin) {
            $query->whereIn('domain', $actor->domains ?: ['']);
        }

        return $query;
    }

    private function visibleLists(CurrentActor $actor): Builder
    {
        $query = DB::connection('vmail')->table('maillists')
            ->select('maillists.*')
            ->selectRaw('(SELECT GROUP_CONCAT(o.owner ORDER BY o.owner SEPARATOR ", ") FROM maillist_owners o WHERE o.address = maillists.address) AS owners')
            ->selectRaw('(SELECT GROUP_CONCAT(f.forwarding ORDER BY f.forwarding SEPARATOR ", ") FROM forwardings f WHERE f.address = maillists.address AND f.is_list = 1) AS members');
        if ($actor->selfService) {
            $query->whereIn('address', function (Builder $query) use ($actor) {
                $query->select('address')->from('maillist_owners')->where('owner', $actor->email);
            });
        } elseif (! $actor->globalAdmin) {
            $query->whereIn('domain', $actor->domains ?: ['']);
        }

        return $query;
    }

    private function normalizeMembers(string|array $members): array
    {
        $values = is_array($members) ? $members : preg_split('/[\s,;]+/', $members);
        return array_values(array_unique(array_filter(array_map(fn ($member) => IredMailAddress::email((string) $member), $values ?: []))));
    }

    public function updateUserForwarding(CurrentActor $actor, string $email, array $data): void
    {
        $email = IredMailAddress::email($email) ?? abort(404);
        abort_unless($actor->canManageEmail($email), 403);

        $mailbox = DB::connection('vmail')->table('mailbox')->where('username', $email)->first();
        abort_unless($mailbox, 404);

        $domain = IredMailAddress::domainOf($email);
        $destinations = $this->normalizeMembers($data['forwarding_destinations'] ?? '');
        $keepLocalCopy = ! empty($data['keep_local_copy']);

        if ($keepLocalCopy) {
            array_unshift($destinations, $email);
            $destinations = array_values(array_unique($destinations));
        }

        if ($destinations === []) {
            throw ValidationException::withMessages(['forwarding_destinations' => 'Keep local copy or enter at least one forwarding address.']);
        }

        DB::connection('vmail')->transaction(function () use ($email, $domain, $destinations) {
            DB::connection('vmail')->table('forwardings')->where('address', $email)->where('is_forwarding', 1)->delete();

            foreach ($destinations as $destination) {
                DB::connection('vmail')->table('forwardings')->insert([
                    'address' => $email,
                    'forwarding' => $destination,
                    'domain' => $domain,
                    'dest_domain' => IredMailAddress::domainOf($destination),
                    'is_forwarding' => 1,
                    'active' => 1,
                ]);
            }
        });

        $summary = implode(', ', array_filter($destinations, fn (string $destination) => $destination !== $email));
        $this->audit->log('update', "Updated forwarding for {$email}".($summary === '' ? ' with local delivery only.' : ": {$summary}."), $domain, $email);
    }

    private function accountExists(string $email): bool
    {
        foreach (['mailbox' => 'username', 'alias' => 'address', 'maillists' => 'address'] as $table => $column) {
            if (DB::connection('vmail')->table($table)->where($column, $email)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function replaceListPeople(string $address, string $domain, array $owners, array $members): void
    {
        DB::connection('vmail')->table('maillist_owners')->where('address', $address)->delete();
        DB::connection('vmail')->table('moderators')->where('address', $address)->delete();
        DB::connection('vmail')->table('forwardings')->where('address', $address)->where('is_list', 1)->delete();

        foreach ($owners as $owner) {
            DB::connection('vmail')->table('maillist_owners')->insert([
                'address' => $address,
                'owner' => $owner,
                'domain' => $domain,
                'dest_domain' => IredMailAddress::domainOf($owner),
            ]);
        }

        foreach ($members as $member) {
            DB::connection('vmail')->table('forwardings')->insert([
                'address' => $address,
                'forwarding' => $member,
                'domain' => $domain,
                'dest_domain' => IredMailAddress::domainOf($member),
                'is_list' => 1,
                'active' => 1,
            ]);
        }
    }
}
