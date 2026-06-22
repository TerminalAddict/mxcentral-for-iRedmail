<?php

namespace App\Support;

final class IredMailAddress
{
    public static function email(string $value): ?string
    {
        $value = strtolower(trim($value));
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    public static function domain(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > 255 || str_contains($value, '@')) {
            return null;
        }

        return preg_match('/^(?=.{1,255}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value) ? $value : null;
    }

    public static function domainOf(string $email): string
    {
        return substr(strrchr($email, '@') ?: '', 1);
    }

    public static function amavisdDomain(string $domain): string
    {
        return implode('.', array_reverse(explode('.', strtolower($domain))));
    }

    public static function validPolicyAddress(string $value): bool
    {
        $value = strtolower(trim($value));
        if ($value === '@.' || self::email($value) || self::domain(ltrim($value, '@.'))) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false
            || preg_match('/^[0-9a-f:.]+\/\d{1,3}$/i', $value)
            || preg_match('/^[a-z0-9._%+\-*]+@\*$/i', $value);
    }
}
