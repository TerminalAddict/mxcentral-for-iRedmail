<?php

namespace App\Services\IredMail;

use App\Support\IredMailAddress;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

final class DomainDkimService
{
    private const BEGIN_MARKER = '# BEGIN mxcentral-for-iRedmail managed DKIM keys';
    private const END_MARKER = '# END mxcentral-for-iRedmail managed DKIM keys';

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function status(CurrentActor $actor, string $domain): array
    {
        $domain = $this->validatedHostedDomain($actor, $domain);
        $keyPath = $this->keyPath($domain);
        $expected = $this->expectedDnsRecord($domain);
        $dns = $this->dnsStatus($domain);

        return [
            'domain' => $domain,
            'selector' => $this->selector(),
            'dns_name' => $this->dnsName($domain),
            'key_path' => $keyPath,
            'config_path' => $this->configPath(),
            'key_exists' => is_file($keyPath),
            'key_readable' => is_readable($keyPath),
            'config_readable' => is_readable($this->configPath()),
            'config_writable' => $this->configWritable(),
            'configured' => $this->domainConfigured($domain),
            'genrsa_configured' => $this->genrsaCommand() !== '',
            'showkeys_configured' => $this->showkeysCommand() !== '',
            'restart_configured' => $this->restartCommand() !== '',
            'testkeys_configured' => $this->testkeysCommand() !== '',
            'expected_txt' => $expected['txt'],
            'expected_chunks' => $expected['chunks'],
            'dns' => $dns,
        ];
    }

    public function generate(CurrentActor $actor, string $domain, int $bits = 1024): array
    {
        abort_unless($actor->globalAdmin, 403);
        $domain = $this->validatedHostedDomain($actor, $domain);
        $bits = $this->validBits($bits);

        $keyPath = $this->keyPath($domain);
        $directory = dirname($keyPath);
        if (! is_dir($directory) && @mkdir($directory, 0750, true) === false) {
            throw ValidationException::withMessages(['dkim' => "Cannot create DKIM directory {$directory}."]);
        }
        if (! is_writable($directory)) {
            throw ValidationException::withMessages(['dkim' => "Cannot write DKIM directory {$directory}."]);
        }

        $rotated = is_file($keyPath);
        $generated = $this->generateKeyFile($keyPath, $bits);
        if (! $generated['ok']) {
            throw ValidationException::withMessages(['dkim' => 'DKIM key generation failed: '.$generated['message']]);
        }

        $this->secureKeyFile($keyPath);
        $changed = $this->ensureAmavisdConfig($domain);
        $restart = $this->restartAmavisd();
        $testkeys = $this->testKeys();
        $this->audit->log('update', ($rotated ? 'Rotated' : 'Generated')." {$bits}-bit DKIM key for {$domain} with selector ".$this->selector().'.', $domain);

        return [
            'domain' => $domain,
            'key_path' => $keyPath,
            'bits' => $bits,
            'rotated' => $rotated,
            'changed' => $changed,
            'restart' => $restart,
            'testkeys' => $testkeys,
            'status' => $this->status($actor, $domain),
        ];
    }

    public function cleanupRemovedDomain(CurrentActor $actor, string $domain): array
    {
        abort_unless($actor->globalAdmin, 403);
        $domain = IredMailAddress::domain($domain) ?? abort(404);

        $config = $this->removeAmavisdConfig($domain);
        $keys = $this->deleteKeyFiles($domain);
        $needsRestart = $config['changed'] || $keys['deleted'] !== [];
        $restart = $needsRestart
            ? $this->restartAmavisd()
            : ['configured' => $this->restartCommand() !== '', 'ok' => true, 'message' => 'No restart needed.'];

        if ($needsRestart && ! ($restart['configured'] ?? false)) {
            throw ValidationException::withMessages(['dkim' => 'DKIM cleanup changed files, but AMAVISD_RESTART_COMMAND is not configured. Restart amavis manually before deleting the domain.']);
        }
        if ($needsRestart && ! ($restart['ok'] ?? false)) {
            throw ValidationException::withMessages(['dkim' => 'DKIM cleanup changed files, but amavisd restart failed: '.$restart['message']]);
        }

        $this->audit->log('delete', "Cleaned up DKIM config and key files for deleted domain {$domain}.", $domain);

        return [
            'domain' => $domain,
            'config' => $config,
            'keys' => $keys,
            'restart' => $restart,
        ];
    }

