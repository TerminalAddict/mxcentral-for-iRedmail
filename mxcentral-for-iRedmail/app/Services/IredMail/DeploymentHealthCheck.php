<?php

namespace App\Services\IredMail;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DeploymentHealthCheck
{
    public function __construct(
        private readonly ProductionSafetyCheck $production,
        private readonly PrivilegedHelper $privileged,
    ) {}

    public function errors(): array
    {
        $errors = $this->production->errors();
        $tables = [
            'vmail' => ['admin', 'alias', 'alias_domain', 'domain', 'domain_admins', 'forwardings', 'mailbox', 'maillist_owners', 'maillists', 'moderators'],
            'iredadmin' => ['log', 'deleted_mailboxes'],
            'amavisd' => ['msgs', 'msgrcpt', 'maddr', 'quarantine', 'mailaddr', 'wblist'],
            'iredapd' => ['throttle'],
            'fail2ban' => ['banned'],
        ];
        foreach ($tables as $connection => $requiredTables) {
            try {
                DB::connection($connection)->getPdo();
                foreach ($requiredTables as $table) {
                    if (! Schema::connection($connection)->hasTable($table)) {
                        $errors[] = "{$connection}.{$table} is missing or inaccessible.";
                    }
                }
            } catch (\Throwable $exception) {
                $errors[] = "{$connection} database health check failed: ".$exception->getMessage();
            }
        }

        $helper = $this->privileged->run('health_check');
        if (! $helper['ok']) {
            $errors[] = 'Privileged helper health check failed: '.$helper['message'];
        }

        return $errors;
    }
}
