<?php

namespace App\Services\IredMail;

use App\Support\IredMailAddress;
use Illuminate\Support\Facades\DB;

final class DomainDnsService
{
    public function __construct(private readonly DomainDkimService $dkim)
    {
    }

    public function status(CurrentActor $actor, string $domain): array
    {
        $domain = $this->validatedHostedDomain($actor, $domain);
        $dkimStatus = $this->dkim->status($actor, $domain);
        $mx = $this->mxStatus($domain);
        $spfRecords = $this->prefixedTxtRecords($domain, 'v=spf1');
        $spf = $this->spfStatus($domain, $spfRecords);
        $dmarcName = '_dmarc.'.$domain;
        $dmarcRecords = $this->prefixedTxtRecords($dmarcName, 'v=DMARC1');
        $dmarc = $this->dmarcStatus($domain, $dmarcRecords);

        return [
            'domain' => $domain,
            'checked_at' => now()->toDateTimeString(),
            'dkim' => [
                'name' => $dkimStatus['dns_name'],
                'records' => $dkimStatus['dns']['records'],
                'expected' => $dkimStatus['expected_txt'],
                'ok' => $dkimStatus['dns']['match'],
                'label' => $dkimStatus['dns']['match'] ? 'OK' : ($dkimStatus['expected_txt'] ? 'Pending' : 'No key'),
            ],
            'mx' => [
                'name' => $domain,
                'records' => $mx['records'],
                'ok' => $mx['ok'],
                'label' => $mx['label'],
                'details' => $mx['details'],
                'targets' => $mx['targets'],
            ],
            'spf' => [
                'name' => $domain,
                'records' => $spfRecords,
                'ok' => $spf['ok'],
                'label' => $spf['label'],
                'details' => $spf['details'],
                'targets' => $spf['targets'],
            ],
            'dmarc' => [
                'name' => $dmarcName,
                'records' => $dmarcRecords,
                'ok' => $dmarc['ok'],
                'label' => $dmarc['label'],
                'details' => $dmarc['details'],
                'external_reports' => $dmarc['external_reports'],
            ],
        ];
    }

