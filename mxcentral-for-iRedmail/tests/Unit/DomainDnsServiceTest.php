<?php

namespace Tests\Unit;

use App\Services\IredMail\AuditLogger;
use App\Services\IredMail\CurrentActor;
use App\Services\IredMail\DomainDkimService;
use App\Services\IredMail\DomainDnsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DomainDnsServiceTest extends TestCase
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
            'iredmail.dns_cache_store' => 'array',
        ]);

        DB::purge('vmail');
        DB::connection('vmail')->getSchemaBuilder()->create('domain', function ($table): void {
            $table->string('domain')->primary();
        });
        DB::connection('vmail')->table('domain')->insert(['domain' => 'example.com']);
        Cache::store('array')->clear();
    }

    protected function tearDown(): void
    {
        Cache::store('array')->clear();
        DB::purge('vmail');

        parent::tearDown();
    }

    public function test_status_returns_only_the_saved_dns_snapshot(): void
    {
        $service = $this->service();

        $this->assertNull($service->status($this->globalActor(), 'example.com'));

        $snapshot = [
            'domain' => 'example.com',
            'checked_at' => '2026-07-27 12:34:56',
            'dkim' => ['ok' => true],
            'mx' => ['ok' => true],
            'spf' => ['ok' => true],
            'dmarc' => ['ok' => true],
        ];
        Cache::store('array')->forever('mxcentral:domain-dns:v1:example.com', $snapshot);

        $this->assertSame($snapshot, $service->status($this->globalActor(), 'EXAMPLE.COM'));
    }

    public function test_forget_removes_the_saved_dns_snapshot(): void
    {
        Cache::store('array')->forever('mxcentral:domain-dns:v1:example.com', [
            'domain' => 'example.com',
        ]);

        $service = $this->service();
        $service->forget($this->globalActor(), 'example.com');

        $this->assertNull($service->status($this->globalActor(), 'example.com'));
    }

    private function service(): DomainDnsService
    {
        return new DomainDnsService(new DomainDkimService(new AuditLogger));
    }

    private function globalActor(): CurrentActor
    {
        return new CurrentActor(
            email: 'postmaster@example.test',
            type: 'mailbox-admin',
            globalAdmin: true,
            domainAdmin: false,
            selfService: false,
        );
    }
}
