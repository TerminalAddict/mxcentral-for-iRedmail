<?php

use App\Services\IredMail\IredMailUpgradeCheckService;
use App\Services\IredMail\QuarantineNotificationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('quarantine:notify-recipients {--force-all : Notify recipients for all currently quarantined messages, including messages already notified before} {--dry-run : Count notifications without sending mail or updating notification state}', function (QuarantineNotificationService $notifications) {
    $result = $notifications->notify(
        forceAll: (bool) $this->option('force-all'),
        dryRun: (bool) $this->option('dry-run'),
    );

    $this->info(($result['dry_run'] ? 'Dry run counted' : 'Sent').' '.$result['sent'].' recipient notification(s) for '.$result['messages'].' quarantined message(s).');

    foreach ($result['failed'] as $recipient => $message) {
        $this->error("{$recipient}: {$message}");
    }

    return $result['failed'] === [] ? Command::SUCCESS : Command::FAILURE;
})->purpose('Notify recipients who have quarantined mail visible in MXCentral.');

Artisan::command('iredmail:check-upgrades {--dry-run : Check releases without sending notifications or updating notified-version state} {--no-notify : Check releases without sending admin email}', function (IredMailUpgradeCheckService $upgrades) {
    $result = $upgrades->check(
        dryRun: (bool) $this->option('dry-run'),
        notify: ! (bool) $this->option('no-notify'),
    );

    $this->info('iRedMail installed: '.(data_get($result, 'iredmail.installed') ?: 'unknown'));
    $this->info('iRedMail latest: '.(data_get($result, 'iredmail.latest') ?: 'unknown'));
    $this->info('iRedAPD installed: '.(data_get($result, 'iredapd.installed') ?: 'unknown'));
    $this->info('iRedAPD latest: '.(data_get($result, 'iredapd.latest') ?: 'unknown'));

    if (($result['status'] ?? '') === 'failed') {
        $this->error('Upgrade check failed: '.$result['error']);

        return Command::FAILURE;
    }

    if (($result['iredmail']['upgrade_available'] ?? false) || ($result['iredapd']['upgrade_available'] ?? false)) {
        $this->warn('Upgrade available.');
    } else {
        $this->info('No upgrade detected.');
    }

    return Command::SUCCESS;
})->purpose('Check for published iRedMail and iRedAPD upgrades.');
