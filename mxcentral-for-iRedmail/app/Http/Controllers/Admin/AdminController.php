<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\IredMail\AccountRepository;
use App\Services\IredMail\CurrentActor;
use App\Services\IredMail\DomainDkimService;
use App\Services\IredMail\DomainDnsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AdminController extends Controller
{
    public function dashboard(AccountRepository $accounts, CurrentActor $actor)
    {
        return view('admin.dashboard', ['stats' => $accounts->dashboard($actor)]);
    }

    public function domains(Request $request, AccountRepository $accounts, CurrentActor $actor, DomainDkimService $dkim, DomainDnsService $dns)
    {
        $selectedDomain = $accounts->domain($actor, $request->query('edit'));

        return view('admin.domains', [
            'rows' => $accounts->domains($actor, $request->query('q')),
            'domainOptions' => $accounts->domainOptions($actor),
            'selectedDomain' => $selectedDomain,
            'aliasDomains' => $selectedDomain ? $accounts->aliasDomains($actor, $selectedDomain->domain) : collect(),
            'catchAllDestinations' => $selectedDomain ? $accounts->catchAllDestinations($actor, $selectedDomain->domain) : collect(),
            'backupMxPrimaryIp' => $accounts->backupMxPrimaryIp($selectedDomain),
            'dkimStatus' => $selectedDomain ? $dkim->status($actor, $selectedDomain->domain) : null,
            'dnsStatus' => $selectedDomain ? $dns->status($actor, $selectedDomain->domain) : null,
        ]);
    }

    public function users(Request $request, AccountRepository $accounts, CurrentActor $actor)
    {
        return view('admin.users', [
            'rows' => $accounts->users($actor, $request->query('domain'), $request->query('q')),
            'domainOptions' => $accounts->domainOptions($actor),
            'userOptions' => $accounts->userOptions($actor, $request->query('domain')),
            'selectedUser' => $accounts->user($actor, $request->query('edit')),
        ]);
    }

    public function aliases(Request $request, AccountRepository $accounts, CurrentActor $actor)
    {
        return view('admin.aliases', [
            'rows' => $accounts->aliases($actor, $request->query('domain'), $request->query('q')),
            'domainOptions' => $accounts->domainOptions($actor),
            'aliasOptions' => $accounts->aliasOptions($actor, $request->query('domain')),
            'selectedAlias' => $accounts->alias($actor, $request->query('edit')),
        ]);
    }

    public function lists(Request $request, AccountRepository $accounts, CurrentActor $actor)
    {
        return view('admin.lists', [
            'rows' => $accounts->lists($actor, $request->query('domain'), $request->query('q')),
            'domainOptions' => $accounts->domainOptions($actor),
            'listOptions' => $accounts->listOptions($actor, $request->query('domain')),
            'selectedList' => $accounts->list($actor, $request->query('edit')),
        ]);
    }

    public function admins(AccountRepository $accounts, CurrentActor $actor)
    {
        return view('admin.admins', [
            'rows' => $accounts->admins($actor),
            'domainOptions' => $accounts->domainOptions($actor),
        ]);
    }

    public function search(Request $request, AccountRepository $accounts, CurrentActor $actor)
    {
        return view('admin.search', ['results' => $accounts->search($actor, (string) $request->query('q')), 'term' => $request->query('q')]);
    }

    public function createDomain(Request $request, AccountRepository $accounts, CurrentActor $actor)
    {
        $accounts->createDomain($actor, $request->all());

        return back()->with('status', 'Domain created.');
    }

    public function updateDomain(Request $request, AccountRepository $accounts, CurrentActor $actor, string $domain)
    {
        $accounts->updateDomain($actor, $domain, $request->all());

        return back()->with('status', 'Domain updated.');
    }

    public function createAliasDomain(Request $request, AccountRepository $accounts, CurrentActor $actor, string $domain)
    {
        $accounts->createAliasDomain($actor, $domain, $request->all());

        return back()->with('status', 'Alias domain added.');
    }

    public function deleteAliasDomain(AccountRepository $accounts, CurrentActor $actor, string $aliasDomain)
    {
        $accounts->deleteAliasDomain($actor, $aliasDomain);

        return back()->with('status', 'Alias domain removed.');
    }

    public function createCatchAll(Request $request, AccountRepository $accounts, CurrentActor $actor, string $domain)
    {
        $accounts->createCatchAll($actor, $domain, $request->all());

        return back()->with('status', 'Catch-all destination added.');
    }

    public function deleteCatchAll(AccountRepository $accounts, CurrentActor $actor, string $domain, string $destination)
    {
        $accounts->deleteCatchAll($actor, $domain, $destination);

        return back()->with('status', 'Catch-all destination removed.');
    }

    public function deleteDomain(Request $request, AccountRepository $accounts, DomainDkimService $dkim, CurrentActor $actor, string $domain)
    {
        $dkimCleanup = $dkim->cleanupRemovedDomain($actor, $domain);
        $accounts->deleteDomain($actor, $domain, (int) $request->input('keep_days', 0));

        $message = 'Domain deleted; mailbox paths logged.';
        if (($dkimCleanup['config']['changed'] ?? false) || ($dkimCleanup['keys']['deleted'] ?? []) !== []) {
            $message .= ' DKIM signing config/key files cleaned up and amavisd restarted.';
        }

        return back()->with('status', $message);
    }

    public function generateDomainDkim(Request $request, DomainDkimService $dkim, CurrentActor $actor, string $domain)
    {
        $result = $dkim->generate($actor, $domain, (int) $request->input('bits', 1024));
        $verb = ($result['rotated'] ?? false) ? 'rotated' : 'ready';
        $message = "DKIM key is {$verb} for {$result['domain']} using {$result['bits']} bits. Publish the TXT record shown below.";
        if (! ($result['restart']['configured'] ?? false)) {
            $message .= ' Amavisd restart is not configured; restart amavis manually before relying on the new signature.';
        } elseif (! ($result['restart']['ok'] ?? false)) {
            $message .= ' Amavisd restart failed; check the DKIM panel for details.';
        } else {
            $message .= ' Amavisd restarted.';
        }

        return back()->with('status', $message);
    }

    public function checkDomainDkim(DomainDkimService $dkim, CurrentActor $actor, string $domain)
    {
        $dns = $dkim->checkDns($actor, $domain);
        $message = $dns['match']
            ? "DKIM DNS is correct for {$dns['name']}."
            : "DKIM DNS does not match yet for {$dns['name']}.";

        return back()->with('status', $message);
    }

    public function checkDomainDns(DomainDnsService $dns, CurrentActor $actor, string $domain)
    {
        return back()->with('status', $dns->summary($actor, $domain));
    }

    public function createUser(Request $request, AccountRepository $accounts, CurrentActor $actor)
    {
        $accounts->createUser($actor, $request->all());

        return back()->with('status', 'User created.');
    }

    public function updateUser(Request $request, AccountRepository $accounts, CurrentActor $actor, string $email)
    {
        $accounts->updateUser($actor, $email, $request->all());

        return back()->with('status', 'User updated.');
    }

    public function updateUserForwarding(Request $request, AccountRepository $accounts, CurrentActor $actor, string $email)
    {
        $accounts->updateUserForwarding($actor, $email, $request->all());

        return back()->with('status', 'Forwarding updated.');
    }

    public function createAlias(Request $request, AccountRepository $accounts, CurrentActor $actor)
    {
        $accounts->createAlias($actor, $request->all());

        return back()->with('status', 'Alias created.');
    }

    public function updateAlias(Request $request, AccountRepository $accounts, CurrentActor $actor, string $address)
    {
        $accounts->updateAlias($actor, $address, $request->all());

        return back()->with('status', 'Alias updated.');
    }

    public function deleteAlias(AccountRepository $accounts, CurrentActor $actor, string $address)
    {
        $accounts->deleteAlias($actor, $address);

        return back()->with('status', 'Alias deleted.');
    }

    public function createList(Request $request, AccountRepository $accounts, CurrentActor $actor)
    {
        $accounts->createList($actor, $request->all());

        return back()->with('status', 'Mailing list created.');
    }

    public function updateList(Request $request, AccountRepository $accounts, CurrentActor $actor, string $address)
    {
        $accounts->updateList($actor, $address, $request->all());

        return back()->with('status', 'Mailing list updated.');
    }

    public function deleteList(AccountRepository $accounts, CurrentActor $actor, string $address)
    {
        $accounts->deleteList($actor, $address);

        return back()->with('status', 'Mailing list deleted.');
    }

    public function assignAdmin(Request $request, AccountRepository $accounts, CurrentActor $actor)
    {
        $accounts->assignAdmin($actor, $request->all());

        return back()->with('status', 'Admin assignment saved.');
    }

    public function deleteAdminAssignment(AccountRepository $accounts, CurrentActor $actor, string $email, string $domain)
    {
        $accounts->deleteAdminAssignment($actor, $email, $domain);

        return back()->with('status', 'Admin assignment removed.');
    }

    public function updateServices(Request $request, AccountRepository $accounts, CurrentActor $actor, string $email)
    {
        $accounts->updateUserServices($actor, $email, $request->input('services', []));

        return back()->with('status', 'Services updated.');
    }

    public function deleteUser(Request $request, AccountRepository $accounts, CurrentActor $actor, string $email)
    {
        $accounts->deleteUser($actor, $email, (int) $request->input('keep_days', 0));

        return back()->with('status', 'User deleted; mailbox path logged.');
    }

    public function exportAccounts(AccountRepository $accounts, CurrentActor $actor): Response
    {
        return $this->csv('managed-accounts.csv', $accounts->exportAccounts($actor));
    }

    public function exportAdminStats(AccountRepository $accounts, CurrentActor $actor): Response
    {
        return $this->csv('admin-statistics.csv', $accounts->exportAdminStats($actor));
    }

    private function csv(string $filename, array $rows): Response
    {
        $content = '';
        foreach ($rows as $row) {
            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, $row);
            rewind($handle);
            $content .= stream_get_contents($handle);
            fclose($handle);
        }

        return response($content, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
