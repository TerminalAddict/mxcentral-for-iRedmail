<?php

namespace Tests\Unit;

use App\Services\IredMail\AuthService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuthServiceRevalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.vmail' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('vmail');
        $schema = DB::connection('vmail')->getSchemaBuilder();
        $schema->create('admin', function ($table): void {
            $table->string('username')->primary();
            $table->string('password');
            $table->integer('active');
            $table->dateTime('modified')->nullable();
            $table->dateTime('passwordlastchange')->nullable();
        });
        $schema->create('domain_admins', function ($table): void {
            $table->string('username');
            $table->string('domain');
            $table->integer('active');
        });
        $schema->create('mailbox', function ($table): void {
            $table->string('username')->primary();
            $table->string('password');
            $table->integer('active');
            $table->integer('isadmin')->default(0);
            $table->integer('isglobaladmin')->default(0);
        });
    }

    public function test_privilege_changes_change_the_security_version_and_disabled_admins_fail_closed(): void
    {
        DB::connection('vmail')->table('admin')->insert([
            'username' => 'admin@example.com',
            'password' => '{PLAIN}correct-password',
            'active' => 1,
        ]);
        DB::connection('vmail')->table('domain_admins')->insert([
            'username' => 'admin@example.com',
            'domain' => 'example.com',
            'active' => 1,
        ]);

        $auth = new AuthService;
        $initial = $auth->attemptIdentity('admin@example.com', 'correct-password');
        $this->assertNotNull($initial);
        $this->assertSame(['example.com'], $initial['actor']->domains);

        DB::connection('vmail')->table('domain_admins')->insert([
            'username' => 'admin@example.com',
            'domain' => 'second.example',
            'active' => 1,
        ]);
        $changed = $auth->refreshIdentity('admin@example.com', 'admin-record');
        $this->assertNotNull($changed);
        $this->assertNotSame($initial['version'], $changed['version']);

        DB::connection('vmail')->table('admin')->where('username', 'admin@example.com')->update(['active' => 0]);
        $this->assertNull($auth->refreshIdentity('admin@example.com', 'admin-record'));
    }
}
