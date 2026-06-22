<?php

namespace App\Http\Middleware;

use App\Services\IredMail\CurrentActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class IredMailAuthenticated
{
    public function handle(Request $request, Closure $next, string $scope = 'any'): Response
    {
        $actor = CurrentActor::fromSession();
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

        return $next($request);
    }
}
