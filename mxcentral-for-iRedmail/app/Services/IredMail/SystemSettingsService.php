<?php

namespace App\Services\IredMail;

use App\Support\IredMailAddress;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

final class SystemSettingsService
{
    private const BEGIN_MARKER = '# BEGIN iredadmin-php managed: login mismatch senders';
    private const END_MARKER = '# END iredadmin-php managed: login mismatch senders';
    private const UNAUTH_BEGIN_MARKER = '# BEGIN mxcentral managed: unauthenticated senders';
    private const UNAUTH_END_MARKER = '# END mxcentral managed: unauthenticated senders';
    private const SENDER_ACCESS_BEGIN_MARKER = '# BEGIN mxcentral managed: unauthenticated senders';
    private const SENDER_ACCESS_END_MARKER = '# END mxcentral managed: unauthenticated senders';
    private const SENDER_MISMATCH_PLUGIN = 'reject_sender_login_mismatch';

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function settings(CurrentActor $actor): array
    {
        abort_unless($actor->globalAdmin, 403);

        $path = $this->settingsPath();
        $content = is_readable($path) ? (string) file_get_contents($path) : '';
        $postfixMainCfPath = $this->postfixMainCfPath();
        $postfixMainCfContent = is_readable($postfixMainCfPath) ? (string) file_get_contents($postfixMainCfPath) : '';
        $senderAccessPath = $this->postfixSenderAccessPath();

        return [
            'path' => $path,
            'restart_command_configured' => $this->restartCommand() !== '',
            'readable' => is_readable($path),
            'writable' => is_writable($path),
            'allowed_login_mismatch_senders' => $this->extractSenders($content),
            'allowed_forged_senders' => $this->extractAllowedForgedSenders($content),
            'allowed_unauthenticated_networks' => $this->extractMyNetworks($content),
            'sender_mismatch_plugin_enabled' => $this->senderMismatchPluginEnabled($content),
            'discard_recipients' => $this->discardRecipients(),
            'discard_path' => $this->discardRecipientsPath(),
            'discard_readable' => $this->discardRecipientsReadable(),
            'discard_writable' => $this->discardRecipientsWritable(),
            'postfix_main_cf_path' => $postfixMainCfPath,
            'postfix_main_cf_readable' => is_readable($postfixMainCfPath),
            'postfix_main_cf_writable' => is_writable($postfixMainCfPath),
            'postfix_sender_login_mismatch_present' => $this->postfixSenderLoginMismatchPresent($postfixMainCfContent),
            'postfix_recipient_access_configured' => $this->postfixRecipientAccessConfigured(),
            'postfix_sender_access_path' => $senderAccessPath,
            'postfix_sender_access_readable' => $this->postfixSenderAccessReadable(),
            'postfix_sender_access_writable' => $this->postfixSenderAccessWritable(),
            'postfix_sender_access_configured' => $this->postfixSenderAccessConfigured(),
            'postmap_command_configured' => $this->postmapCommand() !== '',
            'postfix_reload_command_configured' => $this->postfixReloadCommand() !== '',
            'sogo_logo_url' => $this->sogoLogoUrl(),
            'sogo_template_source' => $this->sogoTemplateSource(),
            'sogo_template_source_readable' => is_readable($this->sogoTemplateSource()),
            'sogo_template_target' => $this->sogoTemplateTarget(),
            'sogo_template_target_exists' => is_file($this->sogoTemplateTarget()),
            'sogo_template_target_readable' => is_readable($this->sogoTemplateTarget()),
            'sogo_template_target_writable' => $this->sogoTemplateTargetWritable(),
            'sogo_reload_command_configured' => $this->sogoReloadCommand() !== '',
            'hosted_mailboxes' => $this->hostedMailboxes(),
            'hosted_domains' => $this->hostedDomains(),
        ];
    }

    public function saveAllowedLoginMismatchSenders(CurrentActor $actor, string|array $value): array
    {
        abort_unless($actor->globalAdmin, 403);

        $path = $this->settingsPath();
        return $this->withFileLock($path, function () use ($path, $value) {
            if (! is_file($path) || ! is_readable($path)) {
                throw ValidationException::withMessages(['settings' => "Cannot read {$path}."]);
            }
            if (! is_writable($path)) {
                throw ValidationException::withMessages(['settings' => "Cannot write {$path}. Check file ownership or sudo helper permissions."]);
            }

            $senders = $this->normalizeHostedSenders($value);
            $original = (string) file_get_contents($path);
            $updated = $this->ensureSenderMismatchPluginEnabled($this->replaceManagedBlock($original, $senders));

            if ($updated !== $original) {
                $backup = $path.'.bak';
                if (@copy($path, $backup) === false) {
                    throw ValidationException::withMessages(['settings' => "Cannot create backup {$backup}."]);
                }

                if (@file_put_contents($path, $updated, LOCK_EX) === false) {
                    throw ValidationException::withMessages(['settings' => "Cannot write {$path}."]);
                }
            }

            $postfix = $this->ensurePostfixSenderLoginMismatchRemoved();
            $restart = $this->restartIredapd();
            $this->audit->log('update', 'Updated iredapd login mismatch senders: '.implode(', ', $senders).'.');

            return [
                'changed' => $updated !== $original,
                'postfix' => $postfix,
                'restart' => $restart,
                'senders' => $senders,
            ];
        });
    }

