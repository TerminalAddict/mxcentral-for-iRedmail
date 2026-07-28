<?php

namespace Tests\Feature;

use App\Services\IredMail\AuditLogRepository;
use App\Services\IredMail\AuthService;
use App\Services\IredMail\CurrentActor;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fakes\FakeAuthService;
use Tests\TestCase;

final class AuditLogAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.iredadmin' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'iredmail.page_size' => 1,
        ]);
        DB::purge('iredadmin');
        DB::connection('iredadmin')->getSchemaBuilder()->create('log', function ($table): void {
            $table->id();
            $table->dateTime('timestamp');
            $table->string('admin');
            $table->string('ip');
            $table->string('domain')->default('');
            $table->string('username')->default('');
            $table->string('event', 20);
            $table->string('loglevel', 10)->default('info');
            $table->text('msg')->nullable();
        });

        DB::connection('iredadmin')->table('log')->insert([
            [
                'timestamp' => '2026-07-28 12:01:00',
                'admin' => 'postmaster@example.com',
                'ip' => '192.0.2.10',
                'domain' => 'example.com',
                'username' => 'user@example.com',
                'event' => 'view',
                'loglevel' => 'info',
                'msg' => 'Authorized one-time decryptable password reveal for user@example.com. Purpose: Customer migration <script>alert(1)</script>',
            ],
            [
                'timestamp' => '2026-07-28 12:00:00',
                'admin' => 'postmaster@example.com',
                'ip' => '192.0.2.10',
                'domain' => 'example.com',
                'username' => 'other@example.com',
                'event' => 'create',
                'loglevel' => 'info',
                'msg' => 'Created user other@example.com.',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('iredadmin');

        parent::tearDown();
    }

    public function test_global_admin_can_filter_paginated_audit_entries_and_messages_are_escaped(): void
    {
        $actor = $this->globalActor();
        $this->app->instance(AuthService::class, new FakeAuthService($actor));

        $this->withSession($this->authSession($actor))
            ->get(route('system.audit-log', ['event' => 'view', 'q' => 'Customer migration']))
            ->assertOk()
            ->assertSee('Audit Log')
            ->assertSee('Customer migration')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('Created user other@example.com.');

        $this->withSession($this->authSession($actor))
            ->get(route('system.audit-log', ['page' => 2]))
            ->assertOk()
            ->assertSee('Created user other@example.com.');
    }

    public function test_domain_admin_cannot_access_route_or_repository(): void
    {
        $actor = new CurrentActor(
            email: 'admin@example.com',
            type: 'admin',
            globalAdmin: false,
            domainAdmin: true,
            selfService: false,
            domains: ['example.com'],
        );
        $this->app->instance(AuthService::class, new FakeAuthService($actor));

        $this->withSession($this->authSession($actor))
            ->get(route('system.audit-log'))
            ->assertForbidden();

        try {
            (new AuditLogRepository)->entries($actor);
            $this->fail('A domain administrator queried the global audit log.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function globalActor(): CurrentActor
    {
        return new CurrentActor(
            email: 'postmaster@example.com',
            type: 'admin',
            globalAdmin: true,
            domainAdmin: false,
            selfService: false,
        );
    }

    private function authSession(CurrentActor $actor): array
    {
        return [
            'auth_identity' => [
                'email' => $actor->email,
                'source' => 'admin-record',
                'version' => 'test-security-version',
            ],
        ];
    }
}
