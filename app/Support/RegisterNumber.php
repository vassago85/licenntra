<?php

namespace App\Support;

use InvalidArgumentException;

final readonly class RegisterNumber
{
    private function __construct(public string $value) {}

    public static function from(string $input): self
    {
        $value = strtoupper((string) preg_replace('/\s+/', '', trim($input)));

        if ($value === '' || preg_match('/^[A-Z0-9]+$/', $value) !== 1) {
            throw new InvalidArgumentException('A vehicle register number must be alphanumeric.');
        }

        return new self($value);
    }
}
