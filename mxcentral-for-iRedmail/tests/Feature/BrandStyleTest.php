<?php

namespace Tests\Feature;

use App\Services\IredMail\AuthService;
use App\Services\IredMail\CurrentActor;
use Tests\Fakes\FakeAuthService;
use Tests\TestCase;

final class BrandStyleTest extends TestCase
{
    public function test_login_uses_mxcentral_brand_shell(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('MXCentral Mail Admin')
            ->assertSee('login-shell')
            ->assertSee('brand__mark')
            ->assertSee('Sign in with a local MXCentral account.');
    }

    public function test_authenticated_layout_includes_mobile_bottom_nav(): void
    {
        $actor = new CurrentActor(
            email: 'postmaster@example.test',
            type: 'mailbox-admin',
            globalAdmin: true,
            domainAdmin: false,
            selfService: false,
        );
        $this->app->instance(AuthService::class, new FakeAuthService($actor));

        $this->withSession([
            'auth_identity' => [
                'email' => 'postmaster@example.test',
                'source' => 'mailbox-admin',
                'version' => 'test-security-version',
            ],
        ])->get('/system/settings')
            ->assertOk()
            ->assertSee('app-topbar')
            ->assertSee('app-brand__mark')
            ->assertSee('bottom-nav')
            ->assertSee('bottom-nav__icon')
            ->assertSee('Server Setup');
    }
}
