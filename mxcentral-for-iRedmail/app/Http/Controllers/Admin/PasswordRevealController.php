<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\IredMail\CurrentActor;
use App\Services\IredMail\PasswordRevealAccess;
use App\Services\IredMail\PasswordRevealService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PasswordRevealController extends Controller
{
    public function request(
        Request $request,
        PasswordRevealService $passwords,
        PasswordRevealAccess $passwordRevealAccess,
        CurrentActor $actor,
        string $email,
    ): RedirectResponse {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'totp_code' => [$passwordRevealAccess->requiresTotp() ? 'required' : 'nullable', 'string', 'size:6'],
            'purpose' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $source = (string) $request->session()->get('auth_identity.source');
        $token = $passwords->request(
            $actor,
            $source,
            $email,
            $data['current_password'],
            (string) ($data['totp_code'] ?? ''),
            $data['purpose'],
        );

        return redirect()->route('users.password.consume', ['token' => $token]);
    }

    public function consume(PasswordRevealService $passwords, CurrentActor $actor, string $token): View
    {
        return view('admin.password_reveal', [
            'reveal' => $passwords->consume($actor, $token),
        ]);
    }
}
