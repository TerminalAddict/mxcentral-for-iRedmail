<?php

namespace Tests\Unit;

use App\Services\IredMail\AuditLogger;
use App\Services\IredMail\CurrentActor;
use App\Services\IredMail\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DomainStagingTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/mxcentral-staging-'.bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0700, true);

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
            'iredmail.postfix_main_cf_path' => $this->temporaryDirectory.'/main.cf',
            'iredmail.postfix_discard_recipients_path' => $this->temporaryDirectory.'/discard_recipients',
            'iredmail.postfix_staging_domains_path' => $this->temporaryDirectory.'/staging_domains.pcre',
            'iredmail.postfix_reload_command' => '/usr/bin/true',
        ]);

        DB::purge('vmail');
        DB::purge('iredadmin');

        DB::connection('vmail')->getSchemaBuilder()->create('domain', function ($table): void {
            $table->string('domain')->primary();
            $table->integer('active')->default(1);
        });
        DB::connection('vmail')->getSchemaBuilder()->create('alias_domain', function ($table): void {
            $table->string('alias_domain')->primary();
            $table->string('target_domain');
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

        DB::connection('vmail')->table('domain')->insert(['domain' => 'example.com', 'active' => 1]);
        DB::connection('vmail')->table('alias_domain')->insert([
            'alias_domain' => 'alias.example.net',
            'target_domain' => 'example.com',
        ]);
        file_put_contents(
            $this->temporaryDirectory.'/main.cf',
            'smtpd_recipient_restrictions = check_recipient_access hash:'.$this->temporaryDirectory."/discard_recipients, permit_mynetworks, reject_unauth_destination\n",
        );
    }

    protected function tearDown(): void
    {
        DB::purge('vmail');
        DB::purge('iredadmin');

        foreach (glob($this->temporaryDirectory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_staging_domain_manages_pcre_hook_aliases_and_reload_without_disabling_accounts(): void
    {
        $service = new SystemSettingsService(new AuditLogger);

        $result = $service->saveDomainStaging($this->actor(), 'example.com', true);

        $this->assertTrue($result['state_changed']);
        $this->assertTrue($result['reload']['ok']);
        $this->assertSame(['example.com'], $service->stagedDomains());
        $this->assertSame(1, DB::connection('vmail')->table('domain')->where('domain', 'example.com')->value('active'));

        $map = file_get_contents($this->temporaryDirectory.'/staging_domains.pcre');
        $this->assertStringContainsString('# MXCentral staged primary domain: example.com', $map);
        $this->assertStringContainsString('/@example\\.com$/i 450 4.2.0', $map);
        $this->assertStringContainsString('/@alias\\.example\\.net$/i 450 4.2.0', $map);

        $mainCf = file_get_contents($this->temporaryDirectory.'/main.cf');
        $stagingHook = 'check_recipient_access pcre:'.$this->temporaryDirectory.'/staging_domains.pcre';
        $discardHook = 'check_recipient_access hash:'.$this->temporaryDirectory.'/discard_recipients';
        $this->assertStringContainsString($stagingHook, $mainCf);
        $this->assertLessThan(strpos($mainCf, $discardHook), strpos($mainCf, $stagingHook));

        $unstaged = $service->saveDomainStaging($this->actor(), 'example.com', false);

        $this->assertTrue($unstaged['state_changed']);
        $this->assertSame([], $service->stagedDomains());
        $this->assertStringNotContainsString('@example\\.com', file_get_contents($this->temporaryDirectory.'/staging_domains.pcre'));
    }

    public function test_refresh_adds_new_alias_domain_to_an_existing_staging_map(): void
    {
        $service = new SystemSettingsService(new AuditLogger);
        $service->saveDomainStaging($this->actor(), 'example.com', true);

        DB::connection('vmail')->table('alias_domain')->insert([
            'alias_domain' => 'new-alias.example.org',
            'target_domain' => 'example.com',
        ]);
        $result = $service->refreshStagingDomains();

        $this->assertTrue($result['changed']);
        $this->assertStringContainsString(
            '/@new\\-alias\\.example\\.org$/i 450 4.2.0',
            file_get_contents($this->temporaryDirectory.'/staging_domains.pcre'),
        );
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
