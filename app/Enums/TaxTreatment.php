<?php

namespace App\Enums;

enum TaxTreatment: string
{
    use LabelsEnum;

    case Exempt = 'exempt';
    case ZeroRated = 'zero_rated';
    case Standard = 'standard';

    public function label(): string
    {
        return match ($this) {
            self::Exempt => 'Exempt (no VAT)',
            self::ZeroRated => 'Zero-rated (0% VAT)',
            self::Standard => 'Standard-rated (VAT applies)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Exempt => 'Exempt',
            self::ZeroRated => 'Zero-rated',
            self::Standard => 'Std rated',
        };
    }

    public function attractsVat(): bool
    {
        return $this === self::Standard;
    }
}
