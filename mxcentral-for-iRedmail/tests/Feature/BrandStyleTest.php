<?php

namespace Tests\Feature;

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
        $this->withSession([
            'actor' => [
                'email' => 'postmaster@example.test',
                'type' => 'mailbox-admin',
                'global_admin' => true,
                'domain_admin' => false,
                'self_service' => false,
                'domains' => [],
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
