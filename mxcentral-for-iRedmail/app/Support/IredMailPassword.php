<?php

namespace App\Support;

final class IredMailPassword
{
    public static function verify(string $plain, string $stored): bool
    {
        if ($stored === '') {
            return false;
        }

        if (str_starts_with($stored, '{PLAIN}')) {
            return hash_equals(substr($stored, 7), $plain);
        }

        if (str_starts_with($stored, '{SSHA}')) {
            $raw = base64_decode(substr($stored, 6), true);
            if ($raw === false || strlen($raw) <= 20) {
                return false;
            }
            $digest = substr($raw, 0, 20);
            $salt = substr($raw, 20);
            return hash_equals($digest, sha1($plain.$salt, true));
        }

        if (str_starts_with($stored, '{SSHA512}')) {
            $raw = base64_decode(substr($stored, 9), true);
            if ($raw === false || strlen($raw) <= 64) {
                return false;
            }
            $digest = substr($raw, 0, 64);
            $salt = substr($raw, 64);
            return hash_equals($digest, hash('sha512', $plain.$salt, true));
        }

        if (str_starts_with($stored, '{SSHA256}')) {
            $raw = base64_decode(substr($stored, 9), true);
            if ($raw === false || strlen($raw) <= 32) {
                return false;
            }
            $digest = substr($raw, 0, 32);
            $salt = substr($raw, 32);
            return hash_equals($digest, hash('sha256', $plain.$salt, true));
        }

        if (str_starts_with($stored, '{SHA}')) {
            $digest = base64_decode(substr($stored, 5), true);
            return $digest !== false && hash_equals($digest, sha1($plain, true));
        }

        if (str_starts_with($stored, '{CRYPT}')) {
            $hash = substr($stored, 7);
            return hash_equals($hash, crypt($plain, $hash));
        }

        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon')) {
            return password_verify($plain, $stored);
        }

        return hash_equals($stored, crypt($plain, $stored));
    }

    public static function hash(string $plain): string
    {
        $salt = random_bytes(8);
        return '{SSHA}'.base64_encode(sha1($plain.$salt, true).$salt);
    }
}