    public function saveUnauthenticatedSenders(CurrentActor $actor, string|array $sendersValue, string|array $networksValue): array
    {
        abort_unless($actor->globalAdmin, 403);

        $path = $this->settingsPath();
        return $this->withFileLock($path, function () use ($path, $sendersValue, $networksValue) {
            if (! is_file($path) || ! is_readable($path)) {
                throw ValidationException::withMessages(['unauthenticated_senders' => "Cannot read {$path}."]);
            }
            if (! is_writable($path)) {
                throw ValidationException::withMessages(['unauthenticated_senders' => "Cannot write {$path}. Check file ownership or sudo helper permissions."]);
            }

            $senders = $this->normalizeHostedSenders($sendersValue, 'allowed_forged_senders');
            $networks = $this->normalizeNetworks($networksValue);
            $original = (string) file_get_contents($path);
            $updated = $this->replaceUnauthenticatedSettingsBlock($original, $senders, $networks);

            if ($updated !== $original) {
                $backup = $path.'.bak';
                if (@copy($path, $backup) === false) {
                    throw ValidationException::withMessages(['unauthenticated_senders' => "Cannot create backup {$backup}."]);
                }

                if (@file_put_contents($path, $updated, LOCK_EX) === false) {
                    throw ValidationException::withMessages(['unauthenticated_senders' => "Cannot write {$path}."]);
                }
            }

            $senderAccess = $this->saveSenderAccessPcre($senders, $networks);
            $postfixHook = $this->ensurePostfixSenderAccessHook();
            $reload = ($senderAccess['changed'] || $postfixHook['changed']) ? $this->reloadPostfix() : ['configured' => true, 'ok' => true, 'message' => 'No Postfix sender access change needed.'];
            $restart = $this->restartIredapd();

            $this->audit->log('update', 'Updated unauthenticated sender allow list.');

            return [
                'changed' => $updated !== $original,
                'sender_access' => $senderAccess,
                'postfix_hook' => $postfixHook,
                'reload' => $reload,
                'restart' => $restart,
                'senders' => $senders,
                'networks' => $networks,
            ];
        });
    }

    public function saveSogoLogo(CurrentActor $actor, string $url): array
    {
        abort_unless($actor->globalAdmin, 403);

        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw ValidationException::withMessages(['sogo_logo_url' => 'Enter a valid http or https image URL.']);
        }

        $source = $this->sogoTemplateSource();
        if (! is_file($source) || ! is_readable($source)) {
            throw ValidationException::withMessages(['sogo_logo_url' => "Cannot read SOGo source template {$source}."]);
        }

