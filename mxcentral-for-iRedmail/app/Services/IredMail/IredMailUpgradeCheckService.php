<?php

namespace App\Services\IredMail;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class IredMailUpgradeCheckService
{
    public function check(bool $dryRun = false, bool $notify = true): array
    {
        $previous = $this->status();
        $state = [
            'checked_at' => now()->toIso8601String(),
            'status' => 'ok',
            'error' => '',
            'last_notified_iredmail_version' => $previous['last_notified_iredmail_version'] ?? '',
            'last_notified_iredapd_version' => $previous['last_notified_iredapd_version'] ?? '',
        ];

        try {
            $iredmailReleasesHtml = $this->get((string) config('iredmail.upgrade_releases_url'));
            $iredmailDownloadHtml = $this->get((string) config('iredmail.upgrade_download_url'));

            $installedIredMail = $this->installedIredMailVersion();
            $latestIredMail = $this->latestIredMailVersion($iredmailDownloadHtml, $iredmailReleasesHtml);
            $iredMailUpgradePath = $this->upgradePath($installedIredMail, $latestIredMail, $iredmailReleasesHtml);

            $installedIredApd = $this->installedIredApdVersion();
            $latestIredApd = $this->latestIredApdVersion();

            $state += [
                'iredmail' => [
                    'installed' => $installedIredMail,
                    'latest' => $latestIredMail,
                    'upgrade_available' => $this->upgradeAvailable($installedIredMail, $latestIredMail),
                    'release_notes_url' => (string) config('iredmail.upgrade_releases_url'),
                    'download_url' => (string) config('iredmail.upgrade_download_url'),
                    'upgrade_path' => $iredMailUpgradePath,
                    'installed_version_path' => (string) config('iredmail.iredmail_release_path'),
                ],
                'iredapd' => [
                    'installed' => $installedIredApd,
                    'latest' => $latestIredApd,
                    'upgrade_available' => $this->upgradeAvailable($installedIredApd, $latestIredApd),
                    'tags_url' => (string) config('iredmail.iredapd_tags_url'),
                    'installed_version_path' => (string) config('iredmail.iredapd_version_file'),
                ],
            ];

            $state['notification'] = $this->notificationState($state, $dryRun, $notify);
            $this->saveStatus($state);
        } catch (\Throwable $exception) {
            report($exception);
            $state = $previous;
            $state['checked_at'] = now()->toIso8601String();
            $state['status'] = 'failed';
            $state['error'] = $exception->getMessage();
            $this->saveStatus($state);
        }

        return $state;
    }

