<?php

namespace Tests\Feature;

use App\Services\IredMail\AuthService;
use App\Services\IredMail\CurrentActor;
use Illuminate\Support\Facades\Route;
use Tests\Fakes\FakeAuthService;
use Tests\TestCase;

final class SecurityHardeningTest extends TestCase
{
    public function test_sudoers_grants_only_the_fixed_privileged_helper(): void
    {
        $sudoers = (string) file_get_contents(base_path('docs/sudoers.conf'));

        $this->assertStringContainsString(
            'www-data ALL=(root) NOPASSWD: MXC_PRIVILEGED_HELPER',
            $sudoers,
        );
        $this->assertStringContainsString(
            'MXC_PRIVILEGED_HELPER = /usr/local/sbin/mxcentral-privileged',
            $sudoers,
        );
        $this->assertStringNotContainsString('/usr/bin/chown', $sudoers);
        $this->assertStringNotContainsString('/usr/bin/chmod', $sudoers);
        $this->assertStringNotContainsString('/usr/sbin/amavisd', $sudoers);
        $this->assertStringNotContainsString('/usr/bin/setfacl', $sudoers);
        $this->assertStringNotContainsString('*.pem', $sudoers);
    }

    public function test_privileged_helper_uses_nofollow_and_atomic_replace(): void
    {
        $helper = (string) file_get_contents(base_path('../scripts/mxcentral-privileged'));

        $this->assertStringContainsString('os.O_NOFOLLOW', $helper);
        $this->assertStringContainsString('os.O_EXCL', $helper);
        $this->assertStringContainsString('os.fsync', $helper);
        $this->assertStringContainsString('os.replace', $helper);
        $this->assertStringContainsString('DOMAIN_PATTERN.fullmatch', $helper);
        $this->assertStringContainsString('validate_managed_change', $helper);
        $this->assertStringContainsString('redact_managed_read', $helper);
        $this->assertStringNotContainsString('operation == "write_file"', $helper);
        $this->assertStringNotContainsString('operation == "dkim_delete"', $helper);
        $this->assertStringNotContainsString('operation == "postmap"', $helper);
    }

    public function test_self_service_user_cannot_call_admin_user_update_api(): void
    {
        $actor = new CurrentActor(
            email: 'user@example.test',
            type: 'user',
            globalAdmin: false,
            domainAdmin: false,
            selfService: true,
            domains: ['example.test'],
        );
        $this->app->instance(AuthService::class, new FakeAuthService($actor));
        $session = [
            'auth_identity' => [
                'email' => 'user@example.test',
                'source' => 'mailbox-user',
                'version' => 'test-security-version',
            ],
        ];

        $this->withSession($session)
            ->patchJson('/api/users/user@example.test', ['quota' => 999999, 'active' => 0])
            ->assertForbidden();

        $this->withSession($session)
            ->patchJson('/api/mls/user@example.test', ['owners' => 'user@example.test', 'members' => 'attacker@example.test'])
            ->assertForbidden();
    }

    public function test_quarantine_raw_rejects_protocol_control_characters(): void
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
        ])->get('/activities/quarantined/raw/bad%0D%0Asecret')
            ->assertBadRequest();
    }

    public function test_authenticated_requests_revalidate_security_version_and_disable_browser_caching(): void
    {
        Route::middleware(['web', 'iredmail.auth'])->get('/_test/private-page', fn () => response('private'));
        $actor = new CurrentActor('postmaster@example.test', 'admin', true, false, false);
        $session = [
            'auth_identity' => [
                'email' => $actor->email,
                'source' => 'admin-record',
                'version' => 'current-version',
            ],
        ];

        $this->app->instance(AuthService::class, new FakeAuthService($actor, 'current-version'));
        $this->withSession($session)
            ->get('/_test/private-page')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache');

        $this->app->instance(AuthService::class, new FakeAuthService($actor, 'changed-version'));
        $this->withSession($session)
            ->get('/_test/private-page')
            ->assertRedirect(route('login'))
            ->assertSessionMissing('auth_identity');
    }
}
