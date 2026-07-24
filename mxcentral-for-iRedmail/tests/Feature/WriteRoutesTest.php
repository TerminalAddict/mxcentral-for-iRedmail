<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WriteRoutesTest extends TestCase
{
    public function test_account_write_routes_are_registered(): void
    {
        foreach ([
            'domains.create',
            'domains.update',
            'domains.alias-domains.create',
            'domains.alias-domains.delete',
            'domains.catch-all.create',
            'domains.catch-all.delete',
            'domains.dkim.generate',
            'domains.dkim.check',
            'domains.delete',
            'users.create',
            'users.update',
            'users.forwarding',
            'users.delete',
            'aliases.create',
            'aliases.update',
            'aliases.delete',
            'lists.create',
            'lists.update',
            'lists.delete',
            'admins.assign',
            'admins.delete',
            'system.settings.update',
            'system.settings.unauthenticated.update',
            'system.settings.discard.update',
            'system.settings.sogo.update',
            'system.settings.decryptable-passwords.update',
            'quarantine',
            'quarantine.delete',
            'quarantine.release',
            'quarantine.raw',
        ] as $route) {
            $this->assertTrue(Route::has($route), "{$route} route is missing.");
        }
    }

    public function test_setup_api_route_is_admin_only(): void
    {
        $this->assertFalse(Route::has('setup'));

        $apiSetup = collect(Route::getRoutes())->first(fn ($route) => in_array('GET', $route->methods(), true) && $route->uri() === 'api/setup');

        $this->assertNotNull($apiSetup, 'api/setup route is missing.');
        $this->assertContains('iredmail.auth:admin', $apiSetup->gatherMiddleware());
    }
}