    public function status(): array
    {
        $path = $this->statePath();
        if (! is_readable($path)) {
            return [
                'checked_at' => '',
                'status' => 'never',
                'error' => '',
                'last_notified_iredmail_version' => '',
                'last_notified_iredapd_version' => '',
                'iredmail' => [
                    'installed' => $this->installedIredMailVersion(),
                    'latest' => '',
                    'upgrade_available' => false,
                    'release_notes_url' => (string) config('iredmail.upgrade_releases_url'),
                    'download_url' => (string) config('iredmail.upgrade_download_url'),
                    'upgrade_path' => [],
                    'installed_version_path' => (string) config('iredmail.iredmail_release_path'),
                ],
                'iredapd' => [
                    'installed' => $this->installedIredApdVersion(),
                    'latest' => '',
                    'upgrade_available' => false,
                    'tags_url' => (string) config('iredmail.iredapd_tags_url'),
                    'installed_version_path' => (string) config('iredmail.iredapd_version_file'),
                ],
                'notification' => [
                    'sent' => false,
                    'recipients' => [],
                    'failed' => [],
                    'dry_run' => false,
                    'reason' => 'No upgrade check has run yet.',
                ],
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function notificationState(array &$state, bool $dryRun, bool $notify): array
    {
        if (! $notify) {
            return ['sent' => false, 'recipients' => [], 'failed' => [], 'dry_run' => $dryRun, 'reason' => 'Notifications disabled for this run.'];
        }

        $iredmailTarget = (string) ($state['iredmail']['latest'] ?? '');
        $iredapdTarget = (string) ($state['iredapd']['latest'] ?? '');
        $newIredMail = ($state['iredmail']['upgrade_available'] ?? false)
            && $iredmailTarget !== ''
            && $iredmailTarget !== (string) ($state['last_notified_iredmail_version'] ?? '');
        $newIredApd = ($state['iredapd']['upgrade_available'] ?? false)
            && $iredapdTarget !== ''
            && $iredapdTarget !== (string) ($state['last_notified_iredapd_version'] ?? '');

        if (! $newIredMail && ! $newIredApd) {
            return ['sent' => false, 'recipients' => [], 'failed' => [], 'dry_run' => $dryRun, 'reason' => 'No newly detected upgrade target.'];
        }

        $recipients = $this->adminRecipients();
        if ($recipients === []) {
            return ['sent' => false, 'recipients' => [], 'failed' => [], 'dry_run' => $dryRun, 'reason' => 'No global admin recipients found.'];
        }

        $failed = [];
        foreach ($recipients as $recipient) {
            try {
                if (! $dryRun) {
                    Mail::html($this->notificationHtml($state), function ($mail) use ($recipient): void {
                        $mail->to($recipient)
                            ->subject($this->notificationSubject());
                    });
                }
            } catch (\Throwable $exception) {
                report($exception);
                $failed[$recipient] = $exception->getMessage();
            }
        }

        if (! $dryRun && $failed === []) {
            if ($newIredMail) {
                $state['last_notified_iredmail_version'] = $iredmailTarget;
            }
            if ($newIredApd) {
                $state['last_notified_iredapd_version'] = $iredapdTarget;
            }
        }

        return [
            'sent' => ! $dryRun && $failed === [],
            'recipients' => $recipients,
            'failed' => $failed,
            'dry_run' => $dryRun,
            'reason' => $failed === [] ? 'Notification sent for new upgrade target.' : 'Some notifications failed.',
        ];
    }

    private function get(string $url): string
    {
        $response = Http::timeout((int) config('iredmail.upgrade_check_timeout', 15))
            ->accept('text/html,application/json')
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("Cannot fetch {$url}: HTTP ".$response->status());
        }

        return $response->body();
    }

    private function installedIredMailVersion(): string
    {
        $path = (string) config('iredmail.iredmail_release_path');
        if (! is_readable($path)) {
            return '';
        }

        return $this->normalizeVersion((string) strtok((string) file_get_contents($path), "\r\n"));
    }

    private function installedIredApdVersion(): string
    {
        $path = (string) config('iredmail.iredapd_version_file');
        if (! is_readable($path)) {
            return '';
        }

        $content = (string) file_get_contents($path);
        if (preg_match('/__version__\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $match)) {
            return $this->normalizeVersion($match[1]);
        }

        return '';
    }

    private function latestIredMailVersion(string $downloadHtml, string $releasesHtml): string
    {
        if (preg_match('/Stable\s+v?([0-9]+(?:\.[0-9]+)+(?:-[0-9]+)?)/i', $downloadHtml, $match)) {
            return $this->normalizeVersion($match[1]);
        }

        if (preg_match('/>\s*v?([0-9]+(?:\.[0-9]+)+(?:-[0-9]+)?)\s*</', $releasesHtml, $match)) {
            return $this->normalizeVersion($match[1]);
        }

        return '';
    }

    private function latestIredApdVersion(): string
    {
        $body = $this->get((string) config('iredmail.iredapd_tags_api_url'));
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            foreach ($decoded as $tag) {
                if (is_array($tag) && isset($tag['name'])) {
                    return $this->normalizeVersion((string) $tag['name']);
                }
            }
        }

        return '';
    }

    private function upgradePath(string $installed, string $latest, string $html): array
    {
        if ($installed === '' || $latest === '' || ! $this->upgradeAvailable($installed, $latest)) {
            return [];
        }

        $edges = $this->upgradeEdges($html);
        $path = [];
        $current = $installed;
        $seen = [];

        while ($current !== $latest && ! isset($seen[$current])) {
            $seen[$current] = true;
            if (! isset($edges[$current])) {
                break;
            }

            $step = $edges[$current];
            $path[] = $step;
            $current = $step['to'];
        }

        return $path;
    }

    private function upgradeEdges(string $html): array
    {
        preg_match_all('/href=["\']([^"\']*upgrade\.iredmail\.([0-9][0-9A-Za-z.\-]*)-([0-9][0-9A-Za-z.\-]*)\.html)["\']/i', $html, $matches, PREG_SET_ORDER);
        $edges = [];

        foreach ($matches as $match) {
            $from = $this->normalizeVersion($match[2]);
            $to = $this->normalizeVersion($match[3]);
            if ($from === '' || $to === '') {
                continue;
            }

            $edges[$from] = [
                'from' => $from,
                'to' => $to,
                'url' => $this->absoluteDocsUrl($match[1]),
            ];
        }

        return $edges;
    }

    private function absoluteDocsUrl(string $href): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return rtrim((string) config('iredmail.upgrade_docs_base_url'), '/').'/'.ltrim($href, './');
    }

    private function upgradeAvailable(string $installed, string $latest): bool
    {
        return $installed !== '' && $latest !== '' && version_compare($latest, $installed, '>');
    }

    private function normalizeVersion(string $version): string
    {
        return trim(Str::lower(preg_replace('/^v/i', '', trim($version)) ?? ''));
    }

    private function adminRecipients(): array
    {
        $recipients = [];

        try {
            $domainAdmins = DB::connection('vmail')->table('domain_admins')
                ->whereRaw('LOWER(domain) = ?', ['all'])
                ->where('active', 1)
                ->pluck('username')
                ->all();
            array_push($recipients, ...$domainAdmins);
        } catch (\Throwable $exception) {
            report($exception);
        }

        try {
            $mailboxAdmins = DB::connection('vmail')->table('mailbox')
                ->where('isglobaladmin', 1)
                ->where('active', 1)
                ->pluck('username')
                ->all();
            array_push($recipients, ...$mailboxAdmins);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($email) => strtolower(trim((string) $email)), $recipients),
            fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        )));
    }

    private function notificationSubject(): string
    {
        return (string) config('iredmail.upgrade_notification_subject', '[Attention] iRedMail upgrade available');
    }

    private function notificationHtml(array $state): string
    {
        $settingsUrl = route('system.settings', absolute: true);
        $hostname = gethostname() ?: php_uname('n');
        $iredmail = $state['iredmail'];
        $iredapd = $state['iredapd'];

        $steps = collect($iredmail['upgrade_path'] ?? [])->map(function (array $step): string {
            return '<li><a href="'.$this->e((string) $step['url']).'">Upgrade iRedMail '.$this->e((string) $step['from']).' to '.$this->e((string) $step['to']).'</a></li>';
        })->implode('');

        $steps = $steps !== '' ? '<ol>'.$steps.'</ol>' : '<p>No sequential iRedMail upgrade tutorial path was detected. Check the release notes before upgrading.</p>';

        return '<!doctype html><html><body>'
            .'<p>An iRedMail upgrade is available for '.$this->e($hostname).'.</p>'
            .'<table style="border-collapse:collapse;width:100%;max-width:720px;">'
            .$this->row('iRedMail installed', (string) ($iredmail['installed'] ?? ''))
            .$this->row('iRedMail latest', (string) ($iredmail['latest'] ?? ''))
            .$this->row('iRedAPD installed', (string) ($iredapd['installed'] ?? ''))
            .$this->row('iRedAPD latest', (string) ($iredapd['latest'] ?? ''))
            .'</table>'
            .'<p>iRedMail upgrade docs say not to skip releases. Apply the upgrade tutorials sequentially.</p>'
            .$steps
            .'<p><a href="'.$this->e((string) ($iredmail['release_notes_url'] ?? '')).'">iRedMail release notes</a></p>'
            .'<p><a href="'.$this->e($settingsUrl).'">Open MXCentral system settings</a></p>'
            .'</body></html>';
    }

    private function row(string $label, string $value): string
    {
        return '<tr><th align="left" style="padding:6px;border-bottom:1px solid #ddd;">'.$this->e($label).'</th>'
            .'<td style="padding:6px;border-bottom:1px solid #ddd;">'.$this->e($value !== '' ? $value : 'unknown').'</td></tr>';
    }

    private function e(string $value): string
    {
        return e(Str::limit($value, 500, '...'));
    }

    private function saveStatus(array $state): void
    {
        $path = $this->statePath();
        $directory = dirname($path);
        if (! is_dir($directory) && @mkdir($directory, 0755, true) === false) {
            throw new \RuntimeException("Cannot create {$directory}.");
        }

        if (@file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write {$path}.");
        }
    }

    private function statePath(): string
    {
        $configured = trim((string) config('iredmail.upgrade_check_state_path'));

        return $configured !== '' ? $configured : storage_path('app/iredmail-upgrade-check.json');
    }
}
