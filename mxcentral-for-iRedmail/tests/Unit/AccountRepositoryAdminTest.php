<?php

namespace Tests\Unit;

use App\Services\IredMail\AccountRepository;
use App\Services\IredMail\AuditLogger;
use App\Services\IredMail\CurrentActor;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AccountRepositoryAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.vmail' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'database.connections.iredadmin' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        DB::purge('vmail');
        DB::purge('iredadmin');

        DB::connection('vmail')->getSchemaBuilder()->create('domain', function ($table): void {
            $table->string('domain')->primary();
        });
        DB::connection('vmail')->getSchemaBuilder()->create('mailbox', function ($table): void {
            $table->string('username')->primary();
            $table->integer('isadmin')->default(0);
            $table->integer('isglobaladmin')->default(0);
            $table->dateTime('modified')->nullable();
        });
        DB::connection('vmail')->getSchemaBuilder()->create('admin', function ($table): void {
            $table->string('username')->primary();
        });
        DB::connection('vmail')->getSchemaBuilder()->create('domain_admins', function ($table): void {
            $table->string('username');
            $table->string('domain');
            $table->integer('active')->default(1);
            $table->dateTime('created')->nullable();
            $table->dateTime('modified')->nullable();
            $table->dateTime('expired')->nullable();
        });
        DB::connection('iredadmin')->getSchemaBuilder()->create('log', function ($table): void {
            $table->string('admin')->nullable();
            $table->string('ip')->nullable();
            $table->string('domain')->nullable();
            $table->string('username')->nullable();
            $table->string('event')->nullable();
            $table->string('loglevel')->nullable();
            $table->text('msg')->nullable();
        });

        DB::connection('vmail')->table('domain')->insert([
            ['domain' => 'example.com'],
            ['domain' => 'example.net'],
        ]);
        DB::connection('vmail')->table('mailbox')->insert(['username' => 'admin@example.com']);
    }

    protected function tearDown(): void
    {
        DB::purge('vmail');
        DB::purge('iredadmin');

        parent::tearDown();
    }

    public function test_assign_admin_accepts_multiple_hosted_domains(): void
    {
        $this->repository()->assignAdmin($this->actor(), [
            'username' => 'admin@example.com',
            'domains' => ['example.com', 'example.net'],
        ]);

        $domains = DB::connection('vmail')->table('domain_admins')
            ->where('username', 'admin@example.com')
            ->orderBy('domain')
            ->pluck('domain')
            ->all();

        $this->assertSame(['example.com', 'example.net'], $domains);
        $this->assertSame(1, DB::connection('vmail')->table('mailbox')->where('username', 'admin@example.com')->value('isadmin'));
        $this->assertSame(0, DB::connection('vmail')->table('mailbox')->where('username', 'admin@example.com')->value('isglobaladmin'));
    }

    public function test_assign_admin_treats_all_as_global_even_with_other_domains_selected(): void
    {
        $this->repository()->assignAdmin($this->actor(), [
            'username' => 'admin@example.com',
            'domains' => ['ALL', 'example.com'],
        ]);

        $domains = DB::connection('vmail')->table('domain_admins')
            ->where('username', 'admin@example.com')
            ->pluck('domain')
            ->all();

        $this->assertSame(['ALL'], $domains);
        $this->assertSame(1, DB::connection('vmail')->table('mailbox')->where('username', 'admin@example.com')->value('isglobaladmin'));
    }

    private function repository(): AccountRepository
    {
        return new AccountRepository(new AuditLogger);
    }

    private function actor(): CurrentActor
    {
        return new CurrentActor(
            email: 'postmaster@example.com',
            type: 'admin',
            globalAdmin: true,
            domainAdmin: false,
            selfService: false,
            domains: [],
        );
    }
}