    public function summary(CurrentActor $actor, string $domain): string
    {
        $status = $this->status($actor, $domain);

        return sprintf(
            'DNS check complete for %s: DKIM %s, MX %s, SPF %s, DMARC %s.',
            $status['domain'],
            $status['dkim']['label'],
            $status['mx']['label'],
            $status['spf']['label'],
            $status['dmarc']['label'],
        );
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

    private function prefixedTxtRecords(string $name, string $prefix): array
    {
        return array_values(array_filter(
            $this->txtRecords($name),
            fn (string $record): bool => str_starts_with(strtolower($record), strtolower($prefix)),
        ));
    }

    private function spfStatus(string $domain, array $records): array
    {
        $targets = $this->spfTargets();
        if (count($records) !== 1) {
            return [
                'ok' => false,
                'label' => $this->singleRecordLabel($records),
                'details' => count($targets['ips']) === 0 ? 'No server IP configured.' : null,
                'targets' => $targets,
            ];
        }

        if (count($targets['ips']) === 0) {
            return [
                'ok' => false,
                'label' => 'No server IP',
                'details' => 'Set IREDMAIL_SPF_SERVER_IPS to the public outbound mail IP address, then run php artisan optimize:clear.',
                'targets' => $targets,
            ];
        }

        $evaluation = $this->spfAuthorizes($domain, $targets['ips']);

        return [
            'ok' => $evaluation['authorized'],
            'label' => $evaluation['authorized'] ? 'OK' : 'Server not included',
            'details' => $evaluation['message'],
            'targets' => $targets,
        ];
    }

    private function mxStatus(string $domain): array
    {
        $targets = $this->spfTargets();
        $records = $this->mxRecords($domain);

        if ($records === []) {
            return [
                'ok' => false,
                'label' => 'Missing',
                'details' => 'No MX records found.',
                'records' => [],
                'targets' => $targets,
            ];
        }

        if ($targets['hostname'] === '' && $targets['ips'] === []) {
            return [
                'ok' => false,
                'label' => 'No server target',
                'details' => 'Set IREDMAIL_SPF_SERVER_HOSTNAME or IREDMAIL_SPF_SERVER_IPS to check MX records.',
                'records' => $records,
                'targets' => $targets,
            ];
        }

        $expectedHostname = rtrim(strtolower($targets['hostname']), '.');
        foreach ($records as $record) {
            $mxHost = rtrim(strtolower($record['target']), '.');
            if ($expectedHostname !== '' && $mxHost === $expectedHostname) {
                return [
                    'ok' => true,
                    'label' => 'OK',
                    'details' => "MX points at {$record['target']}.",
                    'records' => $records,
                    'targets' => $targets,
                ];
            }

            foreach ($this->hostIps($mxHost) as $ip) {
                if (in_array($ip, $targets['ips'], true)) {
                    return [
                        'ok' => true,
                        'label' => 'OK',
                        'details' => "MX {$record['target']} resolves to server IP {$ip}.",
                        'records' => $records,
                        'targets' => $targets,
                    ];
                }
            }
        }

        return [
            'ok' => false,
            'label' => 'Not this server',
            'details' => 'No MX host points at the configured server hostname or public IP.',
            'records' => $records,
            'targets' => $targets,
        ];
    }

    private function spfTargets(): array
    {
        $hostname = trim((string) config('iredmail.spf_server_hostname'));
        $ips = array_values(array_filter(array_map(
            'trim',
            preg_split('/[,;\s]+/', (string) config('iredmail.spf_server_ips')) ?: [],
        ), fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false));

        if ($hostname !== '') {
            $ips = array_merge($ips, $this->hostIps($hostname));
        }

        return [
            'hostname' => $hostname,
            'ips' => array_values(array_unique($ips)),
        ];
    }

    private function dmarcStatus(string $domain, array $records): array
    {
        if (count($records) !== 1) {
            return [
                'ok' => false,
                'label' => $this->singleRecordLabel($records),
                'details' => null,
                'external_reports' => [],
            ];
        }

        $externalReports = $this->dmarcExternalReports($domain, $records[0]);
        $missing = array_values(array_filter($externalReports, fn (array $report): bool => ! $report['ok']));

        if ($missing !== []) {
            return [
                'ok' => false,
                'label' => 'External auth missing',
                'details' => 'One or more external DMARC report destinations has not published the required authorization TXT record.',
                'external_reports' => $externalReports,
            ];
        }

        return [
            'ok' => true,
            'label' => 'OK',
            'details' => $externalReports === [] ? 'No external report destinations.' : 'External report destinations are authorized.',
            'external_reports' => $externalReports,
        ];
    }

    private function dmarcExternalReports(string $domain, string $record): array
    {
        $tags = $this->dmarcTags($record);
        $reports = [];

        foreach (['rua', 'ruf'] as $tag) {
            foreach ($this->dmarcReportDomains($tags[$tag] ?? '') as $reportDomain) {
                if ($reportDomain === $domain) {
                    continue;
                }

                $name = $domain.'._report._dmarc.'.$reportDomain;
                $records = $this->prefixedTxtRecords($name, 'v=DMARC1');
                $key = $tag.'|'.$reportDomain;
                $reports[$key] = [
                    'tag' => $tag,
                    'domain' => $reportDomain,
                    'name' => $name,
                    'records' => $records,
                    'ok' => count($records) >= 1,
                    'label' => count($records) >= 1 ? 'OK' : 'Missing',
                ];
            }
        }

        return array_values($reports);
    }

    private function dmarcTags(string $record): array
    {
        $tags = [];
        foreach (explode(';', $record) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $name = strtolower(trim($name));
            if ($name !== '') {
                $tags[$name] = trim($value);
            }
        }

        return $tags;
    }

    private function dmarcReportDomains(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $domains = [];
        foreach (explode(',', $value) as $uri) {
            $uri = trim($uri);
            if (! str_starts_with(strtolower($uri), 'mailto:')) {
                continue;
            }

            $address = substr($uri, 7);
            $address = preg_replace('/!.+$/', '', $address) ?? $address;
            $domain = IredMailAddress::domainOf($address);
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    private function spfAuthorizes(string $domain, array $targetIps, int $depth = 0, array $seen = []): array
    {
        if ($depth > 10) {
            return ['authorized' => false, 'message' => 'SPF include/redirect depth limit reached.'];
        }

        $domain = strtolower($domain);
        if (isset($seen[$domain])) {
            return ['authorized' => false, 'message' => "SPF loop detected at {$domain}."];
        }
        $seen[$domain] = true;

        $records = $this->prefixedTxtRecords($domain, 'v=spf1');
        if (count($records) !== 1) {
            return ['authorized' => false, 'message' => "{$domain} has ".$this->singleRecordLabel($records).' SPF records.'];
        }

        $redirect = null;
        foreach (preg_split('/\s+/', trim($records[0])) ?: [] as $term) {
            if ($term === '' || str_starts_with(strtolower($term), 'v=spf1')) {
                continue;
            }

            $qualifier = '+';
            if (in_array($term[0], ['+', '-', '~', '?'], true)) {
                $qualifier = $term[0];
                $term = substr($term, 1);
            }

            if (str_starts_with(strtolower($term), 'redirect=')) {
                $redirect = substr($term, 9);
                continue;
            }

            if ($qualifier === '-') {
                continue;
            }

            $match = $this->spfTermMatches($term, $domain, $targetIps, $depth, $seen);
            if ($match['matched']) {
                return ['authorized' => true, 'message' => $match['message']];
            }
        }

        if ($redirect) {
            return $this->spfAuthorizes($redirect, $targetIps, $depth + 1, $seen);
        }

        return ['authorized' => false, 'message' => 'No SPF mechanism authorizes the configured server IP.'];
    }

    private function spfTermMatches(string $term, string $domain, array $targetIps, int $depth, array $seen): array
    {
        $lower = strtolower($term);

        if (str_starts_with($lower, 'ip4:') || str_starts_with($lower, 'ip6:')) {
            $range = substr($term, 4);
            foreach ($targetIps as $ip) {
                if ($this->ipMatchesCidr($ip, $range)) {
                    return ['matched' => true, 'message' => "Server IP {$ip} is authorized by {$term}."];
                }
            }
        }

        if (str_starts_with($lower, 'include:')) {
            $includeDomain = substr($term, 8);
            $result = $this->spfAuthorizes($includeDomain, $targetIps, $depth + 1, $seen);
            if ($result['authorized']) {
                return ['matched' => true, 'message' => "Server is authorized via include:{$includeDomain}. ".$result['message']];
            }
        }

        if ($lower === 'a' || str_starts_with($lower, 'a:') || str_starts_with($lower, 'a/')) {
            $host = $this->spfMechanismDomain($term, 'a', $domain);
            if ($match = $this->firstMatchingHostIp($host, $targetIps)) {
                return ['matched' => true, 'message' => "Server IP {$match} is authorized by {$term}."];
            }
        }

        if ($lower === 'mx' || str_starts_with($lower, 'mx:') || str_starts_with($lower, 'mx/')) {
            $host = $this->spfMechanismDomain($term, 'mx', $domain);
            foreach ($this->mxHosts($host) as $mxHost) {
                if ($match = $this->firstMatchingHostIp($mxHost, $targetIps)) {
                    return ['matched' => true, 'message' => "Server IP {$match} is authorized by {$term} through MX {$mxHost}."];
                }
            }
        }

        return ['matched' => false, 'message' => null];
    }

    private function spfMechanismDomain(string $term, string $mechanism, string $default): string
    {
        $value = substr($term, strlen($mechanism));
        if (str_starts_with($value, ':')) {
            $value = substr($value, 1);
            $value = preg_replace('/\/.*$/', '', $value) ?? '';

            return $value !== '' ? $value : $default;
        }

        return $default;
    }

    private function firstMatchingHostIp(string $host, array $targetIps): ?string
    {
        foreach ($this->hostIps($host) as $ip) {
            if (in_array($ip, $targetIps, true)) {
                return $ip;
            }
        }

        return null;
    }

    private function hostIps(string $host): array
    {
        $ips = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($host, $type);
            if ($records === false) {
                continue;
            }

            foreach ($records as $record) {
                $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $ips[] = $ip;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function mxHosts(string $domain): array
    {
        return array_values(array_unique(array_map(
            fn (array $record): string => rtrim((string) ($record['target'] ?? ''), '.'),
            $this->mxRecords($domain),
        )));
    }

    private function mxRecords(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_MX);
        if ($records === false) {
            return [];
        }

        $records = array_values(array_filter($records, fn (array $record): bool => ! empty($record['target'])));
        usort($records, fn (array $a, array $b): int => ((int) ($a['pri'] ?? 0)) <=> ((int) ($b['pri'] ?? 0)));

        return array_map(fn (array $record): array => [
            'priority' => (int) ($record['pri'] ?? 0),
            'target' => rtrim((string) $record['target'], '.'),
        ], $records);
    }

    private function ipMatchesCidr(string $ip, string $range): bool
    {
        [$network, $prefix] = array_pad(explode('/', $range, 2), 2, null);
        $ipBinary = @inet_pton($ip);
        $networkBinary = @inet_pton($network);
        if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
            return false;
        }

        $bits = strlen($ipBinary) * 8;
        $prefixLength = $prefix === null ? $bits : (int) $prefix;
        if ($prefixLength < 0 || $prefixLength > $bits) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;

        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
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

    private function singleRecordLabel(array $records): string
    {
        return match (count($records)) {
            0 => 'Missing',
            1 => 'OK',
            default => 'Multiple records',
        };
    }
}
