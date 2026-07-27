<?php

namespace Tests\Unit;

use App\Services\IredMail\CurrentActor;
use App\Services\IredMail\PolicyRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fakes\FakePrivilegedHelper;
use Tests\TestCase;

final class PolicyRepositoryAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.amavisd' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'iredmail.page_size' => 50,
        ]);
        DB::purge('amavisd');
        DB::connection('amavisd')->getSchemaBuilder()->create('mailaddr', function ($table): void {
            $table->increments('id');
            $table->string('email');
            $table->integer('priority')->default(7);
            $table->string('domain')->nullable();
        });
        DB::connection('amavisd')->getSchemaBuilder()->create('wblist', function ($table): void {
            $table->integer('rid');
            $table->integer('sid');
            $table->string('wb');
            $table->integer('priority')->default(0);
        });

        DB::connection('amavisd')->table('mailaddr')->insert([
            ['id' => 1, 'email' => '@.', 'domain' => '.'],
            ['id' => 2, 'email' => '@managed.example', 'domain' => 'managed.example'],
            ['id' => 3, 'email' => 'user@managed.example', 'domain' => 'managed.example'],
            ['id' => 4, 'email' => '@other.example', 'domain' => 'other.example'],
            ['id' => 10, 'email' => 'sender@example.net', 'domain' => 'example.net'],
        ]);
        DB::connection('amavisd')->table('wblist')->insert([
            ['rid' => 1, 'sid' => 10, 'wb' => 'W'],
            ['rid' => 2, 'sid' => 10, 'wb' => 'W'],
            ['rid' => 3, 'sid' => 10, 'wb' => 'B'],
            ['rid' => 4, 'sid' => 10, 'wb' => 'W'],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('amavisd');
        parent::tearDown();
    }

    public function test_unfiltered_domain_admin_listing_is_scoped_to_managed_domains(): void
    {
        $rows = $this->repository()->wblist($this->domainActor())->getCollection();

        $this->assertSame(
            ['@managed.example', 'user@managed.example'],
            $rows->pluck('recipient')->sort()->values()->all(),
        );
    }

    public function test_domain_admin_cannot_filter_for_global_or_other_domain_entries(): void
    {
        foreach (['@.', '@other.example'] as $account) {
            try {
                $this->repository()->wblist($this->domainActor(), $account);
                $this->fail("Expected {$account} to be forbidden.");
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    private function repository(): PolicyRepository
    {
        return new PolicyRepository(new FakePrivilegedHelper);
    }

    private function domainActor(): CurrentActor
    {
        return new CurrentActor(
            email: 'admin@managed.example',
            type: 'admin',
            globalAdmin: false,
            domainAdmin: true,
            selfService: false,
            domains: ['managed.example'],
        );
    }
}
