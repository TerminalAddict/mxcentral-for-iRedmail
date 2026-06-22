<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\IredMail\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, AuthService $auth): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'mode' => ['required', 'in:admin,user'],
        ]);

        $actor = $auth->attempt($data['email'], $data['password'], $data['mode']);
        if (! $actor) {
            return back()->withErrors(['email' => 'Invalid credentials or inactive account.'])->onlyInput('email', 'mode');
        }

        $request->session()->regenerate();
        session(['actor' => [
            'email' => $actor->email,
            'type' => $actor->type,
            'global_admin' => $actor->globalAdmin,
            'domain_admin' => $actor->domainAdmin,
            'self_service' => $actor->selfService,
            'domains' => $actor->domains,
        ]]);

        return redirect()->route($actor->selfService ? 'preferences' : 'dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
