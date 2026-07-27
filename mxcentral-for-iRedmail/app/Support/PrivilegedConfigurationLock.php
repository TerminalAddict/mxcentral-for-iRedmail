<?php

namespace App\Support;

final class PrivilegedConfigurationLock
{
    public static function run(\Closure $operation): mixed
    {
        $directory = storage_path('framework/locks');
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Cannot create lock directory {$directory}.");
        }
        $path = $directory.'/privileged-configuration.lock';
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open privileged configuration lock {$path}.");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Cannot acquire privileged configuration lock {$path}.");
            }

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
