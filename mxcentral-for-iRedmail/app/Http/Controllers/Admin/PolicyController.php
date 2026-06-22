<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\IredMail\CurrentActor;
use App\Services\IredMail\PolicyRepository;
use Illuminate\Http\Request;

final class PolicyController extends Controller
{
    public function throttle(Request $request, PolicyRepository $policy, CurrentActor $actor)
    {
        return view('admin.throttle', [
            'rows' => $policy->throttle($actor, $request->query('account')),
            'currentAccount' => $request->query('account'),
        ]);
    }

    public function saveThrottle(Request $request, PolicyRepository $policy, CurrentActor $actor)
    {
        $policy->saveThrottle($actor, $request->all());
        return back()->with('status', 'Throttle setting saved.');
    }

    public function wblist(Request $request, PolicyRepository $policy, CurrentActor $actor)
    {
        return view('admin.wblist', ['rows' => $policy->wblist($actor, $request->query('account'))]);
    }

    public function addWblist(Request $request, PolicyRepository $policy, CurrentActor $actor)
    {
        $policy->addWblist($actor, $request->all());
        return back()->with('status', 'White/blacklist entry saved.');
    }

    public function fail2ban(PolicyRepository $policy, CurrentActor $actor)
    {
        return view('admin.fail2ban', ['rows' => $policy->fail2ban($actor)]);
    }

    public function unban(Request $request, PolicyRepository $policy, CurrentActor $actor, string $ip)
    {
        $result = $policy->unban($actor, $ip);
        if (($result['configured'] ?? false) && ! ($result['ok'] ?? false)) {
            return back()->withErrors(['fail2ban' => "{$ip} marked for unban, but fail2ban-client failed: ".$result['message']]);
        }

        return back()->with('status', ($result['configured'] ?? false)
            ? "{$ip} unban command completed and database row marked for removal."
            : "{$ip} marked for unban.");
    }
}