    public function checkDns(CurrentActor $actor, string $domain): array
    {
        $domain = $this->validatedHostedDomain($actor, $domain);
        $status = $this->status($actor, $domain);
        $this->audit->log('check', "Checked DKIM DNS for {$domain}.", $domain);

        return $status['dns'];
    }

    private function validatedHostedDomain(CurrentActor $actor, string $domain): string
    {
        $domain = IredMailAddress::domain($domain) ?? abort(404);
        abort_unless($actor->canManageDomain($domain), 403);

        if (! DB::connection('vmail')->table('domain')->where('domain', $domain)->exists()) {
            abort(404);
        }

        return $domain;
    }

    private function ensureAmavisdConfig(string $domain): bool
    {
        $path = $this->configPath();

        return $this->withFileLock($path, function () use ($path, $domain) {
            if (! is_file($path) || ! is_readable($path)) {
                throw ValidationException::withMessages(['dkim' => "Cannot read amavisd config {$path}."]);
            }
            if (! $this->configWritable()) {
                throw ValidationException::withMessages(['dkim' => "Cannot write amavisd config {$path}."]);
            }

            $original = (string) file_get_contents($path);
            $domains = $this->configuredManagedDomains($original);
            $domains[$domain] = true;
            ksort($domains);

            $updated = $this->replaceManagedBlock($original, array_keys($domains));
            if ($updated === $original) {
                return false;
            }

            if (@copy($path, $path.'.bak') === false) {
                throw ValidationException::withMessages(['dkim' => "Cannot create backup {$path}.bak."]);
            }
            if (@file_put_contents($path, $updated, LOCK_EX) === false) {
                throw ValidationException::withMessages(['dkim' => "Cannot write {$path}."]);
            }

            return true;
        });
    }

    private function removeAmavisdConfig(string $domain): array
    {
        $path = $this->configPath();

        return $this->withFileLock($path, function () use ($path, $domain) {
            if (! is_file($path)) {
                return ['changed' => false, 'path' => $path, 'message' => "Amavisd config {$path} does not exist."];
            }
            if (! is_readable($path)) {
                throw ValidationException::withMessages(['dkim' => "Cannot read amavisd config {$path}."]);
            }
            if (! $this->configWritable()) {
                throw ValidationException::withMessages(['dkim' => "Cannot write amavisd config {$path}."]);
            }

            $original = (string) file_get_contents($path);
            $domains = $this->configuredManagedDomains($original);
            if (! isset($domains[$domain])) {
                return ['changed' => false, 'path' => $path, 'message' => "{$domain} was not present in the mxcentral-managed DKIM block."];
            }

            unset($domains[$domain]);
            ksort($domains);
            $updated = $this->replaceManagedBlock($original, array_keys($domains));
            if ($updated === $original) {
                return ['changed' => false, 'path' => $path, 'message' => 'No amavisd config change needed.'];
            }

            if (@copy($path, $path.'.bak') === false) {
                throw ValidationException::withMessages(['dkim' => "Cannot create backup {$path}.bak."]);
            }
            if (@file_put_contents($path, $updated, LOCK_EX) === false) {
                throw ValidationException::withMessages(['dkim' => "Cannot write {$path}."]);
            }

            return ['changed' => true, 'path' => $path, 'message' => "Removed {$domain} from the mxcentral-managed DKIM block."];
        });
    }

    private function configuredManagedDomains(string $content): array
    {
        $domains = [];
        $blockPattern = '/'.preg_quote(self::BEGIN_MARKER, '/').'.*?'.preg_quote(self::END_MARKER, '/').'/s';
        if (! preg_match($blockPattern, $content, $match)) {
            return $domains;
        }

        preg_match_all('/dkim_key\(\s*[\'"]([^\'"]+)[\'"]\s*,/i', $match[0], $matches);
        foreach ($matches[1] ?? [] as $domain) {
            $domain = IredMailAddress::domain($domain);
            if ($domain) {
                $domains[$domain] = true;
            }
        }

        return $domains;
    }

