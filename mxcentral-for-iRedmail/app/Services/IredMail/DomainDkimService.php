<?php

namespace App\Services\IredMail;

use App\Support\IredMailAddress;
use App\Support\PrivilegedConfigurationLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DomainDkimService
{
    private const BEGIN_MARKER = '# BEGIN mxcentral-for-iRedmail managed DKIM keys';

    private const END_MARKER = '# END mxcentral-for-iRedmail managed DKIM keys';

    private readonly PrivilegedHelper $privileged;

    public function __construct(private readonly AuditLogger $audit, ?PrivilegedHelper $privileged = null)
    {
        $this->privileged = $privileged ?? app(PrivilegedHelper::class);
    }

    public function status(CurrentActor $actor, string $domain, bool $lookupDns = false): array
    {
        $domain = $this->validatedHostedDomain($actor, $domain);
        $keyPath = $this->keyPath($domain);
        $expected = $this->expectedDnsRecord($domain);
        $keyStatus = $this->privileged->run('dkim_status', ['domain' => $domain]);
        $keyExists = ($keyStatus['ok'] ?? false) && ($keyStatus['data']['exists'] ?? false);

        return [
            'domain' => $domain,
            'selector' => $this->selector(),
            'dns_name' => $this->dnsName($domain),
            'key_path' => $keyPath,
            'config_path' => $this->configPath(),
            'key_exists' => $keyExists,
            'key_readable' => $keyExists,
            'config_readable' => $this->privileged->configured(),
            'config_writable' => $this->privileged->configured(),
            'configured' => $this->domainConfigured($domain),
            'genrsa_configured' => $this->privileged->configured(),
            'showkeys_configured' => $this->privileged->configured(),
            'restart_configured' => $this->privileged->configured(),
            'testkeys_configured' => $this->privileged->configured(),
            'expected_txt' => $expected['txt'],
            'expected_chunks' => $expected['chunks'],
            'dns' => $lookupDns ? $this->dnsStatus($domain) : null,
        ];
    }

    public function generate(CurrentActor $actor, string $domain, int $bits = 1024): array
    {
        abort_unless($actor->globalAdmin, 403);
        $domain = $this->validatedHostedDomain($actor, $domain);
        $bits = $this->validBits($bits);

        return PrivilegedConfigurationLock::run(function () use ($actor, $domain, $bits): array {
            $keyPath = $this->keyPath($domain);
            $original = $this->readAmavisdConfig();
            $domains = $this->configuredManagedDomains($original);
            $domains[$domain] = true;
            ksort($domains);
            $updated = $this->replaceManagedBlock($original, array_keys($domains));
            $generated = $this->privileged->run('dkim_apply', [
                'action' => 'generate',
                'domain' => $domain,
                'bits' => $bits,
                'amavis_content' => $updated,
            ]);
            if (! $generated['ok']) {
                throw ValidationException::withMessages(['dkim' => 'DKIM key/config transaction failed and was rolled back: '.$generated['message']]);
            }

            $rotated = (bool) ($generated['data']['key']['rotated'] ?? false);
            $changed = $updated !== $original;
            $restart = [
                'configured' => true,
                'ok' => true,
                'message' => 'Applied in DKIM transaction '.($generated['data']['operation_id'] ?? '').'.',
            ];
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
        });
    }

    public function cleanupRemovedDomain(CurrentActor $actor, string $domain): array
    {
        abort_unless($actor->globalAdmin, 403);
        $domain = IredMailAddress::domain($domain) ?? abort(404);

        return PrivilegedConfigurationLock::run(function () use ($domain): array {
            $original = $this->readAmavisdConfig();
            $domains = $this->configuredManagedDomains($original);
            $configured = isset($domains[$domain]);
            unset($domains[$domain]);
            ksort($domains);
            $updated = $this->replaceManagedBlock($original, array_keys($domains));
            $applied = $this->privileged->run('dkim_apply', [
                'action' => 'delete',
                'domain' => $domain,
                'amavis_content' => $updated,
            ]);
            if (! $applied['ok']) {
                throw ValidationException::withMessages(['dkim' => 'DKIM cleanup transaction failed and was rolled back: '.$applied['message']]);
            }
            $config = [
                'changed' => $updated !== $original,
                'path' => $this->configPath(),
                'message' => $configured ? "Removed {$domain} from the mxcentral-managed DKIM block." : 'No amavisd config change needed.',
            ];
            $keys = [
                'deleted' => $applied['data']['key']['deleted'] ?? [],
                'path' => $this->keyPath($domain),
            ];
            $restart = [
                'configured' => true,
                'ok' => true,
                'message' => 'Applied in DKIM transaction '.($applied['data']['operation_id'] ?? '').'.',
            ];

            $this->audit->log('delete', "Cleaned up DKIM config and key files for deleted domain {$domain}.", $domain);

            return [
                'domain' => $domain,
                'config' => $config,
                'keys' => $keys,
                'restart' => $restart,
            ];
        });
    }

    public function checkDns(CurrentActor $actor, string $domain): array
    {
        $domain = $this->validatedHostedDomain($actor, $domain);
        $status = $this->status($actor, $domain, true);
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
            return preg_replace_callback($pattern, fn (): string => $block."\n", $content, 1) ?? $content;
        }

        return rtrim($content)."\n\n".$block."\n";
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
        try {
            $content = $this->readAmavisdConfig();
        } catch (ValidationException) {
            return false;
        }

        return isset($this->configuredManagedDomains($content)[$domain]);
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
        $result = $this->privileged->run('amavis_showkeys');
        if (! $result['ok']) {
            return ['txt' => null, 'chunks' => []];
        }

        $record = $this->extractShowkeysRecord((string) ($result['data']['output'] ?? ''), $this->dnsName($domain));
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

    private function testKeys(): array
    {
        return $this->privileged->run('amavis_testkeys');
    }

    private function readAmavisdConfig(): string
    {
        $result = $this->privileged->run('read_file', ['target' => 'amavis_config']);
        if (! $result['ok']) {
            throw ValidationException::withMessages(['dkim' => 'Cannot read amavisd configuration through the privileged helper: '.$result['message']]);
        }

        return (string) ($result['data']['content'] ?? '');
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

    private function validBits(int $bits): int
    {
        return in_array($bits, [1024, 2048], true) ? $bits : 1024;
    }

    private function perlSingleQuoted(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
