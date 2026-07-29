<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\IredMail\CurrentActor;
use App\Services\IredMail\IredMailUpgradeCheckService;
use App\Services\IredMail\SetupInspector;
use App\Services\IredMail\SystemSettingsService;
use Illuminate\Http\Request;

final class SystemSettingsController extends Controller
{
    public function edit(SystemSettingsService $settings, SetupInspector $setup, IredMailUpgradeCheckService $upgrades, CurrentActor $actor)
    {
        return view('admin.system_settings', [
            'settings' => $settings->settings($actor),
            'setupChecks' => $setup->report(),
            'upgradeStatus' => $upgrades->status(),
        ]);
    }

    public function update(Request $request, SystemSettingsService $settings, CurrentActor $actor)
    {
        $result = $settings->saveAllowedLoginMismatchSenders($actor, $request->input('allowed_login_mismatch_senders', []));

        if ($result['postfix']['changed'] && ! $result['postfix']['reload']['configured']) {
            return back()->with('status', 'Settings saved. Postfix main.cf was updated, but Postfix reload is not configured, so reload Postfix manually.');
        }

        if ($result['postfix']['changed'] && ! $result['postfix']['reload']['ok']) {
            return back()->withErrors(['postfix' => 'Settings saved, but Postfix reload failed: '.$result['postfix']['reload']['message']]);
        }

        if (! $result['restart']['configured']) {
            return back()->with('status', 'Settings saved. Restart command is not configured, so restart iredapd manually.');
        }

        if (! $result['restart']['ok']) {
            return back()->withErrors(['restart' => 'Settings saved, but iredapd restart failed: '.$result['restart']['message']]);
        }

        return back()->with('status', 'Settings saved and iredapd restarted.');
    }

    public function updateDiscardRecipients(Request $request, SystemSettingsService $settings, CurrentActor $actor)
    {
        $result = $settings->saveDiscardRecipients($actor, $request->input('discard_recipients', []));

        if (! $result['postmap']['configured']) {
            return back()->withErrors(['postmap' => 'Discard recipients were saved, but MXCentral has no postmap command configured and could not activate the map.']);
        }

        if (! $result['postmap']['ok']) {
            return back()->withErrors(['postmap' => 'Discard recipients saved, but postmap failed: '.$result['postmap']['message']]);
        }

        if (! $result['reload']['configured']) {
            return back()->withErrors(['reload' => 'Discard recipients and the Postfix map were updated, but MXCentral has no Postfix reload command configured.']);
        }

        if (! $result['reload']['ok']) {
            return back()->withErrors(['reload' => 'Discard recipients saved and postmap completed, but Postfix reload failed: '.$result['reload']['message']]);
        }

        $hook = $result['postfix_hook']['changed'] ? ' Postfix recipient access was installed in main.cf.' : '';

        return back()->with('status', 'Discard recipients saved, postmap completed, and Postfix reloaded.'.$hook);
    }

    public function updateUnauthenticatedSenders(Request $request, SystemSettingsService $settings, CurrentActor $actor)
    {
        $result = $settings->saveUnauthenticatedSenders(
            $actor,
            $request->input('allowed_forged_senders', []),
            $request->input('allowed_unauthenticated_networks', '')
        );

        $postfixChanged = $result['sender_access']['changed'] || $result['postfix_hook']['changed'];

        if ($postfixChanged && ! $result['reload']['configured']) {
            return back()->with('status', 'Unauthenticated sender settings saved. Postfix sender access was updated, but Postfix reload is not configured, so reload Postfix manually.');
        }

        if ($postfixChanged && ! $result['reload']['ok']) {
            return back()->withErrors(['unauthenticated_senders' => 'Unauthenticated sender settings saved, but Postfix reload failed: '.$result['reload']['message']]);
        }

        if (! $result['restart']['configured']) {
            return back()->with('status', 'Unauthenticated sender settings saved. iRedAPD restart command is not configured, so restart iRedAPD manually.');
        }

        if (! $result['restart']['ok']) {
            return back()->withErrors(['unauthenticated_senders' => 'Unauthenticated sender settings saved, but iRedAPD restart failed: '.$result['restart']['message']]);
        }

        return back()->with('status', 'Unauthenticated sender settings saved, Postfix reloaded, and iRedAPD restarted.');
    }

    public function updateSogoLogo(Request $request, SystemSettingsService $settings, CurrentActor $actor)
    {
        $result = $settings->saveSogoLogo(
            $actor,
            (string) $request->input('sogo_logo_url', ''),
            (string) $request->input('sogo_login_background_color', ''),
            (string) $request->input('sogo_login_foreground_color', ''),
            $request->boolean('sogo_use_original_logo'),
            $request->boolean('sogo_use_original_colors'),
        );

        if (! $result['reload']['configured']) {
            return back()->with('status', 'SOGo branding saved. Reload command is not configured, so reload SOGo manually if needed.');
        }

        if (! $result['reload']['ok']) {
            return back()->withErrors(['sogo_logo_url' => 'SOGo branding saved, but reload failed: '.$result['reload']['message']]);
        }

        return back()->with('status', 'SOGo branding saved and SOGo reloaded.');
    }

    public function updateDecryptablePasswords(Request $request, SystemSettingsService $settings, CurrentActor $actor)
    {
        $result = $settings->saveDecryptablePasswords($actor, $request->boolean('enabled'));

        if (! $result['changed']) {
            return back()->with('status', 'Decryptable password storage was already '.($result['enabled'] ? 'enabled.' : 'disabled.'));
        }

        return back()->with('status', $result['enabled']
            ? 'Decryptable password storage enabled. Only new or changed passwords can be stored from now on.'
            : 'Decryptable password storage disabled. Stored decryptable passwords were removed.');
    }
}
