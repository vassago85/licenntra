<?php

namespace App\Enums;

enum Transmission: string
{
    use LabelsEnum;

    case None = 'none';
    case Manual = 'manual';
    case SemiAutomatic = 'semi_automatic';
    case Automatic = 'automatic';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Manual => 'Manual',
            self::SemiAutomatic => 'Semi-automatic',
            self::Automatic => 'Automatic',
        };
    }
}
