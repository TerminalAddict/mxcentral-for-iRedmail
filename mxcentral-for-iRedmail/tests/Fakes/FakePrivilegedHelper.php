<?php

namespace Tests\Fakes;

use App\Services\IredMail\PrivilegedHelper;

final class FakePrivilegedHelper extends PrivilegedHelper
{
    public function configured(): bool
    {
        return true;
    }

    public function run(string $operation, array $parameters = []): array
    {
        try {
            $data = match ($operation) {
                'read_file' => ['content' => $this->read((string) $parameters['target'])],
                'apply_configuration' => $this->applyConfiguration($parameters),
                'dkim_status' => $this->dkimStatus((string) $parameters['domain']),
                'dkim_generate' => $this->generateDkim((string) $parameters['domain']),
                'dkim_delete' => $this->deleteDkim((string) $parameters['domain']),
                'dkim_apply' => $this->applyDkim($parameters),
                'amavis_showkeys' => ['output' => ''],
                'amavis_restart' => $this->touchRestartMarker(),
                'iredapd_restart', 'postfix_reload', 'sogo_reload', 'amavis_testkeys', 'postmap', 'fail2ban_unban' => [],
                default => throw new \RuntimeException("Unsupported fake privileged operation: {$operation}"),
            };

            return [
                'configured' => true,
                'ok' => true,
                'message' => 'Fake privileged operation completed.',
                'status' => 0,
                'data' => $data,
            ];
        } catch (\Throwable $exception) {
            return [
                'configured' => true,
                'ok' => false,
                'message' => $exception->getMessage(),
                'status' => 1,
                'data' => [],
            ];
        }
    }

    private function path(string $target): string
    {
        return match ($target) {
            'amavis_config' => (string) config('iredmail.amavisd_config_path'),
            'iredapd_settings' => (string) config('iredmail.iredapd_settings_path'),
            'postfix_main' => (string) config('iredmail.postfix_main_cf_path'),
            'postfix_sender_access' => (string) config('iredmail.postfix_sender_access_path'),
            'postfix_discard_recipients' => (string) config('iredmail.postfix_discard_recipients_path'),
            'postfix_staging_domains' => (string) config('iredmail.postfix_staging_domains_path'),
            'sogo_template' => (string) config('iredmail.sogo_root_template_target'),
            default => throw new \RuntimeException("Unknown fake managed file: {$target}"),
        };
    }

    private function write(string $target, string $content): array
    {
        $path = $this->path($target);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);

        return [];
    }

    private function applyConfiguration(array $parameters): array
    {
        foreach (($parameters['writes'] ?? []) as $target => $content) {
            $this->write((string) $target, (string) $content);
        }

        return [
            'operation_id' => str_repeat('a', 32),
            'status' => 'applied',
            'commands' => [],
        ];
    }

    private function read(string $target): string
    {
        $path = $this->path($target);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function dkimStatus(string $domain): array
    {
        $path = $this->dkimPath($domain);

        return ['exists' => is_file($path), 'path' => $path];
    }

    private function generateDkim(string $domain): array
    {
        $path = $this->dkimPath($domain);
        $rotated = is_file($path);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, 'not-a-real-key');
        chmod($path, 0400);

        return ['rotated' => $rotated, 'path' => $path];
    }

    private function deleteDkim(string $domain): array
    {
        $path = $this->dkimPath($domain);
        $deleted = [];
        foreach (array_merge([$path], glob($path.'.previous-*') ?: []) as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
                $deleted[] = $candidate;
            }
        }

        return ['deleted' => $deleted];
    }

    private function applyDkim(array $parameters): array
    {
        $key = $parameters['action'] === 'generate'
            ? $this->generateDkim((string) $parameters['domain'])
            : $this->deleteDkim((string) $parameters['domain']);
        $this->write('amavis_config', (string) $parameters['amavis_content']);
        $this->touchRestartMarker();

        return [
            'operation_id' => str_repeat('b', 32),
            'status' => 'applied',
            'key' => $key,
        ];
    }

    private function dkimPath(string $domain): string
    {
        return rtrim((string) config('iredmail.amavisd_dkim_directory'), '/').'/'.strtolower($domain).'.pem';
    }

    private function touchRestartMarker(): array
    {
        touch(dirname((string) config('iredmail.amavisd_config_path')).'/restart-called');

        return [];
    }
}
