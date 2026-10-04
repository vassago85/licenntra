<?php

namespace App\Services\LicenceOcr;

/**
 * Immutable value object returned by every {@see LicenceOcrReader}. Treat the
 * three fields as hints — the licensing-company reviewer always confirms them
 * before anything is written to the fleet vehicle.
 */
class LicenceOcrResult
{
    public function __construct(
        public readonly ?string $expiryDate,
        public readonly ?string $registerNumber,
        public readonly ?string $vin,
        public readonly string $status,
        public readonly ?string $notes = null,
    ) {}

    public static function clean(?string $expiryDate, ?string $registerNumber, ?string $vin): self
    {
        return new self($expiryDate, $registerNumber, $vin, 'clean');
    }

    public static function unreadable(?string $notes = null): self
    {
        return new self(null, null, null, 'unreadable', $notes);
    }

    public static function failed(string $notes): self
    {
        return new self(null, null, null, 'failed', $notes);
    }
}
