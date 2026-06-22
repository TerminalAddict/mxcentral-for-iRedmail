<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SecurityHardeningTest extends TestCase
{
    public function test_self_service_user_cannot_call_admin_user_update_api(): void
    {
        $session = [
            'actor' => [
                'email' => 'user@example.test',
                'type' => 'user',
                'global_admin' => false,
                'domain_admin' => false,
                'self_service' => true,
                'domains' => ['example.test'],
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
        $this->withSession([
            'actor' => [
                'email' => 'postmaster@example.test',
                'type' => 'mailbox-admin',
                'global_admin' => true,
                'domain_admin' => false,
                'self_service' => false,
                'domains' => [],
            ],
        ])->get('/activities/quarantined/raw/bad%0D%0Asecret')
            ->assertBadRequest();
    }

}