    private function replaceManagedBlock(string $content, array $domains): string
    {
        $pattern = '/'.preg_quote(self::BEGIN_MARKER, '/').'.*?'.preg_quote(self::END_MARKER, '/').'\R?/s';

        if ($domains === []) {
            if (preg_match($pattern, $content)) {
                return rtrim(preg_replace($pattern, '', $content, 1) ?? $content)."\n";
            }

            return $content;
        }

        $block = $this->managedBlock($domains);
        if (preg_match($pattern, $content)) {
            return preg_replace($pattern, $block."\n", $content, 1) ?? $content;
        }

        return rtrim($content)."\n\n".$block."\n";
    }

    private function deleteKeyFiles(string $domain): array
    {
        $keyPath = $this->keyPath($domain);
        $paths = array_values(array_unique(array_merge([$keyPath], glob($keyPath.'.previous-*') ?: [])));
        $deleted = [];

        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }

            if (! @unlink($path)) {
                throw ValidationException::withMessages(['dkim' => "Cannot delete DKIM key file {$path}."]);
            }

            $deleted[] = $path;
        }

        return [
            'deleted' => $deleted,
            'path' => $keyPath,
        ];
    }

    private function managedBlock(array $domains): string
    {
        $lines = [
            self::BEGIN_MARKER,
            '# Custom addition by mxcentral-for-iRedmail.',
            '# iRedMail Debian/Ubuntu amavisd config file: /etc/amavis/conf.d/50-user.',
        ];

        foreach ($domains as $domain) {
            $lines[] = sprintf("dkim_key('%s', '%s', '%s');", $this->perlSingleQuoted($domain), $this->perlSingleQuoted($this->selector()), $this->perlSingleQuoted($this->keyPath($domain)));
        }

        $lines[] = 'push @dkim_signature_options_bysender_maps, {';
        foreach ($domains as $domain) {
            $lines[] = sprintf("    '%s' => { d => '%s', a => 'rsa-sha256', ttl => 10*24*3600 },", $this->perlSingleQuoted($domain), $this->perlSingleQuoted($domain));
        }
        $lines[] = '};';
        $lines[] = self::END_MARKER;

        return implode("\n", $lines);
    }

    private function domainConfigured(string $domain): bool
    {
        $path = $this->configPath();
        if (! is_readable($path)) {
            return false;
        }

        return isset($this->configuredManagedDomains((string) file_get_contents($path))[$domain]);
    }

    private function expectedDnsRecord(string $domain): array
    {
        $keyPath = $this->keyPath($domain);
        if (! is_readable($keyPath)) {
            return $this->showKeysDnsRecord($domain);
        }

        $private = openssl_pkey_get_private((string) file_get_contents($keyPath));
        if ($private === false) {
            return $this->showKeysDnsRecord($domain);
        }

        $details = openssl_pkey_get_details($private);
        $public = (string) ($details['key'] ?? '');
        $public = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $public) ?? '';
        if ($public === '') {
            return $this->showKeysDnsRecord($domain);
        }

        $txt = 'v=DKIM1; p='.$public;

        return ['txt' => $txt, 'chunks' => str_split($txt, 240)];
    }

    private function showKeysDnsRecord(string $domain): array
    {
        $command = $this->showkeysCommand();
        if ($command === '') {
            return ['txt' => null, 'chunks' => []];
        }

        $result = $this->runConfiguredCommand($command);
        if (! $result['ok']) {
            return ['txt' => null, 'chunks' => []];
        }

        $record = $this->extractShowkeysRecord($result['message'], $this->dnsName($domain));
        if ($record === null) {
            return ['txt' => null, 'chunks' => []];
        }

        preg_match_all('/"([^"]*)"/', $record, $chunks);
        $txt = implode('', $chunks[1] ?? []);

        return $txt === '' ? ['txt' => null, 'chunks' => []] : ['txt' => $txt, 'chunks' => $chunks[1] ?? []];
    }

    private function extractShowkeysRecord(string $output, string $dnsName): ?string
    {
        $lines = preg_split('/\R/', $output) ?: [];
        $record = [];
        $collecting = false;

        foreach ($lines as $line) {
            if (! $collecting) {
                if (preg_match('/^\s*'.preg_quote($dnsName, '/').'\.?\s+(?:\d+\s+)?TXT\s+\(/', $line)) {
                    $collecting = true;
                    $record[] = $line;
                }

                continue;
            }

            $record[] = $line;
            if (preg_match('/\)\s*$/', $line)) {
                return implode("\n", $record);
            }
        }

        return null;
    }

    private function dnsStatus(string $domain): array
    {
        $expected = $this->expectedDnsRecord($domain)['txt'];
        $name = $this->dnsName($domain);
        $records = $this->txtRecords($name);
        $match = $expected !== null && in_array($expected, $records, true);

        return [
            'name' => $name,
            'expected' => $expected,
            'records' => $records,
            'match' => $match,
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    private function txtRecords(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);
        if ($records === false) {
            return [];
        }

        return array_values(array_unique(array_map(function (array $record): string {
            if (isset($record['entries']) && is_array($record['entries'])) {
                return implode('', $record['entries']);
            }

            return (string) ($record['txt'] ?? '');
        }, $records)));
    }

    private function runGenrsa(string $keyPath, int $bits): array
    {
        return $this->runConfiguredCommand($this->genrsaCommand(), [$keyPath, (string) $bits]);
    }

    private function generateKeyFile(string $keyPath, int $bits): array
    {
        if (! is_file($keyPath)) {
            return $this->runGenrsa($keyPath, $bits);
        }

        $backupPath = $keyPath.'.previous-'.date('YmdHis').'-'.uniqid();
        if (! @rename($keyPath, $backupPath)) {
            return ['configured' => true, 'ok' => false, 'message' => "Cannot rotate existing DKIM key {$keyPath}.", 'status' => 1];
        }

        $generated = $this->runGenrsa($keyPath, $bits);
        if (! $generated['ok']) {
            @rename($backupPath, $keyPath);

            return $generated;
        }

        @unlink($backupPath);

        return $generated;
    }

    private function testKeys(): array
    {
        $command = $this->testkeysCommand();
        if ($command === '') {
            return ['configured' => false, 'ok' => false, 'message' => 'amavisd testkeys command is not configured.'];
        }

        return $this->runConfiguredCommand($command);
    }

    private function restartAmavisd(): array
    {
        $command = $this->restartCommand();
        if ($command === '') {
            return ['configured' => false, 'ok' => false, 'message' => 'Amavisd restart command is not configured.'];
        }

        return $this->runConfiguredCommand($command);
    }

    private function secureKeyFile(string $keyPath): void
    {
        clearstatcache(true, $keyPath);

        if (($this->fileMode($keyPath) & 0777) !== 0400) {
            @chmod($keyPath, 0400);
            clearstatcache(true, $keyPath);
        }

        $owner = $this->keyOwner();
        $group = $this->keyGroup();

        if ($owner !== '' && ! $this->fileOwnerMatches($keyPath, $owner) && function_exists('chown')) {
            @chown($keyPath, $owner);
            clearstatcache(true, $keyPath);
        }
        if ($group !== '' && ! $this->fileGroupMatches($keyPath, $group) && function_exists('chgrp')) {
            @chgrp($keyPath, $group);
            clearstatcache(true, $keyPath);
        }

        if (($this->fileMode($keyPath) & 0777) !== 0400 && $this->chmodCommand() !== '') {
            $this->runConfiguredCommand($this->chmodCommand(), ['0400', $keyPath]);
            clearstatcache(true, $keyPath);
        }

        if (! $this->keyOwnershipMatches($keyPath, $owner, $group) && $this->chownCommand() !== '') {
            $ownerGroup = $group !== '' ? "{$owner}:{$group}" : $owner;
            $this->runConfiguredCommand($this->chownCommand(), [$ownerGroup, $keyPath]);
            clearstatcache(true, $keyPath);
        }

        if (($this->fileMode($keyPath) & 0777) !== 0400) {
            throw ValidationException::withMessages(['dkim' => "Cannot chmod {$keyPath} to 0400."]);
        }
    }

    private function keyOwnershipMatches(string $path, string $owner, string $group): bool
    {
        return ($owner === '' || $this->fileOwnerMatches($path, $owner))
            && ($group === '' || $this->fileGroupMatches($path, $group));
    }

    private function fileMode(string $path): int
    {
        $perms = @fileperms($path);

        return $perms === false ? 0 : $perms;
    }

    private function fileOwnerMatches(string $path, string $owner): bool
    {
        if (! function_exists('posix_getpwuid')) {
            return false;
        }

        $current = @fileowner($path);
        if ($current === false) {
            return false;
        }

        $info = posix_getpwuid($current);

        return is_array($info) && ($info['name'] ?? '') === $owner;
    }

    private function fileGroupMatches(string $path, string $group): bool
    {
        if (! function_exists('posix_getgrgid')) {
            return false;
        }

        $current = @filegroup($path);
        if ($current === false) {
            return false;
        }

        $info = posix_getgrgid($current);

        return is_array($info) && ($info['name'] ?? '') === $group;
    }

    private function withFileLock(string $targetPath, Closure $callback): mixed
    {
        $lockDirectory = storage_path('framework/locks');
        if (! is_dir($lockDirectory) && @mkdir($lockDirectory, 0755, true) === false) {
            throw ValidationException::withMessages(['dkim' => "Cannot create lock directory {$lockDirectory}."]);
        }

        $lockPath = $lockDirectory.'/dkim-'.sha1($targetPath).'.lock';
        $handle = @fopen($lockPath, 'c');
        if (! $handle) {
            throw ValidationException::withMessages(['dkim' => "Cannot open lock file {$lockPath}."]);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw ValidationException::withMessages(['dkim' => "Cannot acquire lock for {$targetPath}."]);
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function runConfiguredCommand(string $command, array $extraArguments = []): array
    {
        $arguments = $this->commandArguments($command);
        if ($arguments === []) {
            return ['configured' => false, 'ok' => false, 'message' => 'Command is not configured.', 'status' => 127];
        }

        $process = new Process(array_merge($arguments, $extraArguments));
        $process->setTimeout(30);
        $process->run();

        return [
            'configured' => true,
            'ok' => $process->isSuccessful(),
            'message' => trim($process->getOutput()."\n".$process->getErrorOutput()),
            'status' => $process->getExitCode(),
        ];
    }

    private function commandArguments(string $command): array
    {
        $arguments = array_values(array_filter(str_getcsv($command, ' ', '"', '\\'), fn (string $argument) => $argument !== ''));

        return array_map('trim', $arguments);
    }

    private function configWritable(): bool
    {
        $path = $this->configPath();

        return is_file($path) ? is_writable($path) : is_writable(dirname($path));
    }

    private function configPath(): string
    {
        return (string) config('iredmail.amavisd_config_path');
    }

    private function keyPath(string $domain): string
    {
        return rtrim((string) config('iredmail.amavisd_dkim_directory'), '/').'/'.$domain.'.pem';
    }

    private function dnsName(string $domain): string
    {
        return $this->selector().'._domainkey.'.$domain;
    }

    private function selector(): string
    {
        $selector = strtolower(trim((string) config('iredmail.amavisd_dkim_selector')));

        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $selector) ? $selector : 'mxcentral';
    }

    private function bits(): int
    {
        return $this->validBits((int) config('iredmail.amavisd_dkim_bits'));
    }

    private function validBits(int $bits): int
    {
        return in_array($bits, [1024, 2048], true) ? $bits : 1024;
    }

    private function genrsaCommand(): string
    {
        return trim((string) config('iredmail.amavisd_genrsa_command'));
    }

    private function testkeysCommand(): string
    {
        return trim((string) config('iredmail.amavisd_testkeys_command'));
    }

    private function showkeysCommand(): string
    {
        return trim((string) config('iredmail.amavisd_showkeys_command'));
    }

    private function restartCommand(): string
    {
        return trim((string) config('iredmail.amavisd_restart_command'));
    }

    private function keyOwner(): string
    {
        return trim((string) config('iredmail.amavisd_dkim_key_owner'));
    }

    private function keyGroup(): string
    {
        return trim((string) config('iredmail.amavisd_dkim_key_group'));
    }

    private function chownCommand(): string
    {
        return trim((string) config('iredmail.amavisd_dkim_chown_command'));
    }

    private function chmodCommand(): string
    {
        return trim((string) config('iredmail.amavisd_dkim_chmod_command'));
    }

    private function perlSingleQuoted(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }
}
