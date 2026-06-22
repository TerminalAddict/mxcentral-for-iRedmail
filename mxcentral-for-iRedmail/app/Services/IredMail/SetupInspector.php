<?php

namespace App\Services\IredMail;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SetupInspector
{
    public function report(): array
    {
        $checks = [];
        $checks[] = $this->extension('pdo_mysql');
        $checks[] = $this->connection('vmail', ['domain', 'mailbox', 'alias', 'forwardings', 'domain_admins']);
        $checks[] = $this->connection('iredadmin', ['log', 'deleted_mailboxes', 'settings']);
        $checks[] = $this->connection('amavisd', ['msgs', 'msgrcpt', 'maddr', 'quarantine', 'wblist', 'mailaddr']);
        $checks[] = $this->connection('iredapd', ['throttle']);
        $checks[] = $this->connection('fail2ban', ['banned'], false);

        return $checks;
    }

    private function extension(string $name): array
    {
        return ['name' => "PHP extension {$name}", 'ok' => extension_loaded($name), 'message' => extension_loaded($name) ? 'Loaded' : 'Missing'];
    }

    private function connection(string $connection, array $tables, bool $required = true): array
    {
        try {
            DB::connection($connection)->getPdo();
            $missing = array_values(array_filter($tables, fn ($table) => ! Schema::connection($connection)->hasTable($table)));

            return [
                'name' => "{$connection} database",
                'ok' => $missing === [] || ! $required,
                'message' => $missing === [] ? 'Connected; expected tables present' : 'Missing tables: '.implode(', ', $missing),
            ];
        } catch (\Throwable $exception) {
            return ['name' => "{$connection} database", 'ok' => ! $required, 'message' => $exception->getMessage()];
        }
    }
}
