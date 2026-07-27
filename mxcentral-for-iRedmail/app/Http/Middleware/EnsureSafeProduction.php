<?php

namespace App\Http\Middleware;

use App\Services\IredMail\DeploymentHealthCheck;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSafeProduction
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production')) {
            static $errors = [];
            static $checkedAt = 0;
            if ($checkedAt + 60 <= time()) {
                $errors = app(DeploymentHealthCheck::class)->errors();
                $checkedAt = time();
            }
            if ($errors !== []) {
                report(new \RuntimeException('MXCentral production health check failed: '.implode(' ', $errors)));
                abort(503, 'MXCentral is unavailable because its production health check failed.');
            }
        }

        return $next($request);
    }
}
