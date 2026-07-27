<?php

namespace Tests\Unit;

use App\Services\IredMail\AuditLogger;
use App\Services\IredMail\CurrentActor;
use App\Services\IredMail\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DiscardRecipientAutomationTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/mxcentral-discard-'.bin2hex(random_bytes(6));
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
            'iredmail.postfix_postmap_command' => '/usr/bin/true',
            'iredmail.postfix_reload_command' => '/usr/bin/true',
        ]);

        DB::purge('vmail');
        DB::purge('iredadmin');

        DB::connection('vmail')->getSchemaBuilder()->create('domain', function ($table): void {
            $table->string('domain')->primary();
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

        DB::connection('vmail')->table('domain')->insert(['domain' => 'example.com']);
        file_put_contents(
            $this->temporaryDirectory.'/main.cf',
            "smtpd_recipient_restrictions = permit_mynetworks,\n    permit_sasl_authenticated,\n    reject_unauth_destination\n",
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

    public function test_saving_discard_recipients_installs_hook_runs_commands_and_is_idempotent(): void
    {
        $service = new SystemSettingsService(new AuditLogger);

        $result = $service->saveDiscardRecipients($this->actor(), ['blackhole@example.com']);

        $this->assertTrue($result['changed']);
        $this->assertTrue($result['postfix_hook']['changed']);
        $this->assertTrue($result['postmap']['ok']);
        $this->assertTrue($result['reload']['ok']);
        $this->assertStringContainsString(
            'blackhole@example.com DISCARD',
            file_get_contents($this->temporaryDirectory.'/discard_recipients'),
        );

        $mainCf = file_get_contents($this->temporaryDirectory.'/main.cf');
        $this->assertStringContainsString(
            'check_recipient_access hash:'.$this->temporaryDirectory.'/discard_recipients',
            $mainCf,
        );
        $this->assertStringContainsString('permit_mynetworks', $mainCf);
        $this->assertStringContainsString('permit_sasl_authenticated', $mainCf);
        $this->assertStringContainsString('reject_unauth_destination', $mainCf);

        $secondResult = $service->saveDiscardRecipients($this->actor(), ['blackhole@example.com']);

        $this->assertFalse($secondResult['changed']);
        $this->assertFalse($secondResult['postfix_hook']['changed']);
        $this->assertSame(
            1,
            substr_count(
                file_get_contents($this->temporaryDirectory.'/main.cf'),
                'check_recipient_access hash:'.$this->temporaryDirectory.'/discard_recipients',
            ),
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
