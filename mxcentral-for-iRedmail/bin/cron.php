#!/usr/bin/env php
<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
$storagePath = $basePath.'/storage';
$statePath = $storagePath.'/app/cron-state.json';
$lockPath = $storagePath.'/framework/locks/cron.lock';

$tasks = [
    [
        'name' => 'quarantine-notifications',
        'description' => 'Notify users about newly quarantined mail.',
        'interval' => 6 * 60 * 60,
        'command' => ['quarantine:notify-recipients'],
    ],
];

$options = getopt('', ['list', 'force', 'task:']) ?: [];
$now = time();

ensureDirectory(dirname($statePath));
ensureDirectory(dirname($lockPath));

$lock = fopen($lockPath, 'c');
if (! $lock) {
    fwrite(STDERR, "Cannot open lock file: {$lockPath}\n");
    exit(1);
}

if (! flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

try {
    chdir($basePath);
    $state = loadState($statePath);
    $selectedTask = isset($options['task']) ? (string) $options['task'] : null;

    if (isset($options['list'])) {
        foreach ($tasks as $task) {
            $lastRun = (int) ($state[$task['name']]['last_run_at'] ?? 0);
            $dueAt = $lastRun + (int) $task['interval'];
            $status = $now >= $dueAt ? 'due' : 'next '.date('Y-m-d H:i:s', $dueAt);
            echo $task['name'].': '.$status.' - '.$task['description']."\n";
        }
        exit(0);
    }

    foreach ($tasks as $task) {
        if ($selectedTask !== null && $task['name'] !== $selectedTask) {
            continue;
        }

        $lastRun = (int) ($state[$task['name']]['last_run_at'] ?? 0);
        $due = isset($options['force']) || $lastRun === 0 || ($now - $lastRun) >= (int) $task['interval'];
        if (! $due) {
            continue;
        }

        $exitCode = runArtisan($basePath, $task['command']);
        $state[$task['name']] = [
            'last_run_at' => $now,
            'last_run_at_iso' => date(DATE_ATOM, $now),
            'last_exit_code' => $exitCode,
        ];

        saveState($statePath, $state);

        if ($exitCode !== 0) {
            exit($exitCode);
        }
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

function runArtisan(string $basePath, array $arguments): int
{
    $php = PHP_BINARY ?: '/usr/bin/php';
    $command = [$php, $basePath.'/artisan', ...$arguments];

    if (runningAsRoot() && wwwDataExists() && is_executable('/usr/bin/sudo')) {
        $command = ['/usr/bin/sudo', '-u', 'www-data', $php, $basePath.'/artisan', ...$arguments];
    }

    $process = proc_open($command, [
        0 => ['file', '/dev/null', 'r'],
        1 => STDOUT,
        2 => STDERR,
    ], $pipes, $basePath);

    if (! is_resource($process)) {
        fwrite(STDERR, 'Cannot start command: '.implode(' ', $command)."\n");
        return 1;
    }

    return proc_close($process);
}

function runningAsRoot(): bool
{
    return function_exists('posix_geteuid') && posix_geteuid() === 0;
}

function wwwDataExists(): bool
{
    return function_exists('posix_getpwnam') && posix_getpwnam('www-data') !== false;
}

function ensureDirectory(string $path): void
{
    if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
        fwrite(STDERR, "Cannot create directory: {$path}\n");
        exit(1);
    }
}

function loadState(string $path): array
{
    if (! is_readable($path)) {
        return [];
    }

    $state = json_decode((string) file_get_contents($path), true);

    return is_array($state) ? $state : [];
}

function saveState(string $path, array $state): void
{
    $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($path, $encoded."\n", LOCK_EX) === false) {
        fwrite(STDERR, "Cannot write state file: {$path}\n");
        exit(1);
    }
}
