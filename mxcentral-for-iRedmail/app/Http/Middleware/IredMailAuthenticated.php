<?php

namespace App\Http\Middleware;

use App\Services\IredMail\AuthService;
use App\Services\IredMail\CurrentActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class IredMailAuthenticated
{
    public function handle(Request $request, Closure $next, string $scope = 'any'): Response
    {
        $actor = $request->attributes->get('mxcentral.current_actor');
        if (! $actor instanceof CurrentActor) {
            $actor = $this->revalidatedActor($request);
        }
        if (! $actor) {
            if ($request->expectsJson()) {
                abort(401, 'Authentication required.');
            }

            return redirect()->route('login');
        }

        if ($scope === 'admin' && $actor->selfService) {
            abort(403);
        }

        if ($scope === 'global' && ! $actor->globalAdmin) {
            abort(403);
        }

        app()->instance(CurrentActor::class, $actor);
        $request->attributes->set('mxcentral.current_actor', $actor);
        view()->share('currentActor', $actor);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    private function revalidatedActor(Request $request): ?CurrentActor
    {
        $identity = $request->session()->get('auth_identity');
        if (! is_array($identity)
            || ! is_string($identity['email'] ?? null)
            || ! is_string($identity['source'] ?? null)
            || ! is_string($identity['version'] ?? null)) {
            return null;
        }

        $current = app(AuthService::class)->refreshIdentity($identity['email'], $identity['source']);
        if (! $current || ! hash_equals($identity['version'], $current['version'])) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }

        return $current['actor'];
    }
}
