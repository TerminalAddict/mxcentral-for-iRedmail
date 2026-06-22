<?php

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