        $target = $this->sogoTemplateTarget();
        return $this->withFileLock($target, function () use ($source, $target, $url) {
            $directory = dirname($target);
            if (! is_dir($directory) && @mkdir($directory, 0755, true) === false) {
                throw ValidationException::withMessages(['sogo_logo_url' => "Cannot create {$directory}."]);
            }
            if (! $this->sogoTemplateTargetWritable()) {
                throw ValidationException::withMessages(['sogo_logo_url' => "Cannot write {$target}. Check ownership or sudo helper permissions."]);
            }

            if (! is_file($target) && @copy($source, $target) === false) {
                throw ValidationException::withMessages(['sogo_logo_url' => "Cannot copy {$source} to {$target}."]);
            }

            $original = (string) file_get_contents($target);
            $updated = $this->replaceSogoLogoUrl($original, $url);
            if ($updated === $original && $this->sogoLogoUrlFromContent($original) !== $url) {
                throw ValidationException::withMessages(['sogo_logo_url' => 'Could not find the SOGo logo image tag to update.']);
            }

            if ($updated !== $original) {
                if (@copy($target, $target.'.bak') === false) {
                    throw ValidationException::withMessages(['sogo_logo_url' => "Cannot create backup {$target}.bak."]);
                }
                if (@file_put_contents($target, $updated, LOCK_EX) === false) {
                    throw ValidationException::withMessages(['sogo_logo_url' => "Cannot write {$target}."]);
                }
            }

            $reload = $this->reloadSogo();
            $this->audit->log('update', "Updated SOGo logo URL to {$url}.");

            return [
                'changed' => $updated !== $original,
                'reload' => $reload,
                'url' => $url,
            ];
        });
    }

    public function saveDiscardRecipients(CurrentActor $actor, string|array $value): array
    {
        abort_unless($actor->globalAdmin, 403);

        $path = $this->discardRecipientsPath();
        return $this->withFileLock($path, function () use ($path, $value) {
            $directory = dirname($path);
            if (! is_dir($directory)) {
                throw ValidationException::withMessages(['discard_recipients' => "Directory does not exist: {$directory}"]);
            }
            if (! $this->discardRecipientsWritable()) {
                throw ValidationException::withMessages(['discard_recipients' => "Cannot write {$path}. Check file ownership or sudo helper permissions."]);
            }

            $recipients = $this->normalizeHostedDomainRecipients($value);
            $original = is_file($path) ? (string) file_get_contents($path) : '';
            $updated = $this->discardRecipientsContent($recipients);

            if ($updated !== $original) {
                if (is_file($path) && @copy($path, $path.'.bak') === false) {
                    throw ValidationException::withMessages(['discard_recipients' => "Cannot create backup {$path}.bak."]);
                }
                if (@file_put_contents($path, $updated, LOCK_EX) === false) {
                    throw ValidationException::withMessages(['discard_recipients' => "Cannot write {$path}."]);
                }
            }

            $postmap = $this->runPostmap($path);
            $reload = $postmap['ok'] ? $this->reloadPostfix() : ['configured' => $this->postfixReloadCommand() !== '', 'ok' => false, 'message' => 'Skipped because postmap failed or is not configured.'];

            $this->audit->log('update', 'Updated Postfix discard recipients: '.implode(', ', $recipients).'.');

            return [
                'changed' => $updated !== $original,
                'postmap' => $postmap,
                'reload' => $reload,
                'recipients' => $recipients,
            ];
        });
    }

    private function settingsPath(): string
    {
        return (string) config('iredmail.iredapd_settings_path');
    }

    private function restartCommand(): string
    {
        return trim((string) config('iredmail.iredapd_restart_command'));
    }

    private function normalizeSenders(string|array $value): array
    {
        $values = is_array($value) ? $value : preg_split('/[\s,;]+/', $value);
        $senders = [];

        foreach ($values ?: [] as $raw) {
            $email = IredMailAddress::email((string) $raw);
            if (! $email) {
                if (trim((string) $raw) === '') {
                    continue;
                }
                throw ValidationException::withMessages(['allowed_login_mismatch_senders' => "Invalid email address: {$raw}"]);
            }
            $senders[] = $email;
        }

        return array_values(array_unique($senders));
    }

    private function normalizeHostedSenders(string|array $value, string $field = 'allowed_login_mismatch_senders'): array
    {
        $senders = $this->normalizeSenders($value);
        $hosted = $this->hostedMailboxSet();
        $invalid = array_values(array_diff($senders, array_keys($hosted)));
        if ($invalid !== []) {
            throw ValidationException::withMessages([
                $field => 'Only hosted mailbox accounts can be selected: '.implode(', ', $invalid),
            ]);
        }

        return $senders;
    }

    private function hostedMailboxes()
    {
        return DB::connection('vmail')->table('mailbox')
            ->select('username', 'domain', 'name', 'active')
            ->orderBy('domain')
            ->orderBy('username')
            ->get();
    }

    private function hostedMailboxSet(): array
    {
        return $this->hostedMailboxes()
            ->pluck('username')
            ->mapWithKeys(fn (string $email) => [strtolower($email) => true])
            ->all();
    }

    private function hostedDomains()
    {
        return DB::connection('vmail')->table('domain')
            ->select('domain')
            ->orderBy('domain')
            ->get();
    }

    private function hostedDomainSet(): array
    {
        return $this->hostedDomains()
            ->pluck('domain')
            ->mapWithKeys(fn (string $domain) => [strtolower($domain) => true])
            ->all();
    }

    private function normalizeHostedDomainRecipients(string|array $value): array
    {
        $recipients = $this->normalizeSenders($value);
        $hostedDomains = $this->hostedDomainSet();
        $invalid = [];

        foreach ($recipients as $recipient) {
            $domain = IredMailAddress::domainOf($recipient);
            if (! isset($hostedDomains[$domain])) {
                $invalid[] = $recipient;
            }
        }

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'discard_recipients' => 'Discard recipients must use hosted domains: '.implode(', ', $invalid),
            ]);
        }

        return $recipients;
    }

    private function normalizeNetworks(string|array $value): array
    {
        $values = is_array($value) ? $value : preg_split('/[\s,;]+/', $value);
        $networks = [];

        foreach ($values ?: [] as $raw) {
            $network = trim((string) $raw);
            if ($network === '') {
                continue;
            }

            if (! $this->validIpOrCidr($network)) {
                throw ValidationException::withMessages(['allowed_unauthenticated_networks' => "Invalid IP address or CIDR network: {$network}"]);
            }

            if (! $this->senderAccessRepresentableNetwork($network)) {
                throw ValidationException::withMessages(['allowed_unauthenticated_networks' => "Use an exact IP address or an IPv4 CIDR network on an octet boundary (/8, /16, /24, or /32): {$network}"]);
            }

            $networks[] = $network;
        }

        return array_values(array_unique($networks));
    }

    private function validIpOrCidr(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (! str_contains($value, '/')) {
            return false;
        }

        [$ip, $prefix] = explode('/', $value, 2);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || ! ctype_digit($prefix)) {
            return false;
        }

        $max = str_contains($ip, ':') ? 128 : 32;
        $prefixLength = (int) $prefix;

        return $prefixLength >= 0 && $prefixLength <= $max;
    }

    private function senderAccessRepresentableNetwork(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        [$ip, $prefix] = explode('/', $value, 2);
        if (str_contains($ip, ':')) {
            return (int) $prefix === 128;
        }

        return in_array((int) $prefix, [8, 16, 24, 32], true);
    }

    private function extractSenders(string $content): array
    {
        $blockPattern = '/'.preg_quote(self::BEGIN_MARKER, '/').'.*?'.preg_quote(self::END_MARKER, '/').'/s';
        if (preg_match($blockPattern, $content, $match)) {
            return $this->extractListValues($match[0]);
        }

        if (preg_match('/^\s*ALLOWED_LOGIN_MISMATCH_SENDERS\s*=\s*\[(.*?)\]\s*$/ms', $content, $match)) {
            return $this->extractListValues($match[1]);
        }

        return [];
    }

    private function extractAllowedForgedSenders(string $content): array
    {
        $blockPattern = '/'.preg_quote(self::UNAUTH_BEGIN_MARKER, '/').'.*?'.preg_quote(self::UNAUTH_END_MARKER, '/').'/s';
        if (preg_match($blockPattern, $content, $match)) {
            if (preg_match('/^\s*ALLOWED_FORGED_SENDERS\s*=\s*\[(.*?)\]\s*$/ms', $match[0], $listMatch)) {
                return $this->extractListValues($listMatch[1]);
            }
        }

        if (preg_match('/^\s*ALLOWED_FORGED_SENDERS\s*=\s*\[(.*?)\]\s*$/ms', $content, $match)) {
            return $this->extractListValues($match[1]);
        }

        return [];
    }

    private function extractMyNetworks(string $content): array
    {
        $blockPattern = '/'.preg_quote(self::UNAUTH_BEGIN_MARKER, '/').'.*?'.preg_quote(self::UNAUTH_END_MARKER, '/').'/s';
        if (preg_match($blockPattern, $content, $match)) {
            if (preg_match('/^\s*MYNETWORKS\s*=\s*\[(.*?)\]\s*$/ms', $match[0], $listMatch)) {
                return $this->normalizeNetworks($this->extractQuotedValues($listMatch[1]));
            }
        }

        if (preg_match('/^\s*MYNETWORKS\s*=\s*\[(.*?)\]\s*$/ms', $content, $match)) {
            return $this->normalizeNetworks($this->extractQuotedValues($match[1]));
        }

        return [];
    }

    private function extractListValues(string $content): array
    {
        return $this->normalizeSenders($this->extractQuotedValues($content));
    }

    private function extractQuotedValues(string $content): array
    {
        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $content, $matches);

        return $matches[1] ?? [];
    }

    private function discardRecipientsPath(): string
    {
        return (string) config('iredmail.postfix_discard_recipients_path');
    }

    private function postfixMainCfPath(): string
    {
        return (string) config('iredmail.postfix_main_cf_path');
    }

    private function postfixSenderAccessPath(): string
    {
        return (string) config('iredmail.postfix_sender_access_path');
    }

    private function postmapCommand(): string
    {
        return trim((string) config('iredmail.postfix_postmap_command'));
    }

    private function postfixReloadCommand(): string
    {
        return trim((string) config('iredmail.postfix_reload_command'));
    }

    private function sogoTemplateSource(): string
    {
        $configured = trim((string) config('iredmail.sogo_root_template_source'));
        if ($configured !== '') {
            return $configured;
        }

        $matches = glob('/usr/lib*/GNUstep/SOGo/Templates/MainUI/SOGoRootPage.wox') ?: [];

        return $matches[0] ?? '/usr/lib/GNUstep/SOGo/Templates/MainUI/SOGoRootPage.wox';
    }

    private function sogoTemplateTarget(): string
    {
        return (string) config('iredmail.sogo_root_template_target');
    }

    private function sogoReloadCommand(): string
    {
        return trim((string) config('iredmail.sogo_reload_command'));
    }

    private function sogoTemplateTargetWritable(): bool
    {
        $target = $this->sogoTemplateTarget();

        return is_file($target) ? is_writable($target) : is_writable(dirname($target));
    }

    private function discardRecipientsReadable(): bool
    {
        $path = $this->discardRecipientsPath();

        return is_file($path) ? is_readable($path) : is_readable(dirname($path));
    }

    private function discardRecipientsWritable(): bool
    {
        $path = $this->discardRecipientsPath();

        return is_file($path) ? is_writable($path) : is_writable(dirname($path));
    }

    private function postfixSenderAccessReadable(): bool
    {
        $path = $this->postfixSenderAccessPath();

        return is_file($path) ? is_readable($path) : is_readable(dirname($path));
    }

    private function postfixSenderAccessWritable(): bool
    {
        $path = $this->postfixSenderAccessPath();

        return is_file($path) ? is_writable($path) : is_writable(dirname($path));
    }

    private function discardRecipients(): array
    {
        $path = $this->discardRecipientsPath();
        if (! is_readable($path)) {
            return [];
        }

        $recipients = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim(preg_replace('/\s+#.*$/', '', $line) ?? '');
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            $email = IredMailAddress::email($parts[0] ?? '');
            $action = strtoupper($parts[1] ?? '');
            if ($email && $action === 'DISCARD') {
                $recipients[] = $email;
            }
        }

        return array_values(array_unique($recipients));
    }

    private function discardRecipientsContent(array $recipients): string
    {
        $lines = [
            '# Managed by iredadmin-php.',
            '# Messages sent to these recipients are accepted and silently discarded by Postfix.',
        ];

        foreach ($recipients as $recipient) {
            $lines[] = $recipient.' DISCARD';
        }

        return implode("\n", $lines)."\n";
    }

    private function postfixRecipientAccessConfigured(): bool
    {
        $path = $this->postfixMainCfPath();
        if (! is_readable($path)) {
            return false;
        }

        $content = preg_replace('/\s+/', ' ', (string) file_get_contents($path));
        $map = 'check_recipient_access hash:'.$this->discardRecipientsPath();

        return str_contains((string) $content, $map);
    }

    private function postfixSenderAccessConfigured(): bool
    {
        $path = $this->postfixMainCfPath();
        if (! is_readable($path)) {
            return false;
        }

        $content = preg_replace('/\s+/', ' ', (string) file_get_contents($path));
        $map = 'check_sender_access pcre:'.$this->postfixSenderAccessPath();

        return str_contains((string) $content, $map);
    }

    private function saveSenderAccessPcre(array $senders, array $networks): array
    {
        $path = $this->postfixSenderAccessPath();

        return $this->withFileLock($path, function () use ($path, $senders, $networks) {
            $directory = dirname($path);
            if (! is_dir($directory)) {
                throw ValidationException::withMessages(['unauthenticated_senders' => "Directory does not exist: {$directory}"]);
            }
            if (! $this->postfixSenderAccessWritable()) {
                throw ValidationException::withMessages(['unauthenticated_senders' => "Cannot write {$path}. Check file ownership or sudo helper permissions."]);
            }

            $original = is_file($path) ? (string) file_get_contents($path) : '';
            $updated = $this->replaceSenderAccessBlock($original, $senders, $networks);

            if ($updated !== $original) {
                if (is_file($path) && @copy($path, $path.'.bak') === false) {
                    throw ValidationException::withMessages(['unauthenticated_senders' => "Cannot create backup {$path}.bak."]);
                }
                if (@file_put_contents($path, $updated, LOCK_EX) === false) {
                    throw ValidationException::withMessages(['unauthenticated_senders' => "Cannot write {$path}."]);
                }
            }

            return [
                'changed' => $updated !== $original,
                'path' => $path,
            ];
        });
    }

    private function ensurePostfixSenderAccessHook(): array
    {
        $path = $this->postfixMainCfPath();
        if (! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages(['unauthenticated_senders' => "Cannot read {$path}."]);
        }

        return $this->withFileLock($path, function () use ($path) {
            $original = (string) file_get_contents($path);
            $updated = $this->addPostfixSenderAccessHook($original);

            if ($updated === $original) {
                return ['changed' => false, 'path' => $path];
            }

            if (! is_writable($path)) {
                throw ValidationException::withMessages(['unauthenticated_senders' => "Cannot write {$path}. Check file ownership or sudo helper permissions."]);
            }

            if (@copy($path, $path.'.bak') === false) {
                throw ValidationException::withMessages(['unauthenticated_senders' => "Cannot create backup {$path}.bak."]);
            }

            if (@file_put_contents($path, $updated, LOCK_EX) === false) {
                throw ValidationException::withMessages(['unauthenticated_senders' => "Cannot write {$path}."]);
            }

            return ['changed' => true, 'path' => $path];
        });
    }

    private function addPostfixSenderAccessHook(string $content): string
    {
        $map = 'check_sender_access pcre:'.$this->postfixSenderAccessPath();
        if (str_contains((string) preg_replace('/\s+/', ' ', $content), $map)) {
            return $content;
        }

        $lines = preg_split('/(\R)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($lines === false) {
            return $content;
        }

        for ($index = 0; $index < count($lines); $index += 2) {
            $line = $lines[$index] ?? '';
            if (! preg_match('/^\s*smtpd_sender_restrictions\s*=/', $line)) {
                continue;
            }

            $end = $index + 2;
            while ($end < count($lines) && preg_match('/^\s+/', $lines[$end] ?? '')) {
                $end += 2;
            }

            $block = implode('', array_slice($lines, $index, $end - $index));
            $restrictions = $this->postfixRestrictionValues($block);
            array_unshift($restrictions, $map);
            $replacement = 'smtpd_sender_restrictions = '.implode(', ', array_values(array_unique($restrictions)))."\n";

            return implode('', array_slice($lines, 0, $index))
                .$replacement
                .implode('', array_slice($lines, $end));
        }

        return rtrim($content)."\n\nsmtpd_sender_restrictions = {$map}\n";
    }

    private function replaceSenderAccessBlock(string $content, array $senders, array $networks): string
    {
        $block = $this->senderAccessBlock($senders, $networks);
        $managedPattern = '/'.preg_quote(self::SENDER_ACCESS_BEGIN_MARKER, '/').'.*?'.preg_quote(self::SENDER_ACCESS_END_MARKER, '/').'\R?/s';
        if (preg_match($managedPattern, $content)) {
            return preg_replace($managedPattern, $block."\n", $content, 1) ?? $content;
        }

        return rtrim($content).($content === '' ? '' : "\n\n").$block."\n";
    }

    private function senderAccessBlock(array $senders, array $networks): string
    {
        $lines = [
            self::SENDER_ACCESS_BEGIN_MARKER,
            '# Allow selected senders or client IPs to submit without SMTP AUTH.',
            '# iRedAPD settings.py must also allow the same values.',
        ];

        foreach ($senders as $sender) {
            $lines[] = '/^'.$this->pcreLiteral($sender).'$/ OK';
        }

        foreach ($networks as $network) {
            $lines[] = '/^'.$this->pcreNetworkPattern($network).($this->pcreNetworkIsPrefix($network) ? '' : '$').'/ OK';
        }

        $lines[] = self::SENDER_ACCESS_END_MARKER;

        return implode("\n", $lines);
    }

    private function pcreLiteral(string $value): string
    {
        return str_replace('/', '\/', preg_quote($value, '/'));
    }

    private function pcreNetworkPattern(string $network): string
    {
        if (! str_contains($network, '/') || str_contains($network, ':')) {
            return $this->pcreLiteral($network);
        }

        [$ip, $prefix] = explode('/', $network, 2);
        $prefixLength = (int) $prefix;

        if ($prefixLength === 32) {
            return $this->pcreLiteral($ip);
        }

        $octets = explode('.', $ip);
        $kept = array_slice($octets, 0, (int) ($prefixLength / 8));

        return implode('\.', array_map(fn (string $octet) => preg_quote($octet, '/'), $kept)).'\.';
    }

    private function pcreNetworkIsPrefix(string $network): bool
    {
        if (! str_contains($network, '/') || str_contains($network, ':')) {
            return false;
        }

        [, $prefix] = explode('/', $network, 2);

        return (int) $prefix < 32;
    }

    private function postfixSenderLoginMismatchPresent(string $content): bool
    {
        return in_array(self::SENDER_MISMATCH_PLUGIN, $this->postfixRestrictionValues($this->postfixSenderRestrictionsValue($content)), true);
    }

    private function postfixSenderRestrictionsValue(string $content): string
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $collecting = false;
        $parts = [];

        foreach ($lines as $line) {
            if (! $collecting && preg_match('/^\s*smtpd_sender_restrictions\s*=(.*)$/', $line, $match)) {
                $collecting = true;
                $parts[] = $match[1];
                continue;
            }

            if ($collecting) {
                if (preg_match('/^\s+(.+)$/', $line, $match)) {
                    $parts[] = $match[1];
                    continue;
                }

                break;
            }
        }

        return implode(' ', $parts);
    }

    private function ensurePostfixSenderLoginMismatchRemoved(): array
    {
        $path = $this->postfixMainCfPath();
        if (! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages(['settings' => "Cannot read {$path}."]);
        }

        return $this->withFileLock($path, function () use ($path) {
            $original = (string) file_get_contents($path);
            $updated = $this->removePostfixSenderLoginMismatchRestriction($original);

            if ($updated === $original) {
                return [
                    'changed' => false,
                    'reload' => ['configured' => true, 'ok' => true, 'message' => 'No Postfix sender restriction change needed.'],
                ];
            }

            if (! is_writable($path)) {
                throw ValidationException::withMessages(['settings' => "Cannot write {$path}. Check file ownership or sudo helper permissions."]);
            }

            if (@copy($path, $path.'.bak') === false) {
                throw ValidationException::withMessages(['settings' => "Cannot create backup {$path}.bak."]);
            }

            if (@file_put_contents($path, $updated, LOCK_EX) === false) {
                throw ValidationException::withMessages(['settings' => "Cannot write {$path}."]);
            }

            return [
                'changed' => true,
                'reload' => $this->reloadPostfix(),
            ];
        });
    }

    private function removePostfixSenderLoginMismatchRestriction(string $content): string
    {
        $lines = preg_split('/(\R)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($lines === false) {
            return $content;
        }

        for ($index = 0; $index < count($lines); $index += 2) {
            $line = $lines[$index] ?? '';
            if (! preg_match('/^\s*smtpd_sender_restrictions\s*=/', $line)) {
                continue;
            }

            $end = $index + 2;
            while ($end < count($lines) && preg_match('/^\s+/', $lines[$end] ?? '')) {
                $end += 2;
            }

            $block = implode('', array_slice($lines, $index, $end - $index));
            $restrictions = $this->postfixRestrictionValues($block);
            if (! in_array(self::SENDER_MISMATCH_PLUGIN, $restrictions, true)) {
                return $content;
            }

            $restrictions = array_values(array_filter(
                $restrictions,
                fn (string $restriction) => $restriction !== self::SENDER_MISMATCH_PLUGIN
            ));

            $replacement = 'smtpd_sender_restrictions = '.implode(', ', $restrictions)."\n";

            return implode('', array_slice($lines, 0, $index))
                .$replacement
                .implode('', array_slice($lines, $end));
        }

        return $content;
    }

    private function postfixRestrictionValues(string $block): array
    {
        $value = preg_replace('/^\s*smtpd_sender_restrictions\s*=/m', '', $block, 1) ?? $block;
        $value = preg_replace('/\s+#.*$/m', '', $value) ?? $value;

        $restrictions = [];
        foreach (explode(',', str_replace(["\r", "\n"], ' ', $value)) as $raw) {
            $restriction = trim($raw);
            if ($restriction !== '') {
                $restrictions[] = $restriction;
            }
        }

        return $restrictions;
    }

    private function runPostmap(string $path): array
    {
        $command = $this->postmapCommand();
        if ($command === '') {
            return ['configured' => false, 'ok' => false, 'message' => 'Postmap command is not configured.'];
        }

        return $this->runConfiguredCommand($command, [$path]);
    }

    private function reloadPostfix(): array
    {
        $command = $this->postfixReloadCommand();
        if ($command === '') {
            return ['configured' => false, 'ok' => false, 'message' => 'Postfix reload command is not configured.'];
        }

        return $this->runConfiguredCommand($command);
    }

    private function sogoLogoUrl(): ?string
    {
        $target = $this->sogoTemplateTarget();
        if (! is_readable($target)) {
            return null;
        }

        return $this->sogoLogoUrlFromContent((string) file_get_contents($target));
    }

    private function sogoLogoUrlFromContent(string $content): ?string
    {
        if (preg_match('/<img\b(?=[^>]*\bclass=(["\'])(?:(?!\1).)*\bmd-margin\b(?:(?!\1).)*\1)[^>]*(?<![:\w-])src=(["\'])(.*?)\2[^>]*>/is', $content, $match)) {
            return html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5);
        }

        if (preg_match('/<img\b[^>]*(?<![:\w-])src=(["\'])(.*?)\1[^>]*>/is', $content, $match)) {
            return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);
        }

        return null;
    }

    private function replaceSogoLogoUrl(string $content, string $url): string
    {
        $escaped = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5);
        $imgPattern = '/<img\b(?=[^>]*\bclass=(["\'])(?:(?!\1).)*\bmd-margin\b(?:(?!\1).)*\1)[^>]*>/is';

        if (preg_match($imgPattern, $content)) {
            return preg_replace_callback($imgPattern, fn (array $match) => $this->replaceImgSrc($match[0], $escaped), $content, 1) ?? $content;
        }

        return preg_replace_callback('/<img\b[^>]*>/is', fn (array $match) => $this->replaceImgSrc($match[0], $escaped), $content, 1) ?? $content;
    }

    private function replaceImgSrc(string $tag, string $escapedUrl): string
    {
        if (preg_match('/(?<![:\w-])src=(["\']).*?\1/is', $tag)) {
            return preg_replace('/(?<![:\w-])src=(["\']).*?\1/is', 'src="'.$escapedUrl.'"', $tag, 1) ?? $tag;
        }

        if (preg_match('/\brsrc:src=(["\']).*?\1/is', $tag)) {
            return preg_replace('/\brsrc:src=(["\']).*?\1/is', 'src="'.$escapedUrl.'"', $tag, 1) ?? $tag;
        }

        return rtrim($tag, '>').' src="'.$escapedUrl.'">';
    }

    private function reloadSogo(): array
    {
        $command = $this->sogoReloadCommand();
        if ($command === '') {
            return ['configured' => false, 'ok' => false, 'message' => 'SOGo reload command is not configured.'];
        }

        return $this->runConfiguredCommand($command);
    }

    private function withFileLock(string $targetPath, Closure $callback): mixed
    {
        $lockDirectory = storage_path('framework/locks');
        if (! is_dir($lockDirectory) && @mkdir($lockDirectory, 0755, true) === false) {
            throw ValidationException::withMessages(['settings' => "Cannot create lock directory {$lockDirectory}."]);
        }

        $lockPath = $lockDirectory.'/system-settings-'.sha1($targetPath).'.lock';
        $handle = @fopen($lockPath, 'c');
        if (! $handle) {
            throw ValidationException::withMessages(['settings' => "Cannot open lock file {$lockPath}."]);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw ValidationException::withMessages(['settings' => "Cannot acquire lock for {$targetPath}."]);
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function replaceManagedBlock(string $content, array $senders): string
    {
        $block = $this->managedBlock($senders);
        $managedPattern = '/'.preg_quote(self::BEGIN_MARKER, '/').'.*?'.preg_quote(self::END_MARKER, '/').'\R?/s';
        if (preg_match($managedPattern, $content)) {
            return preg_replace($managedPattern, $block."\n", $content, 1) ?? $content;
        }

        $assignmentPattern = '/^\s*(?:#\s*Custom addition by iredadmin-php\s*\R)?(?:#\s*Allow forging email address\s*\R)?ALLOWED_LOGIN_MISMATCH_SENDERS\s*=\s*\[.*?\]\s*\R?/ms';
        if (preg_match($assignmentPattern, $content)) {
            return preg_replace($assignmentPattern, $block."\n", $content, 1) ?? $content;
        }

        return $this->insertNearTop($content, $block);
    }

    private function replaceUnauthenticatedSettingsBlock(string $content, array $senders, array $networks): string
    {
        $block = $this->unauthenticatedSettingsBlock($senders, $networks);
        $managedPattern = '/'.preg_quote(self::UNAUTH_BEGIN_MARKER, '/').'.*?'.preg_quote(self::UNAUTH_END_MARKER, '/').'\R?/s';
        if (preg_match($managedPattern, $content)) {
            return preg_replace($managedPattern, $block."\n", $content, 1) ?? $content;
        }

        $assignmentPattern = '/^\s*(ALLOWED_FORGED_SENDERS|MYNETWORKS)\s*=\s*\[.*?\]\s*\R?/ms';
        $content = preg_replace($assignmentPattern, '', $content) ?? $content;

        return $this->insertNearTop($content, $block);
    }

    private function unauthenticatedSettingsBlock(array $senders, array $networks): string
    {
        $senderItems = array_map(fn (string $sender) => "'".$this->pythonSingleQuoted($sender)."'", $senders);
        $networkItems = array_map(fn (string $network) => "'".$this->pythonSingleQuoted($network)."'", $networks);

        return implode("\n", [
            self::UNAUTH_BEGIN_MARKER,
            '# Allow selected hosted senders to submit without SMTP AUTH.',
            'ALLOWED_FORGED_SENDERS = '.($senderItems === [] ? '[]' : '['.implode(', ', $senderItems).']'),
            '# Allow selected client IPs or CIDR networks to submit without SMTP AUTH.',
            'MYNETWORKS = '.($networkItems === [] ? '[]' : '['.implode(', ', $networkItems).']'),
            self::UNAUTH_END_MARKER,
        ]);
    }

    private function senderMismatchPluginEnabled(string $content): bool
    {
        if (! preg_match('/^\s*plugins\s*=\s*\[(.*?)\]/ms', $content, $match)) {
            return false;
        }

        return in_array(self::SENDER_MISMATCH_PLUGIN, $this->extractQuotedValues($match[1]), true);
    }

    private function ensureSenderMismatchPluginEnabled(string $content): string
    {
        if ($this->senderMismatchPluginEnabled($content)) {
            return $content;
        }

        $pattern = '/^([ \t]*plugins[ \t]*=[ \t]*\[)(.*?)(\][^\r\n]*(?:\R|$))/ms';
        if (! preg_match($pattern, $content)) {
            return $this->insertNearTop($content, "plugins = ['".self::SENDER_MISMATCH_PLUGIN."']");
        }

        return preg_replace_callback($pattern, function (array $match): string {
            $body = $match[2];
            $plugin = "'".self::SENDER_MISMATCH_PLUGIN."'";

            if (trim($body) === '') {
                return $match[1].$plugin.$match[3];
            }

            if (str_contains($body, "\n") || str_contains($body, "\r")) {
                $indent = '    ';
                if (preg_match('/\R([ \t]*)[\'"]/', $body, $indentMatch)) {
                    $indent = $indentMatch[1];
                }

                return $match[1].rtrim($body).",\n".$indent.$plugin."\n".$match[3];
            }

            return $match[1].rtrim($body).', '.$plugin.$match[3];
        }, $content, 1) ?? $content;
    }

    private function managedBlock(array $senders): string
    {
        $items = array_map(fn (string $sender) => "'".$this->pythonSingleQuoted($sender)."'", $senders);
        $list = $items === [] ? '[]' : '['.implode(', ', $items).']';

        return implode("\n", [
            self::BEGIN_MARKER,
            '# Custom addition by iredadmin-php',
            '# Allow listed SMTP logins to use a different sender address.',
            'ALLOWED_LOGIN_MISMATCH_SENDERS = '.$list,
            self::END_MARKER,
        ]);
    }

    private function pythonSingleQuoted(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }

    private function insertNearTop(string $content, string $block): string
    {
        $lines = preg_split('/(\R)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $index = 0;
        while ($index < count($lines)) {
            $line = $lines[$index] ?? '';
            $separator = $lines[$index + 1] ?? '';
            if (! preg_match('/^\s*(#.*)?$/', $line)) {
                break;
            }
            $index += $separator === '' ? 1 : 2;
        }

        $prefix = implode('', array_slice($lines, 0, $index));
        $suffix = implode('', array_slice($lines, $index));
        $separator = $prefix === '' || str_ends_with($prefix, "\n") ? '' : "\n";

        return $prefix.$separator.$block."\n\n".$suffix;
    }

    private function restartIredapd(): array
    {
        $command = $this->restartCommand();
        if ($command === '') {
            return ['configured' => false, 'ok' => false, 'message' => 'Restart command is not configured.'];
        }

        return $this->runConfiguredCommand($command);
    }

    private function runConfiguredCommand(string $command, array $extraArguments = []): array
    {
        $arguments = $this->commandArguments($command);
        if ($arguments === []) {
            return ['configured' => true, 'ok' => false, 'message' => 'Configured command is empty.', 'status' => 127];
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
}
