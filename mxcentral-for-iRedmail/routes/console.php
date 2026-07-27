<?php

use App\Services\IredMail\DeploymentHealthCheck;
use App\Services\IredMail\IredMailUpgradeCheckService;
use App\Services\IredMail\LoginRateLimiter;
use App\Services\IredMail\QuarantineNotificationService;
use App\Support\IredMailAddress;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mxcentral:check-production', function (DeploymentHealthCheck $check) {
    $errors = $check->errors();
    foreach ($errors as $error) {
        $this->error($error);
    }
    if ($errors !== []) {
        return Command::FAILURE;
    }

    $this->info('Production environment safety checks passed.');

    return Command::SUCCESS;
})->purpose('Fail unless application, database schemas, and the privileged helper are production-safe.');

Artisan::command('mxcentral:unlock-admin {email : Admin email address} {--ip= : Also clear a blocked source IP}', function (LoginRateLimiter $limiter) {
    $email = IredMailAddress::email((string) $this->argument('email'));
    if (! $email) {
        $this->error('Enter a valid admin email address.');

        return Command::INVALID;
    }

    $limiter->clearAccount($email);
    $ip = trim((string) $this->option('ip'));
    if ($ip !== '') {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->error('The --ip value is not a valid IP address.');

            return Command::INVALID;
        }
        $limiter->clearIp($ip);
    }

    $this->info("Cleared login lockout state for {$email}".($ip === '' ? '.' : " and {$ip}."));

    return Command::SUCCESS;
})->purpose('Clear an administrator login lockout after verifying the request out of band.');

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
