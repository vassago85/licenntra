<?php

namespace App\Enums;

enum RejectionReason: string
{
    use LabelsEnum;

    case Illegible = 'illegible';
    case WrongDocument = 'wrong_document';
    case Expired = 'expired';
    case VinMismatch = 'vin_mismatch';
    case NameMismatch = 'name_mismatch';
    case Incomplete = 'incomplete';
    case Unsigned = 'unsigned';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Illegible => 'Illegible',
            self::WrongDocument => 'Wrong document',
            self::Expired => 'Expired',
            self::VinMismatch => 'VIN mismatch',
            self::NameMismatch => 'Name mismatch',
            self::Incomplete => 'Incomplete',
            self::Unsigned => 'Unsigned',
            self::Other => 'Other',
        };
    }

    public function requiresComment(): bool
    {
        return $this === self::Other;
    }
}
