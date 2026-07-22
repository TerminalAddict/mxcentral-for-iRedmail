<?php

namespace Tests\Unit;

use App\Services\IredMail\AuditLogger;
use App\Services\IredMail\CurrentActor;
use App\Services\IredMail\DomainDkimService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DomainDkimServiceTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = sys_get_temp_dir().'/mxcentral-dkim-test-'.uniqid('', true);
        mkdir($this->tempDirectory, 0755, true);

        config([
            'database.connections.vmail' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'iredmail.amavisd_config_path' => $this->tempDirectory.'/50-user',
            'iredmail.amavisd_dkim_directory' => $this->tempDirectory.'/dkim',
            'iredmail.amavisd_dkim_selector' => 'mxcentral',
            'iredmail.amavisd_restart_command' => $this->tempDirectory.'/restart-amavis',
            'iredmail.amavisd_genrsa_command' => $this->tempDirectory.'/genrsa',
            'iredmail.amavisd_showkeys_command' => '',
            'iredmail.amavisd_testkeys_command' => '',
            'iredmail.amavisd_dkim_key_owner' => '',
            'iredmail.amavisd_dkim_key_group' => '',
            'iredmail.amavisd_dkim_chown_command' => '',
            'iredmail.amavisd_dkim_chmod_command' => '',
        ]);

        DB::purge('vmail');
        DB::connection('vmail')->getSchemaBuilder()->create('domain', function ($table): void {
            $table->string('domain')->primary();
        });

        mkdir($this->tempDirectory.'/dkim', 0755, true);
        file_put_contents($this->tempDirectory.'/restart-amavis', "#!/bin/sh\ntouch '{$this->tempDirectory}/restart-called'\n");
        chmod($this->tempDirectory.'/restart-amavis', 0755);
        file_put_contents($this->tempDirectory.'/genrsa', "#!/bin/sh\nprintf 'not-a-real-key' > \"$1\"\n");
        chmod($this->tempDirectory.'/genrsa', 0755);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDirectory);
        DB::purge('vmail');

        parent::tearDown();
    }

    public function test_cleanup_removed_domain_removes_managed_config_and_key_then_restarts_amavis(): void
    {
        $config = implode("\n", [
            '# Existing custom config.',
            '# BEGIN mxcentral-for-iRedmail managed DKIM keys',
            '# Custom addition by mxcentral-for-iRedmail.',
            '# iRedMail Debian/Ubuntu amavisd config file: /etc/amavis/conf.d/50-user.',
            "dkim_key('example.com', 'mxcentral', '{$this->tempDirectory}/dkim/example.com.pem');",
            "dkim_key('other.example', 'mxcentral', '{$this->tempDirectory}/dkim/other.example.pem');",
            'push @dkim_signature_options_bysender_maps, {',
            "    'example.com' => { d => 'example.com', a => 'rsa-sha256', ttl => 10*24*3600 },",
            "    'other.example' => { d => 'other.example', a => 'rsa-sha256', ttl => 10*24*3600 },",
            '};',
            '# END mxcentral-for-iRedmail managed DKIM keys',
            '',
        ]);
        file_put_contents($this->tempDirectory.'/50-user', $config);
        file_put_contents($this->tempDirectory.'/dkim/example.com.pem', 'old-key');
        file_put_contents($this->tempDirectory.'/dkim/example.com.pem.previous-20260101000000-test', 'old-backup');
        file_put_contents($this->tempDirectory.'/dkim/other.example.pem', 'other-key');

        $result = $this->service()->cleanupRemovedDomain($this->globalActor(), 'example.com');

        $updated = (string) file_get_contents($this->tempDirectory.'/50-user');
        $deleted = $result['keys']['deleted'];
        sort($deleted);
        $expectedDeleted = [
            $this->tempDirectory.'/dkim/example.com.pem',
            $this->tempDirectory.'/dkim/example.com.pem.previous-20260101000000-test',
        ];
        sort($expectedDeleted);

        $this->assertTrue($result['config']['changed']);
        $this->assertSame($expectedDeleted, $deleted);
        $this->assertStringNotContainsString('example.com', $updated);
        $this->assertStringContainsString('other.example', $updated);
        $this->assertFileDoesNotExist($this->tempDirectory.'/dkim/example.com.pem');
        $this->assertFileExists($this->tempDirectory.'/dkim/other.example.pem');
        $this->assertFileExists($this->tempDirectory.'/restart-called');
        $this->assertTrue($result['restart']['ok']);
    }

    public function test_rotating_existing_key_restarts_amavis_even_when_config_is_unchanged(): void
    {
        DB::connection('vmail')->table('domain')->insert(['domain' => 'example.com']);
        file_put_contents($this->tempDirectory.'/50-user', implode("\n", [
            '# BEGIN mxcentral-for-iRedmail managed DKIM keys',
            '# Custom addition by mxcentral-for-iRedmail.',
            '# iRedMail Debian/Ubuntu amavisd config file: /etc/amavis/conf.d/50-user.',
            "dkim_key('example.com', 'mxcentral', '{$this->tempDirectory}/dkim/example.com.pem');",
            'push @dkim_signature_options_bysender_maps, {',
            "    'example.com' => { d => 'example.com', a => 'rsa-sha256', ttl => 10*24*3600 },",
            '};',
            '# END mxcentral-for-iRedmail managed DKIM keys',
            '',
        ]));
        file_put_contents($this->tempDirectory.'/dkim/example.com.pem', 'old-key');

        $result = $this->service()->generate($this->globalActor(), 'example.com', 1024);

        $this->assertFalse($result['changed']);
        $this->assertTrue($result['rotated']);
        $this->assertTrue($result['restart']['ok']);
        $this->assertFileExists($this->tempDirectory.'/restart-called');
    }

    private function service(): DomainDkimService
    {
        return new DomainDkimService(new AuditLogger());
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

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $child = $path.'/'.$item;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
