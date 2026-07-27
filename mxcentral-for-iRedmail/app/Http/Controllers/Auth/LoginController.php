<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\IredMail\AuthService;
use App\Services\IredMail\LoginRateLimiter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, AuthService $auth, LoginRateLimiter $limiter): RedirectResponse
    {
        $account = $limiter->normalizedAccount((string) $request->input('email'));
        $ip = (string) $request->ip();
        $retryAfter = $limiter->retryAfter($account, $ip);
        if ($retryAfter > 0) {
            return $this->throttled($retryAfter);
        }

        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'mode' => ['required', 'in:admin,user'],
        ]);

        $identity = $auth->attemptIdentity($data['email'], $data['password'], $data['mode']);
        if (! $identity) {
            $retryAfter = $limiter->recordFailure($account, $ip);

            return back()
                ->withErrors(['email' => 'Invalid credentials or inactive account. Please wait before trying again.'])
                ->onlyInput('email', 'mode')
                ->withHeaders(['Retry-After' => (string) $retryAfter]);
        }

        $limiter->clearAccount($account);
        $request->session()->regenerate();
        session(['auth_identity' => [
            'email' => $identity['actor']->email,
            'source' => $identity['source'],
            'version' => $identity['version'],
            'reauthenticated_at' => now()->timestamp,
        ]]);

        return redirect()->route($identity['actor']->selfService ? 'preferences' : 'dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function throttled(int $retryAfter): RedirectResponse
    {
        return back()
            ->withErrors(['email' => "Too many login attempts. Try again in {$retryAfter} seconds."])
            ->onlyInput('email', 'mode')
            ->withHeaders(['Retry-After' => (string) $retryAfter]);
    }
}
