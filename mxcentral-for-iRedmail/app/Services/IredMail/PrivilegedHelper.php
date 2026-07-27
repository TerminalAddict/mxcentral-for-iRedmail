<?php

namespace App\Services\IredMail;

use Symfony\Component\Process\Process;

class PrivilegedHelper
{
    public function configured(): bool
    {
        return $this->commandArguments() !== [];
    }

    /**
     * @return array{configured: bool, ok: bool, message: string, status: int|null, data: array}
     */
    public function run(string $operation, array $parameters = []): array
    {
        $arguments = $this->commandArguments();
        if ($arguments === []) {
            return [
                'configured' => false,
                'ok' => false,
                'message' => 'The MXCentral privileged helper is not configured.',
                'status' => 127,
                'data' => [],
            ];
        }

        $process = new Process($arguments);
        $process->setInput(json_encode([
            'operation' => $operation,
            'parameters' => $parameters,
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout((float) config('iredmail.privileged_helper_timeout', 60));
        $process->run();

        $output = trim($process->getOutput());
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            $decoded = [];
        }

        return [
            'configured' => true,
            'ok' => $process->isSuccessful() && ($decoded['ok'] ?? false) === true,
            'message' => trim((string) ($decoded['message'] ?? $output)."\n".$process->getErrorOutput()),
            'status' => $process->getExitCode(),
            'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
        ];
    }

    private function commandArguments(): array
    {
        $command = trim((string) config('iredmail.privileged_helper_command'));
        if ($command === '') {
            return [];
        }

        return array_values(array_filter(
            str_getcsv($command, ' ', '"', '\\'),
            fn (string $argument): bool => trim($argument) !== '',
        ));
    }
}
