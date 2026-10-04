<?php

namespace App\Support;

use InvalidArgumentException;

final readonly class Vin
{
    private function __construct(public string $value) {}

    public static function from(string $input): self
    {
        $value = strtoupper((string) preg_replace('/\s+/', '', trim($input)));

        if (strlen($value) !== 17 || preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $value) !== 1) {
            throw new InvalidArgumentException('A VIN must be 17 characters and cannot contain I, O or Q.');
        }

        return new self($value);
    }

    public function grouped(): string
    {
        return substr($this->value, 0, 3).' '.substr($this->value, 3, 6).' '.substr($this->value, 9);
    }
}
