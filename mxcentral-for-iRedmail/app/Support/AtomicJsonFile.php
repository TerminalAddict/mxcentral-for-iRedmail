<?php

namespace App\Support;

final class AtomicJsonFile
{
    public static function locked(string $path, \Closure $operation): mixed
    {
        self::ensureDirectory(dirname($path));
        $handle = fopen($path.'.lock', 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open operation lock for {$path}.");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Cannot lock {$path}.");
            }

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function write(string $path, array $state): void
    {
        $directory = dirname($path);
        self::ensureDirectory($directory);
        $temporary = $directory.'/.'.basename($path).'.'.bin2hex(random_bytes(12)).'.tmp';
        $handle = fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException("Cannot create temporary state file for {$path}.");
        }

        try {
            try {
                $content = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                if (fwrite($handle, $content) !== strlen($content) || ! fflush($handle)) {
                    throw new \RuntimeException("Cannot write {$path}.");
                }
                if (function_exists('fsync') && ! fsync($handle)) {
                    throw new \RuntimeException("Cannot sync {$path}.");
                }
                chmod($temporary, 0640);
            } finally {
                fclose($handle);
            }

            if (! rename($temporary, $path)) {
                throw new \RuntimeException("Cannot atomically replace {$path}.");
            }
        } catch (\Throwable $exception) {
            @unlink($temporary);

            throw $exception;
        }
    }

    private static function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory}.");
        }
    }
}
