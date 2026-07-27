<?php

namespace App\Support;

final class CsvCell
{
    public static function safe(mixed $value): string
    {
        $value = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);

        return preg_match('/^[\x00-\x20\x7f\p{Cf}]*[=+\-@]/u', $value) === 1
            ? "'".$value
            : $value;
    }
}
