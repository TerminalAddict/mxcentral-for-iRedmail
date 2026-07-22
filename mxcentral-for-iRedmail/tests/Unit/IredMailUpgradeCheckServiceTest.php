<?php

namespace Tests\Unit;

use App\Services\IredMail\IredMailUpgradeCheckService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class IredMailUpgradeCheckServiceTest extends TestCase
{
    public function test_it_detects_iredmail_and_iredapd_upgrades_and_builds_sequential_path(): void
    {
        $statePath = tempnam(sys_get_temp_dir(), 'mxcentral-upgrade-state-');
        $iredmailReleasePath = tempnam(sys_get_temp_dir(), 'mxcentral-iredmail-release-');
        $iredapdVersionPath = tempnam(sys_get_temp_dir(), 'mxcentral-iredapd-version-');

        file_put_contents($iredmailReleasePath, "1.8.1\n");
        file_put_contents($iredapdVersionPath, "__version__ = '6.0'\n");
        @unlink($statePath);

        config([
            'iredmail.upgrade_check_state_path' => $statePath,
            'iredmail.iredmail_release_path' => $iredmailReleasePath,
            'iredmail.iredapd_version_file' => $iredapdVersionPath,
            'iredmail.upgrade_releases_url' => 'https://docs.example/iredmail.releases.html',
            'iredmail.upgrade_download_url' => 'https://www.example/download.html',
            'iredmail.iredapd_tags_api_url' => 'https://api.example/repos/iredmail/iRedAPD/tags',
            'iredmail.upgrade_docs_base_url' => 'https://docs.example',
        ]);

        Http::fake([
            'https://docs.example/iredmail.releases.html' => Http::response('
                <a href="upgrade.iredmail.1.8.2-1.8.3.html">Upgrade from iRedMail-1.8.2</a>
                <a href="upgrade.iredmail.1.8.1-1.8.2.html">Upgrade from iRedMail-1.8.1</a>
            '),
            'https://www.example/download.html' => Http::response('<a>Stable 1.8.3 (Jul 7, 2026)</a>'),
            'https://api.example/repos/iredmail/iRedAPD/tags' => Http::response([['name' => '6.1']]),
        ]);

        $result = (new IredMailUpgradeCheckService)->check(dryRun: true, notify: false);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('1.8.1', $result['iredmail']['installed']);
        $this->assertSame('1.8.3', $result['iredmail']['latest']);
        $this->assertTrue($result['iredmail']['upgrade_available']);
        $this->assertSame([
            [
                'from' => '1.8.1',
                'to' => '1.8.2',
                'url' => 'https://docs.example/upgrade.iredmail.1.8.1-1.8.2.html',
            ],
            [
                'from' => '1.8.2',
                'to' => '1.8.3',
                'url' => 'https://docs.example/upgrade.iredmail.1.8.2-1.8.3.html',
            ],
        ], $result['iredmail']['upgrade_path']);
        $this->assertSame('6.0', $result['iredapd']['installed']);
        $this->assertSame('6.1', $result['iredapd']['latest']);
        $this->assertTrue($result['iredapd']['upgrade_available']);
        $this->assertSame('Notifications disabled for this run.', $result['notification']['reason']);

        @unlink($statePath);
        @unlink($iredmailReleasePath);
        @unlink($iredapdVersionPath);
    }
}
