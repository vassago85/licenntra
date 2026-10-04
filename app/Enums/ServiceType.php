<?php

namespace App\Enums;

enum ServiceType: string
{
    use LabelsEnum;

    case RegisterOnly = 'register_only';
    case RegisterAndLicense = 'register_and_license';

    public function label(): string
    {
        return match ($this) {
            self::RegisterOnly => 'Register only',
            self::RegisterAndLicense => 'Register & license',
        };
    }

    public function includesLicence(): bool
    {
        return $this === self::RegisterAndLicense;
    }
}
