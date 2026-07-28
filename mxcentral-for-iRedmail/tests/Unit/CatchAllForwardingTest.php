<?php

namespace Tests\Unit;

use App\Services\IredMail\AccountRepository;
use App\Services\IredMail\AuditLogger;
use App\Services\IredMail\CurrentActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class CatchAllForwardingTest extends TestCase
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
            'iredmail.page_size' => 2,
        ]);

        DB::purge('vmail');
        DB::purge('iredadmin');

        DB::connection('vmail')->getSchemaBuilder()->create('domain', function ($table): void {
            $table->string('domain')->primary();
        });
        DB::connection('vmail')->getSchemaBuilder()->create('mailbox', function ($table): void {
            $table->string('username')->primary();
        });
        DB::connection('vmail')->getSchemaBuilder()->create('alias', function ($table): void {
            $table->string('address')->primary();
        });
        DB::connection('vmail')->getSchemaBuilder()->create('maillists', function ($table): void {
            $table->string('address')->primary();
        });
        DB::connection('vmail')->getSchemaBuilder()->create('forwardings', function ($table): void {
            $table->string('address');
            $table->string('forwarding');
            $table->string('domain');
            $table->string('dest_domain');
            $table->integer('is_forwarding')->default(0);
            $table->integer('active')->default(1);
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
            ['domain' => 'managed.test'],
            ['domain' => 'other.test'],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('vmail');
        DB::purge('iredadmin');

        parent::tearDown();
    }

    public function test_global_admin_can_forward_a_catch_all_to_an_external_address(): void
    {
        $this->repository()->createCatchAll($this->globalActor(), 'managed.test', [
            'forwarding' => 'paul@external.test',
        ]);

        $this->assertDatabaseHas('forwardings', [
            'address' => 'managed.test',
            'forwarding' => 'paul@external.test',
            'domain' => 'managed.test',
            'dest_domain' => 'external.test',
            'is_forwarding' => 1,
            'active' => 1,
        ], 'vmail');
    }

    public function test_domain_admin_can_manage_and_list_only_assigned_domain_catch_alls(): void
    {
        DB::connection('vmail')->table('forwardings')->insert([
            'address' => 'other.test',
            'forwarding' => 'owner@external.test',
            'domain' => 'other.test',
            'dest_domain' => 'external.test',
            'is_forwarding' => 1,
            'active' => 1,
        ]);

        $actor = $this->domainActor();
        $this->repository()->createCatchAll($actor, 'managed.test', ['forwarding' => 'team@external.test']);

        $rows = $this->repository()->catchAlls($actor);
        $this->assertSame(1, $rows->total());
        $this->assertSame('managed.test', $rows->items()[0]->domain);

        try {
            $this->repository()->createCatchAll($actor, 'other.test', ['forwarding' => 'team@external.test']);
            $this->fail('Expected an unmanaged domain to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_same_domain_destination_must_exist_to_prevent_a_forwarding_loop(): void
    {
        try {
            $this->repository()->createCatchAll($this->globalActor(), 'managed.test', [
                'forwarding' => 'missing@managed.test',
            ]);
            $this->fail('Expected a missing same-domain destination to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('prevent a delivery loop', $exception->getMessage());
        }

        DB::connection('vmail')->table('alias')->insert(['address' => 'catchall@managed.test']);
        $this->repository()->createCatchAll($this->globalActor(), 'managed.test', [
            'forwarding' => 'catchall@managed.test',
        ]);

        $this->assertDatabaseHas('forwardings', [
            'address' => 'managed.test',
            'forwarding' => 'catchall@managed.test',
        ], 'vmail');
    }

    public function test_missing_destination_on_another_hosted_domain_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->repository()->createCatchAll($this->globalActor(), 'managed.test', [
            'forwarding' => 'missing@other.test',
        ]);
    }

    public function test_global_admin_cannot_create_a_catch_all_for_an_unhosted_domain(): void
    {
        $this->expectException(ValidationException::class);

        $this->repository()->createCatchAll($this->globalActor(), 'unhosted.test', [
            'forwarding' => 'paul@external.test',
        ]);
    }

    private function repository(): AccountRepository
    {
        return new AccountRepository(new AuditLogger);
    }

    private function globalActor(): CurrentActor
    {
        return new CurrentActor(
            email: 'postmaster@managed.test',
            type: 'admin',
            globalAdmin: true,
            domainAdmin: false,
            selfService: false,
        );
    }

    private function domainActor(): CurrentActor
    {
        return new CurrentActor(
            email: 'admin@managed.test',
            type: 'admin',
            globalAdmin: false,
            domainAdmin: true,
            selfService: false,
            domains: ['managed.test'],
        );
    }
}
