<?php

namespace Tests\Unit;

use App\Services\IredMail\SetupInspector;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SetupInspectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['vmail', 'iredadmin', 'amavisd', 'iredapd', 'fail2ban'] as $connection) {
            config(["database.connections.{$connection}" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]]);
            DB::purge($connection);
        }

        $this->createTables('vmail', ['domain', 'mailbox', 'alias', 'forwardings', 'domain_admins']);
        $this->createTables('iredadmin', ['log', 'deleted_mailboxes']);
        $this->createTables('amavisd', ['msgs', 'msgrcpt', 'maddr', 'quarantine', 'wblist', 'mailaddr']);
        $this->createTables('iredapd', ['throttle']);
        $this->createTables('fail2ban', ['banned']);
    }

    protected function tearDown(): void
    {
        foreach (['vmail', 'iredadmin', 'amavisd', 'iredapd', 'fail2ban'] as $connection) {
            DB::purge($connection);
        }

        parent::tearDown();
    }

    public function test_iredadmin_settings_table_is_not_required(): void
    {
        $check = collect((new SetupInspector)->report())->firstWhere('name', 'iredadmin database');

        $this->assertTrue($check['ok']);
        $this->assertSame('Connected; expected tables present', $check['message']);
    }

    private function createTables(string $connection, array $tables): void
    {
        foreach ($tables as $table) {
            DB::connection($connection)->getSchemaBuilder()->create($table, function ($table): void {
                $table->id();
            });
        }
    }
}
