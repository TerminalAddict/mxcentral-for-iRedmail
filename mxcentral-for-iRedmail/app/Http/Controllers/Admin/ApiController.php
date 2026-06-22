<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\IredMail\AccountRepository;
use App\Services\IredMail\CurrentActor;
use App\Services\IredMail\MailActivityRepository;
use App\Services\IredMail\PolicyRepository;
use App\Services\IredMail\SetupInspector;
use Illuminate\Http\Request;

final class ApiController extends Controller
{
    public function dashboard(AccountRepository $accounts, CurrentActor $actor): array
    {
        return ['data' => $accounts->dashboard($actor)];
    }

    public function domains(Request $request, AccountRepository $accounts, CurrentActor $actor): array
    {
        return ['data' => $accounts->domains($actor, $request->query('q'))];
    }

    public function createDomain(Request $request, AccountRepository $accounts, CurrentActor $actor): array
    {
        $accounts->createDomain($actor, $request->all());
        return ['ok' => true];
    }

    public function updateDomain(Request $request, AccountRepository $accounts, CurrentActor $actor, string $domain): array
    {
        $accounts->updateDomain($actor, $domain, $request->all());
        return ['ok' => true];
    }

    public function deleteDomain(Request $request, AccountRepository $accounts, CurrentActor $actor, string $domain): array
    {
        $accounts->deleteDomain($actor, $domain, (int) $request->input('keep_days', 0));
        return ['ok' => true];
    }

    public function users(Request $request, AccountRepository $accounts, CurrentActor $actor): array
    {
        return ['data' => $accounts->users($actor, $request->query('domain'), $request->query('q'))];
    }

    public function createUser(Request $request, AccountRepository $accounts, CurrentActor $actor): array
    {
        $accounts->createUser($actor, $request->all());
        return ['ok' => true];
    }

    public function updateUser(Request $request, AccountRepository $accounts, CurrentActor $actor, string $email): array
    {
        $accounts->updateUser($actor, $email, $request->all());
        return ['ok' => true];
    }

    public function deleteUser(Request $request, AccountRepository $accounts, CurrentActor $actor, string $email): array
    {
        $accounts->deleteUser($actor, $email, (int) $request->input('keep_days', 0));
        return ['ok' => true];
    }

    public function aliases(Request $request, AccountRepository $accounts, CurrentActor $actor): array
    {
        return ['data' => $accounts->aliases($actor, $request->query('domain'), $request->query('q'))];
    }

    public function createAlias(Request $request, AccountRepository $accounts, CurrentActor $actor): array
    {
        $accounts->createAlias($actor, $request->all());
        return ['ok' => true];
    }

    public function updateAlias(Request $request, AccountRepository $accounts, CurrentActor $actor, string $address): array
    {
        $accounts->updateAlias($actor, $address, $request->all());
        return ['ok' => true];
    }

    public function deleteAlias(AccountRepository $accounts, CurrentActor $actor, string $address): array
    {
        $accounts->deleteAlias($actor, $address);
        return ['ok' => true];
    }

    public function lists(Request $request, AccountRepository $accounts, CurrentActor $actor): array
    {
        return ['data' => $accounts->lists($actor, $request->query('domain'), $request->query('q'))];
    }

    public function createList(Request $request, AccountRepository $accounts, CurrentActor $actor): array
    {
        $accounts->createList($actor, $request->all());
        return ['ok' => true];
    }

    public function updateList(Request $request, AccountRepository $accounts, CurrentActor $actor, string $address): array
    {
        $accounts->updateList($actor, $address, $request->all());
        return ['ok' => true];
    }

    public function deleteList(AccountRepository $accounts, CurrentActor $actor, string $address): array
    {
        $accounts->deleteList($actor, $address);
        return ['ok' => true];
    }

    public function assignAdmin(Request $request, AccountRepository $accounts, CurrentActor $actor): array
    {
        $accounts->assignAdmin($actor, $request->all());
        return ['ok' => true];
    }

    public function deleteAdminAssignment(AccountRepository $accounts, CurrentActor $actor, string $email, string $domain): array
    {
        $accounts->deleteAdminAssignment($actor, $email, $domain);
        return ['ok' => true];
    }

    public function mail(Request $request, MailActivityRepository $mail, CurrentActor $actor, string $direction): array
    {
        abort_unless(in_array($direction, ['sent', 'received'], true), 404);

        return ['data' => $mail->logs($actor, $direction, $request->query('account'))];
    }

    public function quarantine(Request $request, MailActivityRepository $mail, CurrentActor $actor, ?string $type = null): array
    {
        return ['data' => $mail->quarantined($actor, $type, $request->query('account'))];
    }

    public function throttle(Request $request, PolicyRepository $policy, CurrentActor $actor): array
    {
        return ['data' => $policy->throttle($actor, $request->query('account'))];
    }

    public function setup(SetupInspector $setup): array
    {
        return ['data' => $setup->report()];
    }
}
