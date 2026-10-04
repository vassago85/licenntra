<?php

namespace App\Support;

final class Mask
{
    public static function identifier(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $length = strlen($value);

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', $length - 4).substr($value, -4);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public static function sensitive(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $hidden = ['identifier', 'id_number', 'registration_number', 'proxy_id_number', 'password', 'two_factor_secret'];

        foreach ($values as $key => $value) {
            if (in_array($key, $hidden, true) && is_string($value)) {
                $values[$key] = self::identifier($value);
            }
        }

        return $values;
    }
}
