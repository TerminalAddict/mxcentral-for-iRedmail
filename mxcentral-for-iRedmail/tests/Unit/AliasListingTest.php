<?php

namespace Tests\Unit;

use App\Services\IredMail\AccountRepository;
use App\Services\IredMail\AuditLogger;
use App\Services\IredMail\CurrentActor;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AliasListingTest extends TestCase
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
            'iredmail.page_size' => 2,
        ]);

        DB::purge('vmail');

        DB::connection('vmail')->getSchemaBuilder()->create('alias', function ($table): void {
            $table->string('address')->primary();
            $table->string('domain');
            $table->string('name')->nullable();
            $table->string('accesspolicy')->nullable();
            $table->integer('active')->default(1);
        });
        DB::connection('vmail')->getSchemaBuilder()->create('forwardings', function ($table): void {
            $table->string('address');
            $table->string('forwarding');
            $table->integer('is_alias')->default(0);
        });

        DB::connection('vmail')->table('alias')->insert([
            ['address' => 'alpha@example.net', 'domain' => 'example.net', 'name' => 'Alpha', 'accesspolicy' => 'public', 'active' => 1],
            ['address' => 'billing@example.net', 'domain' => 'example.net', 'name' => 'Accounts Team', 'accesspolicy' => 'domain', 'active' => 0],
            ['address' => 'sales@example.com', 'domain' => 'example.com', 'name' => 'Sales Team', 'accesspolicy' => 'public', 'active' => 1],
            ['address' => 'support@example.com', 'domain' => 'example.com', 'name' => 'Help Desk', 'accesspolicy' => 'moderatorsonly', 'active' => 1],
            ['address' => 'zulu@example.com', 'domain' => 'example.com', 'name' => 'Zulu', 'accesspolicy' => 'public', 'active' => 1],
        ]);
        DB::connection('vmail')->table('forwardings')->insert([
            ['address' => 'alpha@example.net', 'forwarding' => 'zed@example.net', 'is_alias' => 1],
            ['address' => 'billing@example.net', 'forwarding' => 'accounts@example.net', 'is_alias' => 1],
            ['address' => 'sales@example.com', 'forwarding' => 'alice@example.com', 'is_alias' => 1],
            ['address' => 'support@example.com', 'forwarding' => 'helpdesk@example.net', 'is_alias' => 1],
            ['address' => 'zulu@example.com', 'forwarding' => 'zulu-owner@example.com', 'is_alias' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('vmail');

        parent::tearDown();
    }

    public function test_aliases_are_paginated_and_sorted_by_an_allowed_column(): void
    {
        $rows = $this->repository()->aliases($this->actor(), sort: 'domain', direction: 'desc');

        $this->assertSame(5, $rows->total());
        $this->assertSame(2, $rows->perPage());
        $this->assertSame(3, $rows->lastPage());
        $this->assertSame(
            ['alpha@example.net', 'billing@example.net'],
            collect($rows->items())->pluck('address')->all(),
        );

        $statusRows = $this->repository()->aliases($this->actor(), sort: 'status', direction: 'asc');
        $this->assertSame('billing@example.net', $statusRows->items()[0]->address);
    }

    public function test_alias_search_matches_address_name_policy_domain_and_members(): void
    {
        $this->assertSame(
            ['billing@example.net'],
            $this->addresses($this->repository()->aliases($this->actor(), search: 'accounts team')),
        );
        $this->assertSame(
            ['support@example.com'],
            $this->addresses($this->repository()->aliases($this->actor(), search: 'moderators')),
        );
        $this->assertSame(
            ['support@example.com'],
            $this->addresses($this->repository()->aliases($this->actor(), search: 'helpdesk@example.net')),
        );
        $this->assertSame(
            ['alpha@example.net', 'billing@example.net'],
            $this->addresses($this->repository()->aliases($this->actor(), domain: 'example.net')),
        );
    }

    public function test_unknown_sort_inputs_fall_back_to_address_ascending(): void
    {
        $rows = $this->repository()->aliases(
            $this->actor(),
            sort: 'address desc; drop table alias',
            direction: 'sideways',
        );

        $this->assertSame(
            ['alpha@example.net', 'billing@example.net'],
            collect($rows->items())->pluck('address')->all(),
        );
        $this->assertTrue(DB::connection('vmail')->getSchemaBuilder()->hasTable('alias'));
    }

    private function addresses($rows): array
    {
        return collect($rows->items())->pluck('address')->all();
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
